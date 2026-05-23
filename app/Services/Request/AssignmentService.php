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
            $rr = $this->pickWeightedLeastLoadedManager($managers);
            $manager = $rr['user'] ?? null;
            // Сохраняем веса/нагрузки/boost в reason — РОПу видно почему
            // именно этому менеджеру досталась заявка (вероятностно).
            // Формат: auto_round_robin:{"boost":2,"loads":{1:5,2:8},"weights":{1:1.5,2:1.0}}
            $reason = $rr
                ? 'auto_round_robin:' . json_encode(
                    [
                        'boost' => $rr['boost'],
                        'loads' => $rr['loads'],
                        'weights' => $rr['weights'],
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
     * Round-robin с приоритетом для отстающих по нагрузке (weighted random).
     *
     * **Зачем не «strict least-loaded»**: если ввести нового менеджера с
     * нагрузкой 0, классический «least-loaded first» отдаст ему ВСЕ новые
     * заявки подряд, пока он не догонит остальных. Реально это плохо —
     * нагрузка прыгает рывками, клиенты одного менеджера получают разные
     * стили общения, нет плавного onboarding.
     *
     * **Алгоритм**: «коэффициент удачи» линейно интерполируется между 1
     * (для самого загруженного) и `X` (для самого отстающего). `X` —
     * параметр из настроек `assignment.newbie_boost`, который РОП может
     * крутить через UI «Настройки». Семантика X — «скорость догона»:
     *   - X=1   — плоская раздача (равные коэффициенты для всех)
     *   - X=2   — новичок получает ×2 от самого загруженного
     *   - X=5   — ×5 (агрессивный догон, новичок почти всю серию забирает)
     *
     * Формула:
     *   coef = 1 + (X − 1) × (max_load − load) / max(max_load − min_load, 1)
     *
     * Пример (X=2, нагрузки [100, 100, 100, 100, 50, 0]):
     *   max=100, min=0
     *   coef для load=100: 1 + 1×(0/100) = 1.0
     *   coef для load=50:  1 + 1×(50/100) = 1.5
     *   coef для load=0:   1 + 1×(100/100) = 2.0
     *
     * Чтобы это превратить в int-веса для random_int, домножаем на 1000.
     *
     * Tiebreak при равных весах (все load одинаковые) — LRU (старее
     * last_assigned_at первым).
     *
     * @param  Collection<int, User>  $managers  Активные менеджеры.
     * @return array{user: User, weights: array<int, float>, loads: array<int, int>, boost: float}|null
     */
    private function pickWeightedLeastLoadedManager(Collection $managers): ?array
    {
        if ($managers->isEmpty()) {
            return null;
        }

        // Скорость догона. Не меньше 1 — иначе формула даст обратный эффект
        // (отстающие получают меньше). Дробные значения (1.5, 2.5) допустимы.
        $boost = max(1.0, (float) app_setting(
            'assignment.newbie_boost',
            config('services.assignment.newbie_boost', 2.0),
        ));

        // Phase 1.10: load = все open-статусы (не-terminal, не-paused).
        $openStatusValues = array_map(
            fn (RequestStatus $s) => $s->value,
            array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isOpenForAssignment()),
        );
        $loadByUser = Request::query()
            ->whereIn('assigned_user_id', $managers->pluck('id'))
            ->whereIn('status', $openStatusValues)
            ->groupBy('assigned_user_id')
            ->selectRaw('assigned_user_id, COUNT(*) AS load_count, MAX(assigned_at) AS last_assigned_at')
            ->get()
            ->keyBy('assigned_user_id');

        $candidates = $managers->map(function (User $u) use ($loadByUser) {
            $row = $loadByUser->get($u->id);

            return [
                'user' => $u,
                'load' => (int) ($row->load_count ?? 0),
                'last_assigned_at' => $row->last_assigned_at ?? null,
            ];
        })->values();

        $maxLoad = (int) $candidates->max('load');
        $minLoad = (int) $candidates->min('load');
        $spread = max($maxLoad - $minLoad, 1); // защита от деления на 0
        // Коэффициент: 1.0 для самого загруженного, X для самого отстающего,
        // линейно для промежуточных. Если все нагрузки одинаковые — spread=1
        // и (max-load)=0 → coef=1 для всех (равная раздача, tiebreak LRU).
        $weighted = $candidates->map(function (array $c) use ($maxLoad, $spread, $boost) {
            $c['coef'] = 1.0 + ($boost - 1.0) * ($maxLoad - $c['load']) / $spread;

            return $c;
        });

        // Если все coef равны 1.0 (загрузка одинаковая) — выбираем по LRU,
        // чтобы не было «случайностей» при равенстве. Случай новых
        // менеджеров с 0 нагрузок попадёт сюда тоже.
        $allEqual = $weighted->every(fn ($c) => abs($c['coef'] - 1.0) < 0.0001);
        if ($allEqual) {
            $manager = $this->pickByLruTiebreak($candidates);

            return $manager ? [
                'user' => $manager,
                'weights' => $weighted->mapWithKeys(fn ($c) => [$c['user']->id => round($c['coef'], 3)])->all(),
                'loads' => $weighted->mapWithKeys(fn ($c) => [$c['user']->id => (int) $c['load']])->all(),
                'boost' => $boost,
            ] : null;
        }

        // random_int не работает с float — масштабируем coef × 1000 в int.
        $intWeights = $weighted->map(fn ($c) => max(1, (int) round($c['coef'] * 1000)));
        $totalWeight = (int) $intWeights->sum();

        $roll = random_int(1, $totalWeight);
        $accum = 0;
        $chosen = null;
        foreach ($weighted as $idx => $c) {
            $accum += $intWeights[$idx];
            if ($roll <= $accum) {
                $chosen = $c;
                break;
            }
        }
        $chosen ??= $weighted->last();

        return [
            'user' => $chosen['user'],
            'weights' => $weighted->mapWithKeys(fn ($c) => [$c['user']->id => round($c['coef'], 3)])->all(),
            'loads' => $weighted->mapWithKeys(fn ($c) => [$c['user']->id => (int) $c['load']])->all(),
            'boost' => $boost,
        ];
    }

    /**
     * Tiebreak helper: при равных весах берём того, кому давнее назначали
     * (NULL — никогда не получал — первым в очереди).
     *
     * @param  Collection<int, array{user: User, load: int, last_assigned_at: ?string}>  $candidates
     */
    private function pickByLruTiebreak(Collection $candidates): ?User
    {
        $sorted = $candidates->sort(function ($a, $b) {
            if ($a['load'] !== $b['load']) {
                return $a['load'] <=> $b['load'];
            }
            if ($a['last_assigned_at'] === null) {
                return -1;
            }
            if ($b['last_assigned_at'] === null) {
                return 1;
            }

            return strcmp($a['last_assigned_at'], $b['last_assigned_at']);
        })->values();

        return $sorted->first()['user'] ?? null;
    }
}
