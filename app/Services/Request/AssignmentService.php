<?php

namespace App\Services\Request;

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
 *  1) Sticky — трёхуровневый поиск менеджера, который уже работал с тем же
 *     товаром / клиентом. Уровни проверяются по убыванию надёжности сигнала,
 *     первый сработавший побеждает (early-return):
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
 *
 *     1c) **parsed_article / parsed_name** — fallback на сырые поля без
 *         каталога (Phase 1 текстовый матч), TRIM по article и
 *         LOWER+TRIM по name. reason kind=`text`.
 *
 *     Sticky всегда побеждает балансировку (per оператор).
 *
 *  2) Round-robin — weighted random по нагрузке. У отстающего менеджера
 *     (низкий load) выше вероятность получить новую заявку, но не 100% —
 *     остальные продолжают получать заявки пропорционально (max-load -
 *     load + 1). Это плавно подтягивает новичков без рывков 0→all.
 *     См. `pickWeightedLeastLoadedManager`.
 */
class AssignmentService
{
    public function __construct(
        private readonly AttentionService $attention,
        private readonly RequestActivityService $activity,
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
            // Сохраняем веса и нагрузки в reason — РОПу видно почему
            // именно этому менеджеру досталась заявка (вероятностно).
            // Формат: auto_round_robin:{"loads":{1:5,2:8},"weights":{1:4,2:1}}
            $reason = $rr
                ? 'auto_round_robin:' . json_encode(
                    ['loads' => $rr['loads'], 'weights' => $rr['weights']],
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
     * Sticky-маршрутизация трёх уровней (см. doc-блок класса).
     *
     * @param  Collection<int, User>  $managers  Активные менеджеры.
     * @return array{user: User, linked: array<int>, kind: 'catalog'|'client'|'text'}|null
     */
    private function pickStickyManager(Request $request, Collection $managers): ?array
    {
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
     * **Алгоритм**: каждому кандидату назначаем вес
     *   `weight = (max_load - manager_load + 1)`
     * и выбираем случайно с вероятностью пропорциональной весу.
     *
     * Пример нагрузок `[0, 5, 8, 10]` (max=10):
     *   weights = [11, 6, 3, 1]   sum = 21
     *   вероятности: 52% / 29% / 14% / 5%
     *
     * Новичок получает большинство (но не 100%) → плавно догоняет.
     * Перегруженный всё-таки получает редкие заявки → не «забывают».
     *
     * Tiebreak при равных весах: чей `last_assigned_at` старее (LRU),
     * NULL (никогда не получал) — первым в очереди.
     *
     * @param  Collection<int, User>  $managers  Активные менеджеры.
     * @return array{user: User, weights: array<int, int>, loads: array<int, int>}|null
     *         user — выбранный, weights/loads — для audit в reason.
     */
    private function pickWeightedLeastLoadedManager(Collection $managers): ?array
    {
        if ($managers->isEmpty()) {
            return null;
        }

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
        // Веса: (max - load + 1). +1 чтобы у самого загруженного был
        // ненулевой шанс (иначе при разнице в 1-2 заявки эффект почти
        // как у strict least-loaded).
        $weighted = $candidates->map(function (array $c) use ($maxLoad) {
            $c['weight'] = $maxLoad - $c['load'] + 1;

            return $c;
        });

        $totalWeight = (int) $weighted->sum('weight');
        if ($totalWeight <= 0) {
            // Защита от вырожденного случая (все weights = 0 — теоретически
            // невозможно при +1). Берём первого LRU чтобы хоть что-то отдать.
            $fallback = $this->pickByLruTiebreak($candidates);

            return [
                'user' => $fallback,
                'weights' => $weighted->mapWithKeys(fn ($c) => [$c['user']->id => (int) $c['weight']])->all(),
                'loads' => $weighted->mapWithKeys(fn ($c) => [$c['user']->id => (int) $c['load']])->all(),
            ];
        }

        // mt_rand даёт равномерное [1..totalWeight], выбираем bucket по
        // префиксной сумме весов. Стабильный seed не нужен — рандом часть
        // дизайна (плавная балансировка по большой серии заявок).
        $roll = random_int(1, $totalWeight);
        $accum = 0;
        $chosen = null;
        foreach ($weighted as $c) {
            $accum += (int) $c['weight'];
            if ($roll <= $accum) {
                $chosen = $c;
                break;
            }
        }
        // Inhouse-страховка от floating-edge'а.
        $chosen ??= $weighted->last();

        return [
            'user' => $chosen['user'],
            'weights' => $weighted->mapWithKeys(fn ($c) => [$c['user']->id => (int) $c['weight']])->all(),
            'loads' => $weighted->mapWithKeys(fn ($c) => [$c['user']->id => (int) $c['load']])->all(),
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
