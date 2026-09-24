<?php

namespace App\Services\Request;

use App\Enums\MailboxType;
use App\Enums\RequestActivityType;
use App\Enums\RequestStatus;
use App\Enums\Role as RoleEnum;
use App\Jobs\Mail\DeliverToManagerInboxJob;
use App\Models\Request;
use App\Models\RequestAssignment;
use App\Models\User;
use App\Notifications\RequestAssignedNotification;
use App\Services\Mail\ClientNotificationService;
use App\Services\Settings\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 *          сильный сигнал — клиент написал лично. НО если владелец сейчас
 *          недоступен (отпуск/командировка) — уровень пропускается, заявка
 *          распределяется доступным по общим правилам (становится «общей»).
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
 *
 *     Перед балансировкой список кандидатов проходит через
 *     `ManagerComplexityGate`: у менеджера в карточке может стоять потолок
 *     сложности («только лёгкие», «только M-артикулы»). Это ограничение
 *     действует ТОЛЬКО на этой стадии — sticky выше сильнее, своих клиентов
 *     менеджер продолжает вести. Если заявка не подходит никому, фильтр
 *     снимается целиком.
 */
class AssignmentService
{
    /** Ключ настройки «каким правилом раздаём заявки». */
    public const SETTING_MODE = 'assignment.mode';

    /** Умный режим: sticky-привязки плюс микс нагрузки и скорости. */
    public const MODE_SMART = 'smart';

    /**
     * Простой пропорциональный: общий поток (info) раздаётся по очереди, с
     * оглядкой только на процент нагрузки менеджера. Привязки по товару,
     * клиенту и тексту, скорость закрытия и потолок сложности не участвуют —
     * режим включают тогда, когда нужен ровный поток без «умных» поправок.
     *
     * Личная почта — исключение в любом режиме: письмо, пришедшее прямо в
     * личный ящик менеджера, закрепляется за ним. В общую раздачу оно уходит
     * только когда владелец ящика недоступен.
     */
    public const MODE_PROPORTIONAL = 'proportional';

    public static function modeLabel(string $mode): string
    {
        return $mode === self::MODE_PROPORTIONAL
            ? 'пропорциональный'
            : 'sticky и балансировка';
    }

    public function mode(): string
    {
        $mode = (string) app(SettingsService::class)->get(self::SETTING_MODE, self::MODE_SMART);

        return $mode === self::MODE_PROPORTIONAL ? self::MODE_PROPORTIONAL : self::MODE_SMART;
    }

    public function __construct(
        private readonly AttentionService $attention,
        private readonly RequestActivityService $activity,
        private readonly DealerEmailService $dealers,
        private readonly ManagerComplexityGate $complexityGate,
    ) {}

    /**
     * @return User|null null если в системе нет активных менеджеров.
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

        // Пропорциональный режим: пропорционально раздаётся ОБЩИЙ поток —
        // то, что пришло на info. Письмо в личный ящик менеджера остаётся за
        // ним и здесь: клиент написал лично, отвечать должен тот, кому писали.
        // Раздаётся с личного ящика только тогда, когда владелец недоступен —
        // это и проверяет pickStickyByDirectMailbox.
        if ($this->mode() === self::MODE_PROPORTIONAL) {
            $byMailbox = $this->pickStickyByDirectMailbox($request);
            if ($byMailbox) {
                return $this->commit(
                    $request,
                    $byMailbox['user'],
                    'auto_sticky:'.json_encode(
                        ['kind' => $byMailbox['kind'], 'linked' => $byMailbox['linked']],
                        JSON_UNESCAPED_UNICODE,
                    ),
                    $byUserId,
                );
            }

            // Одна и та же заявка от разных покупателей — одному менеджеру:
            // такие приходят от торгующих между собой контор по одному
            // конечному объекту, и разбирать их дважды разным людям — двойная
            // работа и два разных ответа одному и тому же спросу.
            $twin = $this->pickTwinManager($request, $managers);
            if ($twin) {
                return $this->commit(
                    $request,
                    $twin['user'],
                    'auto_twin:'.json_encode(
                        ['fingerprint' => $twin['fingerprint'], 'linked' => $twin['linked']],
                        JSON_UNESCAPED_UNICODE,
                    ),
                    $byUserId,
                );
            }

            $share = $this->pickProportionalManager($managers);
            $manager = $share['user'] ?? null;
            $reason = $share
                ? 'auto_proportional:'.json_encode(
                    ['share' => $share['shares'], 'today' => $share['today']],
                    JSON_UNESCAPED_UNICODE,
                )
                : 'auto_proportional';

            return $this->commit($request, $manager, $reason, $byUserId);
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
            $reason = 'auto_sticky:'.json_encode(
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
                ? 'auto_round_robin:'.json_encode(
                    array_filter([
                        'closes' => $rr['closes'],
                        'today' => $rr['today'],
                        'tw' => $rr['target_weights'],
                        // Кого отсекал потолок сложности (если отсекал) — РОПу
                        // видно, почему заявка не ушла ограниченному менеджеру.
                        'gate' => $rr['gate'] ?? null,
                    ], fn ($v) => $v !== null),
                    JSON_UNESCAPED_UNICODE,
                )
                : 'auto_round_robin';
        }

        return $this->commit($request, $manager, $reason, $byUserId);
    }

    /**
     * Записать назначение и всё, что за ним следует: журнал, «внимание»,
     * уведомление менеджеру, доставку письма в его ящик и письмо клиенту.
     * Шаг общий для всех режимов раздачи — различается только выбор менеджера.
     */
    private function commit(Request $request, ?User $manager, string $reason, ?int $byUserId): ?User
    {
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

            $this->activity->touch($request, RequestActivityType::Assigned);
        });

        // Защитная сетка. По новому правилу заявки на личный ящик недоступного
        // владельца больше НЕ садятся на него (pickStickyByDirectMailbox
        // пропускает unavailable owner → назначение доступному по общим
        // правилам), а round-robin/sticky и так берут только available().
        // Поэтому в штатном autoAssign этот блок не срабатывает. Оставлен на
        // случай, если заявка иным путём окажется на недоступном — тогда
        // делегируем доступному коллеге, чтобы не зависла. Non-fatal.
        if ($manager->isUnavailable()) {
            try {
                app(ManagerUnavailabilityService::class)
                    ->delegateOne($request->fresh(), $manager, $byUserId ? User::find($byUserId) : null);
            } catch (\Throwable $e) {
                Log::warning(
                    'AssignmentService: auto-delegate to acting failed (non-fatal)',
                    ['request_id' => $request->id, 'manager_id' => $manager->id, 'error' => $e->getMessage()],
                );
            }
        }

        // Foundation Фаза 2: in-app уведомление менеджеру о новой заявке.
        try {
            $manager->notify(RequestAssignedNotification::from($request->fresh(), $reason));
        } catch (\Throwable $e) {
            Log::warning(
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
            DeliverToManagerInboxJob::dispatch($email->id, $manager->id);
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
                app(ClientNotificationService::class)
                    ->sendOrderReceived($request->refresh());
            } catch (\Throwable $e) {
                Log::warning(
                    'AssignmentService: order_received notification failed (non-fatal)',
                    ['request_id' => $request->id, 'error' => $e->getMessage()]
                );
            }
        }

        return $manager;
    }

    /**
     * За сколько дней ищем заявку-близнеца по составу. Неделя: дальше этого
     * срока одинаковый состав — уже не «та же заявка, гуляющая по рынку», а
     * просто ходовая деталь, которую спрашивают все.
     */
    public const TWIN_WINDOW_DAYS = 7;

    /**
     * Отпечаток состава заявки: что именно в ней просят, без количеств,
     * порядка и формулировок.
     *
     * Позиция опознаётся по каталожному товару, если он резолвлен, иначе по
     * нормализованному артикулу, иначе по нормализованному названию. Пустые
     * позиции выбрасываем: заявка из одних «нужна консультация» близнецов не
     * имеет. Null — состав не на чем сравнивать.
     */
    public static function compositionFingerprint(Request $request): ?string
    {
        $keys = [];

        foreach ($request->items as $item) {
            if ($item->catalog_item_id) {
                $keys[] = 'c'.(int) $item->catalog_item_id;

                continue;
            }
            $article = ItemTokenizer::normalize($item->parsed_article ?? null);
            if ($article !== '') {
                $keys[] = 'a'.$article;

                continue;
            }
            $name = ItemTokenizer::normalize($item->parsed_name ?? null);
            if ($name !== '') {
                $keys[] = 'n'.$name;
            }
        }

        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return null;
        }
        sort($keys);

        return md5(implode('|', $keys));
    }

    /**
     * Менеджер заявки с тем же составом.
     *
     * Совпадать должен ВЕСЬ состав, а не отдельная позиция: пересечение по
     * одной детали — это обычное дело (поручень просят все), и на нём sticky
     * уже работает в умном режиме. Здесь нас интересует другое — когда два
     * покупателя прислали одну и ту же заявку целиком.
     *
     * Ищем среди открытых заявок за последнюю неделю у доступных менеджеров:
     * по старым ответ уже отправлен, и «одному менеджеру» смысла не имеет.
     *
     * Близнец идёт в общий зачёт: он назначается обычным путём и попадает в
     * счётчик выданных за сегодня, поэтому из общей очереди этот менеджер
     * получит ровно настолько меньше.
     *
     * @param  Collection<int, User>  $managers
     * @return array{user: User, fingerprint: string, linked: array<int, int>}|null
     */
    private function pickTwinManager(Request $request, Collection $managers): ?array
    {
        $fingerprint = self::compositionFingerprint($request);
        if ($fingerprint === null) {
            return null;
        }

        $openStatuses = array_map(
            fn (RequestStatus $s) => $s->value,
            array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isOpenForAssignment()),
        );

        $candidates = Request::query()
            ->with('items:id,request_id,catalog_item_id,parsed_article,parsed_name')
            ->whereIn('assigned_user_id', $managers->pluck('id'))
            ->whereIn('status', $openStatuses)
            ->where('id', '!=', $request->id)
            ->where('created_at', '>=', now()->subDays(self::TWIN_WINDOW_DAYS))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get(['id', 'assigned_user_id', 'created_at']);

        foreach ($candidates as $candidate) {
            if (self::compositionFingerprint($candidate) !== $fingerprint) {
                continue;
            }
            $manager = $managers->firstWhere('id', (int) $candidate->assigned_user_id);
            if ($manager) {
                return [
                    'user' => $manager,
                    'fingerprint' => substr($fingerprint, 0, 8),
                    'linked' => [(int) $candidate->id],
                ];
            }
        }

        return null;
    }

    /**
     * Пропорциональная раздача: по очереди, с оглядкой только на процент
     * нагрузки (`users.load_weight`).
     *
     * Никаких поправок на скорость закрытия, текущую загрузку и потолок
     * сложности: режим включают именно тогда, когда нужен ровный поток и
     * предсказуемость, а не оптимизация. Очередь считается по РАЗДАННЫМ
     * сегодня — заявки, прилетевшие в личные ящики, в счёт не идут: берём
     * того, кому меньше всех «додано» относительно его доли.
     * Недоступные менеджеры в список не попадают — их отсеял вызывающий.
     *
     * @param  Collection<int, User>  $managers
     * @return array{user: User, shares: array<int, float>, today: array<int, int>}|null
     */
    /**
     * Назначения, которые сделал распределитель общего потока.
     *
     * Всё остальное — письмо в личный ящик (`auto_sticky` с kind
     * direct_mailbox), ручная передача РОПом, замещение — к пропорции
     * отношения не имеет и в счётчик доли не попадает.
     *
     * @param  Collection<int, int>  $userIds
     */
    private function distributedAssignments(Collection $userIds): Builder
    {
        return RequestAssignment::query()
            ->whereIn('user_id', $userIds)
            ->where(fn ($w) => $w
                ->where('reason', 'like', 'auto_proportional%')
                ->orWhere('reason', 'like', 'auto_twin%'));
    }

    private function pickProportionalManager(Collection $managers): ?array
    {
        if ($managers->isEmpty()) {
            return null;
        }

        $ids = $managers->pluck('id');

        // Считаем ТОЛЬКО то, что раздали сами: заявка с личного ящика остаётся
        // за владельцем, к пропорции она отношения не имеет. Иначе менеджер, к
        // которому клиенты пишут лично, выглядел бы «уже загруженным» и получал
        // бы меньше общего потока — ровно то, чего пропорциональный режим
        // должен избегать. Учитываем и близнецов: они приходят с общего потока
        // и по правилу садятся на того же менеджера, значит долю занимают.
        $todayByUser = $this->distributedAssignments($ids)
            ->where('assigned_at', '>=', now()->startOfDay())
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) AS today')
            ->pluck('today', 'user_id');

        $lastByUser = $this->distributedAssignments($ids)
            ->whereNotNull('assigned_at')
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(assigned_at) AS last_assigned_at')
            ->pluck('last_assigned_at', 'user_id');

        $weights = $managers->mapWithKeys(fn (User $u) => [
            $u->id => max(1, min(500, (int) ($u->load_weight ?? 100))) / 100.0,
        ]);
        $sum = max(1e-9, (float) $weights->sum());

        $candidates = $managers->map(function (User $u) use ($weights, $sum, $todayByUser, $lastByUser) {
            $share = $weights[$u->id] / $sum;
            $today = (int) ($todayByUser[$u->id] ?? 0);

            return [
                'user' => $u,
                'target_weight' => $share,
                'today' => $today,
                // Чем меньше fill, тем сильнее менеджеру недодано сегодня.
                'fill' => $today / max($share, 1e-9),
                'last_assigned_at' => $lastByUser[$u->id] ?? null,
            ];
        })->values();

        $manager = $this->pickBySmoothShare($candidates);
        if (! $manager) {
            return null;
        }

        return [
            'user' => $manager,
            'shares' => $candidates->mapWithKeys(fn ($c) => [$c['user']->id => round($c['target_weight'], 4)])->all(),
            'today' => $candidates->mapWithKeys(fn ($c) => [$c['user']->id => $c['today']])->all(),
        ];
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
     * менеджер ⇄ клиент. **Исключение — недоступный владелец** (отпуск/
     * командировка): к нему заявку НЕ привязываем (возврат null), она уходит
     * доступным по общим правилам и становится «общей». Раньше садили на
     * отсутствующего владельца + delegation — теперь обычное назначение.
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

        // Владелец личного ящика сейчас НЕДОСТУПЕН (отпуск/командировка) →
        // заявка НЕ липнет к нему. Пропускаем direct_mailbox-уровень: дальше
        // сработает обычный пайплайн (sticky по каталогу/клиенту/тексту среди
        // ДОСТУПНЫХ менеджеров + round-robin), и заявка станет «общей»,
        // назначенной доступному по общим правилам. Планируемая (ещё не
        // начавшаяся) недоступность — isUnavailable()=false → owner ещё в строю,
        // липнем как обычно.
        if ($owner->isUnavailable()) {
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

        // Авто-пометка дилерских email'ов: если у этого client_email пришло
        // ≥ N заявок за окно (порог из настроек), фиксируем его как дилерский
        // и пропускаем client-sticky. Поток дилера распределяется через
        // round-robin, а не липнет к одному менеджеру.
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
     * (LOWER+TRIM) плюс нормализованные токены артикулов (ItemTokenizer).
     * Используется когда catalog_item_id ещё не резолвлен и клиент пишет
     * с нового email-адреса.
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

        // Нормализованные токены: точное сравнение строк ловит только
        // побуквенные совпадения, а одну и ту же позицию клиент и площадка
        // пишут по-разному — «MLKAT-X (VER-1)» против «MLKAT-X VER-1»
        // (кейс M-2026-16404 / M-2026-16406: заявки ушли разным менеджерам,
        // РОП переназначал руками). См. ItemTokenizer.
        $tokens = ItemTokenizer::tokensFor($items);

        if (empty($articles) && empty($names) && empty($tokens)) {
            return null;
        }

        $tokenSince = now()->subDays(max(1, (int) config('services.assignment.text_sticky_window_days', 30)));

        $matchClosure = function ($q) use ($articles, $names, $tokens, $tokenSince) {
            if (! empty($articles)) {
                $q->orWhereIn(DB::raw('TRIM(request_items.parsed_article)'), $articles);
            }
            if (! empty($names)) {
                $q->orWhereIn(DB::raw('LOWER(TRIM(request_items.parsed_name))'), $names);
            }
            if (! empty($tokens)) {
                // Окно — только для токенов: точное совпадение строки и так
                // редкое, а нормализованное срабатывает заметно чаще, и старая
                // открытая заявка притягивала бы к себе новые месяцами.
                $q->orWhere(function ($t) use ($tokens, $tokenSince) {
                    $t->where('requests.created_at', '>=', $tokenSince)
                        ->where(function ($x) use ($tokens) {
                            $x->orWhereIn(DB::raw(ItemTokenizer::sqlNormalize('request_items.parsed_article')), $tokens);
                            // Артикул второй позиции нередко лежит внутри названия
                            // первой («…MLKAT-X (VER-1), шинный модуль CAN1X»).
                            foreach ($tokens as $token) {
                                $x->orWhere(
                                    DB::raw(ItemTokenizer::sqlNormalize('request_items.parsed_name')),
                                    'like',
                                    '%'.$token.'%',
                                );
                            }
                        });
                });
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
     * base_close_rate, smoothing_k, mix). quota = clamp(load_weight,1..500)/100.
     *
     * @param  Collection<int, User>  $managers  Доступные менеджеры.
     * @return array{user: User, target_weights: array<int,float>, closes: array<int,int>, today: array<int,int>}|null
     */
    private function pickBalancedManager(Request $request, Collection $managers): ?array
    {
        if ($managers->isEmpty()) {
            return null;
        }

        // Потолок сложности из карточки менеджера (ManagerComplexityGate):
        // отстающему РОП оставляет только лёгкие заявки / только M-артикулы и
        // поднимает потолок по мере роста. Ограничение действует ТОЛЬКО здесь,
        // на свободном выборе; sticky-уровни выше не трогаем — своих клиентов
        // менеджер ведёт дальше. Если заявку не может взять никто — фильтр
        // снимается, без менеджера она не остаётся.
        $gate = $this->complexityGate->filter($managers, $request);
        $managers = $gate['managers'];
        $gateNote = null;
        if ($gate['excluded'] !== []) {
            $gateNote = ['excluded' => array_values($gate['excluded']), 'relaxed' => $gate['relaxed']];
            Log::info('AssignmentService: complexity gate applied', [
                'request_id' => $request->id,
                'complexity_level' => $request->complexity_level?->value,
                'excluded_user_ids' => $gateNote['excluded'],
                'relaxed' => $gate['relaxed'],
            ]);
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
                $cases .= 'WHEN status = '.DB::getPdo()->quote((string) $status).' THEN '.(float) $weight.' ';
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
            // Нижняя граница 1 (не 10): заказчику нужна возможность почти
            // выключить менеджера из распределения (квота 0.01), сохранив
            // sticky-приоритеты. Ноль не допускаем — деление на quota ниже.
            $weight = max(1, min(500, (int) ($u->load_weight ?? 100)));
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
            'gate' => $gateNote,
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
