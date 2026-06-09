<?php

namespace App\Services\Request;

use App\Enums\MailboxType;
use App\Enums\Role as RoleEnum;
use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\RequestAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 sticky + round-robin.
 *
 * Порядок выбора менеджера:
 *  1) Sticky — четырёхуровневый поиск менеджера, который уже владеет
 *     каналом коммуникации или работал с тем же товаром / клиентом.
 *     Уровни проверяются по убыванию надёжности сигнала, первый
 *     сработавший побеждает (early-return):
 *
 *     1.0) **direct_mailbox** — письмо пришло в личный почтовый ящик
 *          менеджера (`Mailbox.type=Personal` с owner_user_id). Самый
 *          сильный сигнал — клиент написал лично. Игнорирует
 *          unavailable owner (delegation покроет на время отсутствия).
 *          reason kind=`direct_mailbox`, linked=[].
 *
 *     1a) **catalog_item_id** — у любой позиции новой заявки уже
 *         резолвлен `request_items.catalog_item_id` (через C-step или
 *         OutboundQuoteCatalogEnricher), и тот же catalog_item_id есть в
 *         открытой заявке у менеджера. Самый сильный сигнал — «тот же
 *         товар каталога». reason kind=`catalog`.
 *
 *     1b) **client_email** — у менеджера есть открытая заявка от того же
 *         `client_email` что и новая. Базовая CRM-логика «один клиент —
 *         один менеджер», даже если товары разные. reason kind=`client`.
 *         **Исключение:** «дилерские» email'ы (≥ N открытых заявок в
 *         системе, порог `dealer.auto_threshold`) этот уровень пропускают —
 *         см. DealerEmailService. Дилерский поток распределяется
 *         через round-robin, чтобы не топить одного менеджера.
 *
 *     1c) **parsed_article / parsed_name** — fallback на сырые поля без
 *         каталога (Phase 1 текстовый матч), TRIM по article и
 *         LOWER+TRIM по name. reason kind=`text`.
 *
 *     Sticky всегда побеждает балансировку (per оператор).
 *
 *  2) Round-robin — weighted random с линейной интерполяцией коэффициента
 *     удачи между 1 (самый загруженный) и X (самый отстающий). X —
 *     параметр настройки `assignment.newbie_boost`, который РОП крутит
 *     через UI «Настройки». Смысл — «во сколько раз больше заявок получит
 *     самый отстающий менеджер чем самый загруженный». Рекомендуемый
 *     диапазон 1.5..3.0 (плавный onboarding). См. `pickWeightedLeastLoadedManager`.
 */
class AssignmentService
{
    public function __construct(
        private readonly AttentionService $attention,
        private readonly RequestActivityService $activity,
        private readonly DealerEmailService $dealers,
    ) {
    }

    /**
     * @return User|null  null если в системе нет активных менеджеров.
     */
    public function autoAssign(Request $request, ?int $byUserId = null): ?User
    {
        // Round-robin и sticky работают только по доступным менеджерам:
        //  - archived_at IS NULL (Phase 1.13)
        //  - unavailable_until IS NULL ИЛИ <= now (Foundation Фаза 2)
        // Менеджеры в отпуске/командировке не получают новых заявок.
        //
        // requestHandlerRoles = manager + head_of_sales. РОП ведёт заявки
        // наравне с менеджером — попадает в round-robin и sticky-резолвер.
        $managers = User::role(RoleEnum::requestHandlerRoles())->available()->get();
        if ($managers->isEmpty()) {
            return null;
        }

        $sticky = $this->pickStickyManager($request, $managers);
        if ($sticky) {
            $manager = $sticky['user'];
            // Snapshot тех Request, по которым произошёл match — выводим в
            // карточке заявки (Phase 2 sticky visibility). Формат:
            //   auto_sticky:{"kind":"catalog|client|text","linked":[id1,...]}
            // `kind` показывает по какому сигналу сработал sticky — в UI
            // рендерим разной иконкой / tooltip'ом. Старые записи (165
            // backfill) останутся как plain `auto_sticky` без kind — UI
            // делает graceful fallback.
            $reason = 'auto_sticky:' . json_encode(
                ['kind' => $sticky['kind'], 'linked' => $sticky['linked']],
                JSON_UNESCAPED_UNICODE,
            );
        } else {
            $rr = $this->pickBalancedManager($request, $managers);
            $manager = $rr['user'] ?? null;
            // Сохраняем скорость закрытия / получено сегодня / капасити-вес в
            // reason — РОПу видно, почему именно этому менеджеру (детерминир.).
            // Формат: auto_round_robin:{"closes":{1:140},"today":{1:6},"tw":{1:3.1}}
            $reason = $rr
                ? 'auto_round_robin:' . json_encode(
                    [
                        'closes' => $rr['closes'],
                        'today' => $rr['today'],
                        'tw' => $rr['target_weights'],
                    ],
                    JSON_UNESCAPED_UNICODE,
                )
                : 'auto_round_robin';
        }

        if (! $manager) {
            return null;
        }

        DB::transaction(function () use ($request, $manager, $byUserId, $reason) {
            $request->assigned_user_id = $manager->id;
            $request->status = RequestStatus::Assigned;
            $request->assigned_at = now();
            $request->save();

            RequestAssignment::create([
                'request_id' => $request->id,
                'user_id' => $manager->id,
                'by_user_id' => $byUserId,
                'reason' => $reason,
                'assigned_at' => now(),
            ]);

            // FreshAssignment — info-уровень, поднимает в Pool наверх до
            // первого открытия менеджером (onManagerOpened сбросит).
            $this->attention->onAssigned($request);

            $this->activity->touch($request, \App\Enums\RequestActivityType::Assigned);
        });

        // Foundation Фаза 2: in-app уведомление менеджеру о новой заявке.
        try {
            $manager->notify(\App\Notifications\RequestAssignedNotification::from($request->fresh(), $reason));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'AssignmentService: notification dispatch failed (non-fatal)',
                ['request_id' => $request->id, 'manager_id' => $manager->id, 'error' => $e->getMessage()],
            );
        }

        // Доставка оригинала письма в личный IMAP-ящик менеджера (async).
        // MailDeliverToManagerService сам пропустит если письмо уже у
        // менеджера или нет личного ящика с OAuth. Без \Seen — увидит
        // как новое.
        $email = $request->emailMessage;
        if ($email) {
            \App\Jobs\Mail\DeliverToManagerInboxJob::dispatch($email->id, $manager->id);
        }

        // Phase 6: автоматическое уведомление клиенту «Заявка принята в работу».
        //
        // Шлём только если:
        //  - заявка не наследник (inheritance_parent_id IS NULL — это значит
        //    клиент не отвечал в существующем нашем треде);
        //  - origin EmailMessage не reply на чужое письмо (in_reply_to IS NULL —
        //    значит это первое письмо клиента, а не continuation треда).
        //
        // ClientNotificationService::sendOrderReceived сам проверит:
        //  - is_enabled шаблона (по умолчанию выключен — admin включает явно);
        //  - идемпотентность (повторный autoAssign не задвоит);
        //  - client_email != null.
        if ($email
            && $request->inheritance_parent_id === null
            && empty($email->in_reply_to)
        ) {
            try {
                app(\App\Services\Mail\ClientNotificationService::class)
                    ->sendOrderReceived($request->refresh());
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'AssignmentService: order_received notification failed (non-fatal)',
                    ['request_id' => $request->id, 'error' => $e->getMessage()]
                );
            }
        }

        return $manager;
    }

    /**
     * Sticky-маршрутизация четырёх уровней (см. doc-блок класса).
     *
     * @param  Collection<int, User>  $managers  Активные менеджеры.
     * @return array{user: User, linked: array<int>, kind: 'direct_mailbox'|'catalog'|'client'|'text'}|null
     */
    private function pickStickyManager(Request $request, Collection $managers): ?array
    {
        // Level 0: письмо пришло в личный ящик менеджера — он и owner,
        // независимо от sticky-истории и round-robin. Бизнес-правило:
        // если клиент написал лично менеджеру X, передавать заявку
        // другому через round-robin нельзя.
        $byMailbox = $this->pickStickyByDirectMailbox($request);
        if ($byMailbox) {
            return $byMailbox;
        }

        $managerIds = $managers->pluck('id')->all();
        // Открытые статусы для пула sticky-кандидатов.
        $openStatuses = array_map(
            fn (RequestStatus $s) => $s->value,
            array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isOpenForAssignment()),
        );

        // Level 1: catalog_item_id — самый сильный сигнал.
        $byCatalog = $this->pickStickyByCatalog($request, $managers, $managerIds, $openStatuses);
        if ($byCatalog) {
            return $byCatalog;
        }

        // Level 2: client_email — «один клиент = один менеджер».
        $byClient = $this->pickStickyByClientEmail($request, $managers, $managerIds, $openStatuses);
        if ($byClient) {
            return $byClient;
        }

        // Level 3: parsed_article / parsed_name (текстовый матч).
        return $this->pickStickyByText($request, $managers, $managerIds, $openStatuses);
    }

    /**
     * Level 0: письмо пришло в личный почтовый ящик менеджера.
     *
     * Бизнес-смысл: клиент написал лично менеджеру X. Передавать другому
     * через round-robin/sticky нельзя — это нарушение прямой связи
     * менеджер ⇄ клиент. Owner ящика становится assignee **даже если
     * сейчас unavailable** (отпуск/командировка): заявка персональная,
     * delegation откроет её acting'у автоматически на время отсутствия.
     *
     * Источник истины: `email_messages.mailbox.owner_user_id` при
     * `type=Personal`. Если у ящика нет owner'а (shared / общий ящик) —
     * возвращаем null, дальше идёт обычный sticky/round-robin.
     *
     * Защиты:
     *   - owner archived → fallback к sticky/RR (его аккаунт деактивирован);
     *   - owner не request_handler (manager/head_of_sales) → fallback
     *     (личные ящики директора/секретаря/админа не должны синкаться
     *     согласно `Mailbox::scopeSyncable`, но defensive).
     *
     * @return array{user: User, linked: array<int>, kind: 'direct_mailbox'}|null
     */
    private function pickStickyByDirectMailbox(Request $request): ?array
    {
        $message = $request->emailMessage;
        if (! $message || ! $message->mailbox_id) {
            return null;
        }

        $mailbox = $message->mailbox;
        if (! $mailbox || $mailbox->type !== MailboxType::Personal) {
            return null;
        }
        if (! $mailbox->owner_user_id) {
            return null;
        }

        $owner = User::query()
            ->active()
            ->role(RoleEnum::requestHandlerRoles())
            ->find($mailbox->owner_user_id);
        if (! $owner) {
            return null;
        }

        return ['user' => $owner, 'linked' => [], 'kind' => 'direct_mailbox'];
    }

    /**
     * Level 1: совпадение по `request_items.catalog_item_id`.
     *
     * @param  array<int, int>  $managerIds
     * @param  array<int, string>  $openStatuses
     * @return array{user: User, linked: array<int>, kind: 'catalog'}|null
     */
    private function pickStickyByCatalog(Request $request, Collection $managers, array $managerIds, array $openStatuses): ?array
    {
        $catalogIds = $request->items()
            ->whereNotNull('catalog_item_id')
            ->pluck('catalog_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        if (empty($catalogIds)) {
            return null;
        }

        $row = DB::table('request_items')
            ->join('requests', 'request_items.request_id', '=', 'requests.id')
            ->whereIn('requests.assigned_user_id', $managerIds)
            ->where('requests.id', '!=', $request->id)
            ->whereIn('requests.status', $openStatuses)
            ->whereIn('request_items.catalog_item_id', $catalogIds)
            ->groupBy('requests.assigned_user_id')
            ->selectRaw('requests.assigned_user_id, COUNT(*) AS hits, MAX(requests.created_at) AS latest_created')
            ->orderByDesc('hits')
            ->orderByDesc('latest_created')
            ->first();

        if (! $row) {
            return null;
        }

        $manager = $managers->firstWhere('id', (int) $row->assigned_user_id);
        if (! $manager) {
            return null;
        }

        $linkedIds = DB::table('request_items')
            ->join('requests', 'request_items.request_id', '=', 'requests.id')
            ->where('requests.assigned_user_id', $manager->id)
            ->where('requests.id', '!=', $request->id)
            ->whereIn('requests.status', $openStatuses)
            ->whereIn('request_items.catalog_item_id', $catalogIds)
            ->distinct()
            ->pluck('requests.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return ['user' => $manager, 'linked' => $linkedIds, 'kind' => 'catalog'];
    }

    /**
     * Level 2: совпадение по `client_email`. Открытая заявка от того же
     * клиента — даже с другим товаром — должна остаться у того же менеджера.
     *
     * @param  array<int, int>  $managerIds
     * @param  array<int, string>  $openStatuses
     * @return array{user: User, linked: array<int>, kind: 'client'}|null
     */
    private function pickStickyByClientEmail(Request $request, Collection $managers, array $managerIds, array $openStatuses): ?array
    {
        $clientEmail = mb_strtolower(trim((string) $request->client_email));
        if ($clientEmail === '') {
            return null;
        }

        // Адреса-агрегаторы (веб-форма сайта order@myzip.ru, маркетплейсы): за
        // одним From стоят разные конечные клиенты — client-sticky не применяем,
        // иначе все заявки липнут одному менеджеру. Round-robin распределит;
        // catalog/text sticky (Level 1/3) продолжают работать. Config —
        // services.assignment.non_sticky_client_emails.
        $aggregators = (array) config('services.assignment.non_sticky_client_emails', []);
        if (in_array($clientEmail, $aggregators, true)) {
            return null;
        }

        // Авто-пометка дилерских email'ов: если у этого client_email уже
        // ≥ N открытых заявок (порог из настроек), фиксируем его как
        // дилерский и пропускаем client-sticky. Поток дилера распределяется
        // через round-robin, а не липнет к одному менеджеру.
        // Catalog (1a) и text (1c) sticky продолжают работать.
        $this->dealers->autoMarkIfNeeded($clientEmail);
        if ($this->dealers->isDealer($clientEmail)) {
            return null;
        }

        $row = DB::table('requests')
            ->whereIn('assigned_user_id', $managerIds)
            ->where('id', '!=', $request->id)
            ->whereIn('status', $openStatuses)
            ->whereRaw('LOWER(client_email) = ?', [$clientEmail])
            ->groupBy('assigned_user_id')
            ->selectRaw('assigned_user_id, COUNT(*) AS hits, MAX(created_at) AS latest_created')
            ->orderByDesc('hits')
            ->orderByDesc('latest_created')
            ->first();

        if (! $row) {
            return null;
        }

        $manager = $managers->firstWhere('id', (int) $row->assigned_user_id);
        if (! $manager) {
            return null;
        }

        $linkedIds = DB::table('requests')
            ->where('assigned_user_id', $manager->id)
            ->where('id', '!=', $request->id)
            ->whereIn('status', $openStatuses)
            ->whereRaw('LOWER(client_email) = ?', [$clientEmail])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return ['user' => $manager, 'linked' => $linkedIds, 'kind' => 'client'];
    }

    /**
     * Level 3: fallback по `parsed_article` (TRIM) / `parsed_name`
     * (LOWER+TRIM). Используется когда catalog_item_id ещё не резолвлен
     * и клиент пишет с нового email-адреса.
     *
     * @param  array<int, int>  $managerIds
     * @param  array<int, string>  $openStatuses
     * @return array{user: User, linked: array<int>, kind: 'text'}|null
     */
    private function pickStickyByText(Request $request, Collection $managers, array $managerIds, array $openStatuses): ?array
    {
        $items = $request->items()->get(['parsed_article', 'parsed_name']);
        if ($items->isEmpty()) {
            return null;
        }

        $articles = $items->pluck('parsed_article')
            ->map(fn ($a) => trim((string) $a))
            ->filter(fn ($a) => $a !== '')
            ->unique()
            ->values()
            ->all();

        $names = $items->pluck('parsed_name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->filter(fn ($n) => $n !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($articles) && empty($names)) {
            return null;
        }

        $matchClosure = function ($q) use ($articles, $names) {
            if (! empty($articles)) {
                $q->orWhereIn(DB::raw('TRIM(request_items.parsed_article)'), $articles);
            }
            if (! empty($names)) {
                $q->orWhereIn(DB::raw('LOWER(TRIM(request_items.parsed_name))'), $names);
            }
        };

        $row = DB::table('request_items')
            ->join('requests', 'request_items.request_id', '=', 'requests.id')
            ->whereIn('requests.assigned_user_id', $managerIds)
            ->where('requests.id', '!=', $request->id)
            ->whereIn('requests.status', $openStatuses)
            ->where($matchClosure)
            ->groupBy('requests.assigned_user_id')
            ->selectRaw('requests.assigned_user_id, COUNT(*) AS hits, MAX(requests.created_at) AS latest_created')
            ->orderByDesc('hits')
            ->orderByDesc('latest_created')
            ->first();

        if (! $row) {
            return null;
        }

        $manager = $managers->firstWhere('id', (int) $row->assigned_user_id);
        if (! $manager) {
            return null;
        }

        $linkedIds = DB::table('request_items')
            ->join('requests', 'request_items.request_id', '=', 'requests.id')
            ->where('requests.assigned_user_id', $manager->id)
            ->where('requests.id', '!=', $request->id)
            ->whereIn('requests.status', $openStatuses)
            ->where($matchClosure)
            ->distinct()
            ->pluck('requests.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return ['user' => $manager, 'linked' => $linkedIds, 'kind' => 'text'];
    }

    /**
     * Гладкое распределение — МИКС трёх сигналов (по ТЗ 20/40/40):
     *   weight (0.2) — поровну по load_weight (floor: никто не в нуле);
     *   load   (0.4) — по текущей взвешенной нагрузке (недозагруженным больше);
     *   speed  (0.4) — по скорости закрытия за период (успех+потеря; быстрым
     *                  больше; для 0 закрытий — base_close_rate × quota).
     *
     * Каждый компонент нормируется к доле (сумма=1), затем
     *   targetWeight = 0.2·flatShare + 0.4·loadShare + 0.4·speedShare.
     * Раздача ПРОПОРЦИОНАЛЬНО targetWeight (smooth-WRR): заявку получает
     * менеджер с минимальным (получено_сегодня / targetWeight). Дневной поток
     * размазывается без всплесков; детерминированно (без рулетки). Реализует
     * «20% по весу + 40% по нагрузке + 40% по скорости» гладко — в отличие от
     * argmin-вёдер, где один менеджер мог забрать весь поток.
     *
     * Пороги/доли — config `assignment.distribution` (period_days,
     * base_close_rate, smoothing_k, mix). quota = clamp(load_weight,10..500)/100.
     *
     * @param  Collection<int, User>  $managers  Доступные менеджеры.
     * @return array{user: User, target_weights: array<int,float>, closes: array<int,int>, today: array<int,int>}|null
     */
    private function pickBalancedManager(Request $request, Collection $managers): ?array
    {
        if ($managers->isEmpty()) {
            return null;
        }

        $cfg = (array) config('services.assignment.distribution', []);
        $periodDays = max(1, (int) ($cfg['period_days'] ?? 14));
        $baseClose = max(0.01, (float) ($cfg['base_close_rate'] ?? 10));
        $K = max(0.01, (float) ($cfg['smoothing_k'] ?? 30));
        $mix = (array) ($cfg['mix'] ?? []);
        $mixW = (float) ($mix['weight'] ?? 0.2);  // поровну по весу
        $mixL = (float) ($mix['load'] ?? 0.4);    // по текущей нагрузке
        $mixS = (float) ($mix['speed'] ?? 0.4);   // по скорости закрытия

        $ids = $managers->pluck('id');
        $openStatusValues = array_map(
            fn (RequestStatus $s) => $s->value,
            array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isOpenForAssignment()),
        );

        // Взвешенная по статусу текущая нагрузка (демпфер). CASE из config —
        // КП=0.5, счёт=0.25 (status_load_weights); ключи строго матчим к enum.
        $statusWeights = (array) config('services.assignment.status_load_weights', []);
        $validStatuses = array_flip($openStatusValues);
        $cases = '';
        foreach ($statusWeights as $status => $weight) {
            if (isset($validStatuses[$status])) {
                $cases .= 'WHEN status = ' . DB::getPdo()->quote((string) $status) . ' THEN ' . (float) $weight . ' ';
            }
        }
        $loadExpr = $cases === '' ? 'COUNT(*)' : "SUM(CASE {$cases} ELSE 1 END)";

        $loadByUser = Request::query()
            ->whereIn('assigned_user_id', $ids)
            ->whereIn('status', $openStatusValues)
            ->groupBy('assigned_user_id')
            ->selectRaw("assigned_user_id, {$loadExpr} AS load_count")
            ->pluck('load_count', 'assigned_user_id');

        // Скорость закрытия за период — успех + потеря (closed_at в окне).
        $since = now()->subDays($periodDays);
        $closeByUser = Request::query()
            ->whereIn('assigned_user_id', $ids)
            ->whereIn('status', [RequestStatus::ClosedWon->value, RequestStatus::ClosedLost->value])
            ->where('closed_at', '>=', $since)
            ->groupBy('assigned_user_id')
            ->selectRaw('assigned_user_id, COUNT(*) AS closes')
            ->pluck('closes', 'assigned_user_id');

        // Получено сегодня — счётчик для гладкой пропорциональной раздачи.
        $todayByUser = Request::query()
            ->whereIn('assigned_user_id', $ids)
            ->where('assigned_at', '>=', now()->startOfDay())
            ->groupBy('assigned_user_id')
            ->selectRaw('assigned_user_id, COUNT(*) AS today')
            ->pluck('today', 'assigned_user_id');

        // Overall last assigned — для LRU-tiebreak в начале дня (today=0 у всех).
        $lastByUser = Request::query()
            ->whereIn('assigned_user_id', $ids)
            ->whereNotNull('assigned_at')
            ->groupBy('assigned_user_id')
            ->selectRaw('assigned_user_id, MAX(assigned_at) AS last_assigned_at')
            ->pluck('last_assigned_at', 'assigned_user_id');

        // Три сырых компонента веса на менеджера.
        $rows = $managers->map(function (User $u) use ($loadByUser, $closeByUser, $baseClose, $K) {
            $load = (float) ($loadByUser[$u->id] ?? 0);
            $weight = max(10, min(500, (int) ($u->load_weight ?? 100)));
            $quota = $weight / 100.0;
            $closes = (int) ($closeByUser[$u->id] ?? 0);
            // Базовая скорость для новичков (0 закрытий) — масштаб на quota.
            $effClose = max((float) $closes, $baseClose * $quota);

            return [
                'user' => $u,
                'load' => $load,
                'closes' => $closes,
                'w_flat' => $quota,                 // поровну по весу
                'w_load' => $quota / ($load + $K),  // по текущей нагрузке (меньше нагрузка → больше)
                'w_speed' => $effClose * $quota,    // по скорости закрытия (быстрее → больше)
            ];
        })->values();

        // Нормируем каждый компонент к сумме=1 (доли), затем микс weight/load/speed.
        $sumFlat = max(1e-9, (float) $rows->sum('w_flat'));
        $sumLoad = max(1e-9, (float) $rows->sum('w_load'));
        $sumSpeed = max(1e-9, (float) $rows->sum('w_speed'));

        $candidates = $rows->map(function (array $c) use ($sumFlat, $sumLoad, $sumSpeed, $mixW, $mixL, $mixS, $todayByUser, $lastByUser) {
            $target = $mixW * ($c['w_flat'] / $sumFlat)
                + $mixL * ($c['w_load'] / $sumLoad)
                + $mixS * ($c['w_speed'] / $sumSpeed);
            $today = (int) ($todayByUser[$c['user']->id] ?? 0);

            return [
                'user' => $c['user'],
                'load' => $c['load'],
                'closes' => $c['closes'],
                'target_weight' => $target,
                'today' => $today,
                // Чем меньше fill — тем сильнее менеджеру «недодано» сегодня.
                'fill' => $today / max($target, 1e-9),
                'last_assigned_at' => $lastByUser[$c['user']->id] ?? null,
            ];
        })->values();

        $manager = $this->pickBySmoothShare($candidates);
        if (! $manager) {
            return null;
        }

        return [
            'user' => $manager,
            'target_weights' => $candidates->mapWithKeys(fn ($c) => [$c['user']->id => round($c['target_weight'], 4)])->all(),
            'closes' => $candidates->mapWithKeys(fn ($c) => [$c['user']->id => $c['closes']])->all(),
            'today' => $candidates->mapWithKeys(fn ($c) => [$c['user']->id => $c['today']])->all(),
        ];
    }

    /**
     * Гладкая пропорциональная раздача: min(today / target_weight). При
     * равенстве (начало дня, today=0 у всех) — больший target_weight первым
     * (выше ёмкость → раньше), затем LRU.
     *
     * @param  Collection<int, array<string,mixed>>  $candidates
     */
    private function pickBySmoothShare(Collection $candidates): ?User
    {
        $sorted = $candidates->sort(function ($a, $b) {
            if (abs($a['fill'] - $b['fill']) > 1e-9) {
                return $a['fill'] <=> $b['fill'];
            }
            if (abs($a['target_weight'] - $b['target_weight']) > 1e-9) {
                return $b['target_weight'] <=> $a['target_weight'];
            }

            return $this->lruCompare($a, $b);
        })->values();

        return $sorted->first()['user'] ?? null;
    }

    /**
     * LRU-сравнение: NULL (никогда не назначали) — первым, иначе по дате asc.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function lruCompare(array $a, array $b): int
    {
        if ($a['last_assigned_at'] === null && $b['last_assigned_at'] === null) {
            return 0;
        }
        if ($a['last_assigned_at'] === null) {
            return -1;
        }
        if ($b['last_assigned_at'] === null) {
            return 1;
        }

        return strcmp((string) $a['last_assigned_at'], (string) $b['last_assigned_at']);
    }
}
