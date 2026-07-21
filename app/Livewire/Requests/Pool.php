<?php

namespace App\Livewire\Requests;

use App\Enums\AttentionReason;
use App\Enums\ClosedLostReason;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Пул заявок (Phase 1.8d-extended → 03-requests.html caркас).
 *
 * Менеджер видит только свои с распарсенными позициями (`new`/`assigned`).
 * Заявки в статусе `pending` (парсер позиций ещё в очереди) скрыты от
 * менеджеров — им не с чем работать. РОП/директор/секретарь видят всё,
 * включая pending — для контроля парсинг-очереди.
 *
 * Колонки таблицы (9): checkbox · код · заявка(title+badges) · статус ·
 * менеджер · клиент · сумма · возраст · ⋯. Поля Phase 2 (sum, SLA,
 * paused-until, refresh-цен) рендерятся как `—` или disabled placeholder'ы.
 */
class Pool extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'scope')]
    public string $scope = 'mine'; // mine | all

    #[Url(as: 'status')]
    public string $status = ''; // '' = все доступные роли, либо конкретный enum-value

    /**
     * Фильтр «только неназначенные» (assigned_user_id IS NULL) — для
     * пункта левой навигации «Нераспределённые». Привязан к URL ?unassigned=1.
     * Совместим с bucket=active и поиском.
     */
    #[Url(as: 'unassigned', except: false)]
    public bool $unassignedOnly = false;

    /**
     * Phase 1.10 — bucket-фильтр (группировка статусов для UI-chip'ов).
     *   active — все open + paused исключены, видны рабочие
     *   paused — на паузе (явно)
     *   closed — closed_won + closed_lost
     *   all    — всё (кроме pending для менеджера)
     * По умолчанию `active` — оператор не хочет видеть закрытые в основном пуле.
     */
    #[Url(as: 'bucket')]
    public string $bucket = 'active';

    /**
     * Фильтр «только делегированные мне» — заявки, временно открытые мне
     * коллегой через активную delegation (acting_user_id = me, ended_at IS NULL),
     * БЕЗ своих. Пункт левой навигации «Делегированные» (виден при наличии).
     */
    #[Url(as: 'delegated', except: false)]
    public bool $delegatedOnly = false;

    /**
     * Фильтр «по конкретному менеджеру» (assigned_user_id = N) — доступен
     * только для ролей с canSeeAll (РОП / директорат / секретарь / админ).
     * Менеджер этим фильтром управлять не может (видит только свои).
     * При активном фильтре scope автоматически считается 'all' внутри
     * render(), чтобы не было конфликта «мои + чужой».
     */
    #[Url(as: 'mgr')]
    public ?int $assignedUserId = null;

    /**
     * Сортировка пула. Значения:
     *   attention     — по attention_level DESC + last_activity_at DESC (default).
     *                   Менеджеру нужно сразу видеть «требует внимания» сверху.
     *   created_desc  — по дате создания заявки DESC (новые сверху).
     *   created_asc   — по дате создания заявки ASC (старые сверху — backlog).
     *
     * Дропдаун выбора доступен ВСЕМ ролям (вкл. менеджера); default — attention.
     * У менеджера scope остаётся «мои», меняется только порядок.
     */
    #[Url(as: 'sort')]
    public string $sort = 'attention';

    /**
     * Фильтр по номеру заявки/КП из 1С (контроль дисциплины):
     * '' — все, 'missing' — без номера, 'present' — с номером.
     */
    #[Url(as: 'onec', except: '')]
    public string $oneCFilter = '';

    /**
     * Окно infinite-scroll: сколько строк показывать. Растёт по loadMore() при
     * долистывании вниз. НЕ в URL (эфемерное состояние). Любая смена фильтра
     * сбрасывает его в 25 через override resetPage() ниже.
     */
    public int $perPage = 25;

    /** Догрузить следующую порцию (вызывается x-intersect sentinel'ом). */
    public function loadMore(): void
    {
        $this->perPage += 25;
    }

    /**
     * Override WithPagination::resetPage — при любой смене фильтра (все
     * updating*-хуки и action'ы зовут resetPage) скроллим список в начало,
     * т.е. сбрасываем окно догрузки. Пагинации больше нет (infinite scroll).
     */
    public function resetPage($pageName = 'page'): void
    {
        $this->perPage = 25;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingScope(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingBucket(): void
    {
        $this->resetPage();
        // При переключении bucket — сбрасываем уточняющий status-фильтр.
        $this->status = '';
    }

    public function updatingAssignedUserId(): void
    {
        $this->resetPage();
    }

    public function updatingDelegatedOnly(): void
    {
        $this->resetPage();
    }

    /**
     * Левая навигация «Делегированные» — показать ИСКЛЮЧИТЕЛЬНО заявки,
     * открытые мне коллегой через активную delegation (без своих).
     */
    public function showDelegated(): void
    {
        $this->delegatedOnly = true;
        $this->scope = 'mine';
        $this->bucket = 'active';
        $this->status = '';
        $this->unassignedOnly = false;
        $this->assignedUserId = null;
        $this->resetPage();
    }

    public function updatingOneCFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSort(): void
    {
        $this->resetPage();
    }

    public function setSort(string $sort): void
    {
        $allowed = ['attention', 'created_desc', 'created_asc'];
        $this->sort = in_array($sort, $allowed, true) ? $sort : 'attention';
        $this->resetPage();
    }

    /**
     * Установить фильтр по менеджеру (или сбросить, передав null/0).
     * При активном фильтре по менеджеру — scope автоматически 'all' и
     * unassignedOnly выключается (конфликт «нераспределённые vs менеджер»).
     */
    public function setManagerFilter(?int $userId): void
    {
        $this->assignedUserId = ($userId !== null && $userId > 0) ? $userId : null;
        if ($this->assignedUserId !== null) {
            $this->scope = 'all';
            $this->unassignedOnly = false;
        }
        $this->delegatedOnly = false;
        $this->resetPage();
    }

    /**
     * Phase 1.10: при заходе на страницу или после URL-навигации
     * проверяем что выбранный $this->status совместим с текущим $bucket.
     * Иначе сбрасываем на «Любой статус» — иначе после перехода
     * Assigned → InProgress заявка пропадала из старого URL-фильтра
     * (?status=assigned).
     */
    /** Query-параметры фильтров пула (для URL-детекта и хранения в сессии). */
    private const FILTER_KEYS = ['q', 'scope', 'status', 'bucket', 'mgr', 'sort', 'onec', 'unassigned', 'delegated'];

    public function mount(): void
    {
        // Восстановление фильтров между заходами: если открыт ГОЛЫЙ
        // /dashboard/requests без фильтров в URL (пункт рейла «Заявки», кнопка
        // «← К списку» с пустым referrer, закладка), поднимаем последний набор
        // фильтров из сессии. Менеджер/директор, зайдя в заявку и вернувшись,
        // попадает в тот же отфильтрованный список (жалоба: фильтры сбрасываются).
        $hasUrlFilters = collect(self::FILTER_KEYS)->contains(fn ($k) => request()->query($k) !== null);
        $restored = $hasUrlFilters ? false : $this->restoreFiltersFromSession();

        // Default scope = 'mine' (свои заявки). Но Director / Secretary НЕ
        // обрабатывают заявки сами (нет ни одной с assigned_user_id=them),
        // поэтому им бессмысленно открывать пустой «Мои». Если scope не задан
        // ни в URL, ни в восстановленной сессии → переключаем на 'all'.
        $user = auth()->user();
        $isViewerOnly = $user?->hasAnyRole([
            Role::Director->value,
            Role::Secretary->value,
            Role::Admin->value,
        ]) && ! $user->hasRole(Role::HeadOfSales->value)
            && ! $user->hasRole(Role::Manager->value);
        if ($isViewerOnly && request()->query('scope') === null && ! $restored) {
            $this->scope = 'all';
        }

        if ($this->status !== '' && ! in_array($this->status, $this->statusesForBucket(), true)) {
            $this->status = '';
        }
    }

    /**
     * Восстановить фильтры из сессии. true — если восстановили.
     */
    private function restoreFiltersFromSession(): bool
    {
        $f = session('pool.filters');
        if (! is_array($f)) {
            return false;
        }
        $this->search = (string) ($f['q'] ?? $this->search);
        $this->scope = (string) ($f['scope'] ?? $this->scope);
        $this->status = (string) ($f['status'] ?? $this->status);
        $this->bucket = (string) ($f['bucket'] ?? $this->bucket);
        $this->assignedUserId = isset($f['mgr']) ? ($f['mgr'] !== null ? (int) $f['mgr'] : null) : $this->assignedUserId;
        $this->sort = (string) ($f['sort'] ?? $this->sort);
        $this->oneCFilter = (string) ($f['onec'] ?? $this->oneCFilter);
        $this->unassignedOnly = (bool) ($f['unassigned'] ?? $this->unassignedOnly);
        $this->delegatedOnly = (bool) ($f['delegated'] ?? $this->delegatedOnly);

        return true;
    }

    /**
     * Запомнить текущий набор фильтров. Зовётся из render() — ловит ЛЮБОЙ
     * способ смены (wire:model, action-кнопки bucket/manager, URL).
     */
    private function persistFilters(): void
    {
        session()->put('pool.filters', [
            'q' => $this->search,
            'scope' => $this->scope,
            'status' => $this->status,
            'bucket' => $this->bucket,
            'mgr' => $this->assignedUserId,
            'sort' => $this->sort,
            'onec' => $this->oneCFilter,
            'unassigned' => $this->unassignedOnly,
            'delegated' => $this->delegatedOnly,
        ]);
    }

    public function setBucket(string $bucket): void
    {
        $allowed = ['active', 'overdue', 'paused', 'closed', 'postsale', 'all'];
        $this->bucket = in_array($bucket, $allowed, true) ? $bucket : 'active';
        $this->status = '';
        $this->delegatedOnly = false;
        $this->resetPage();
    }

    /**
     * Статусы «оформленного заказа» для постпродажного bucket'а и флага
     * PostSale — счёт выставлен / оплачен / успешно закрыт. Именно к ним
     * MailRouter привязывает post_sale письмо (платёжка / отгрузка / документы).
     *
     * @return array<int, string>
     */
    private function postSaleStatuses(): array
    {
        return [
            RequestStatus::AwaitingInvoice->value,
            RequestStatus::Invoiced->value,
            RequestStatus::Paid->value,
            RequestStatus::ClosedWon->value,
        ];
    }

    /**
     * Список enum-значений для текущего bucket.
     *
     * @return array<int, string>
     */
    private function statusesForBucket(): array
    {
        return match ($this->bucket) {
            'paused' => [RequestStatus::Paused->value],
            'closed' => [RequestStatus::ClosedWon->value, RequestStatus::ClosedLost->value],
            // Постпродажа: заказы в статусах «счёт/оплата/успех», на которые
            // пришло постпродажное письмо (платёжка / отгрузка / документы).
            // Доп. фильтр attention_reason=post_sale + attention_required_at
            // IS NOT NULL — в render().
            'postsale' => $this->postSaleStatuses(),
            // «Все» = Активные + Закрытые + На паузе. Pending («в разборе»)
            // исключаем и из списка, чтобы он совпадал со счётчиком корзины.
            'all' => array_map(
                fn (RequestStatus $s) => $s->value,
                array_filter(
                    RequestStatus::cases(),
                    fn (RequestStatus $s) => $s !== RequestStatus::Pending
                        && ($this->canSeeAll || $s->isVisibleToManager()),
                ),
            ),
            // overdue делит пространство статусов с active (только open),
            // дополнительный фильтр attention_level=1 — в render().
            'overdue' => array_map(
                fn (RequestStatus $s) => $s->value,
                array_filter(
                    RequestStatus::cases(),
                    fn (RequestStatus $s) => $s->isOpenForAssignment()
                        && ($this->canSeeAll || $s->isVisibleToManager()),
                ),
            ),
            default => array_map( // active
                fn (RequestStatus $s) => $s->value,
                array_filter(
                    RequestStatus::cases(),
                    fn (RequestStatus $s) => $s->isOpenForAssignment()
                        && ($this->canSeeAll || $s->isVisibleToManager()),
                ),
            ),
        };
    }

    /**
     * Применить query из левой навигации одним движением (scope+status).
     * Через `wire:click="$set(...);$set(...)"` Livewire 4 не парсит
     * compound-выражение — нужен явный action.
     */
    public function applyView(string $scope, string $status, bool $unassigned = false): void
    {
        $this->scope = in_array($scope, ['mine', 'all'], true) ? $scope : 'mine';
        $this->status = $status;
        $this->unassignedOnly = $unassigned;
        $this->delegatedOnly = false;
        // Для «Нераспределённые» — сбрасываем bucket в 'all' (иначе active
        // отрежет paused/closed нераспределённые, что не очевидно UX'ом).
        if ($unassigned) {
            $this->bucket = 'all';
        }
        $this->resetPage();
    }

    #[Computed]
    public function canSeeAll(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Secretary->value,
            Role::Admin->value,
        ]));
    }

    /**
     * Список менеджеров для UI-фильтра. Берём роли manager + head_of_sales
     * (это те, кому реально могут быть назначены заявки — см.
     * Role::requestHandlerRoles). Подгружаем простую коллекцию id+name,
     * сортируем по имени для предсказуемого порядка в dropdown.
     *
     * Возвращается только если canSeeAll (фильтр не имеет смысла для
     * менеджера — он видит только свои).
     *
     * @return Collection<int, array{id:int, name:string}>
     */
    #[Computed]
    public function availableManagers(): Collection
    {
        if (! $this->canSeeAll) {
            return collect();
        }

        return User::query()
            ->active()
            ->role(Role::requestHandlerRoles())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->values();
    }

    /**
     * Имя выбранного менеджера (для chip-подписи). null если фильтр
     * не активен или менеджер не найден.
     */
    #[Computed]
    public function selectedManagerName(): ?string
    {
        if ($this->assignedUserId === null) {
            return null;
        }
        foreach ($this->availableManagers as $m) {
            if ($m['id'] === $this->assignedUserId) {
                return $m['name'];
            }
        }

        return null;
    }

    public function render()
    {
        // Сохраняем текущий набор фильтров на каждый рендер — чтобы возврат к
        // списку (даже на голый URL) поднял их из сессии (см. mount).
        $this->persistFilters();

        $query = Request::query()
            ->with([
                'assignedUser:id,name,avatar_neutral_path,avatar_won_path,avatar_lost_path',
                'emailMessage' => fn ($q) => $q
                    ->select(['id', 'from_email', 'from_name', 'mailbox_id'])
                    ->withCount('attachments')
                    ->with(['mailbox:id,email,type,owner_user_id', 'mailbox.owner:id,name']),
                'items:id,request_id,parsed_name,parsed_brand,position,match_path,is_active',
                // latestAssignment без partial-select — `latestOfMany` делает
                // self-join, и колонки без префикса (request_id) дают
                // SQLSTATE[42702] ambiguous column.
                'latestAssignment',
                // Phase 2 delegation: для UI badge «временно от @{owner}» (acting'у)
                // и «↺ → {acting}» (РОПу/владельцу — кому делегирована).
                'activeDelegations' => fn ($q) => $q->select(['id', 'request_id', 'original_user_id', 'acting_user_id', 'started_at'])
                    ->with(['originalUser:id,name', 'actingUser:id,name']),
            ])
            ->withCount('items');

        // Pool re-sort. Сортировку могут менять ВСЕ роли (UI-дропдаун в
        // pool.blade): attention (default) / created_desc / created_asc
        // (Foundation-feedback 2026-05-22: «по дате создания» для разбора
        // backlog). У менеджера scope всё равно «мои» — порядок на его выбор.
        $effectiveSort = $this->sort;
        match ($effectiveSort) {
            'created_desc' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'created_asc' => $query->orderBy('created_at')->orderBy('id'),
            default => in_array($this->bucket, ['active', 'overdue'], true)
                ? $query->orderByDesc('attention_level')
                    ->orderByRaw('last_activity_at DESC NULLS LAST')
                    ->orderByDesc('id')
                : $query->orderByRaw('last_activity_at DESC NULLS LAST')
                    ->orderByDesc('id'),
        };

        // Менеджер по умолчанию видит свои; РОП/директор — все.
        // Foundation Фаза 2: «свои» теперь включает active delegations
        // (заявки, временно открытые ему коллегой в отпуске).
        $effectiveScope = $this->canSeeAll ? $this->scope : 'mine';

        // Manager-filter (только для canSeeAll): если выбран конкретный
        // менеджер — переопределяем scope на 'all', чтобы не было
        // SQL-конфликта «assigned_user_id = me AND assigned_user_id = X».
        $managerFilterActive = $this->canSeeAll && $this->assignedUserId !== null;
        if ($managerFilterActive) {
            $effectiveScope = 'all';
            $query->where('assigned_user_id', $this->assignedUserId);
        }

        $authId = auth()->id();
        if ($this->delegatedOnly) {
            // «Делегированные» — ИСКЛЮЧИТЕЛЬНО заявки, открытые мне коллегой
            // через активную delegation (без своих). Перекрывает scope.
            $query->whereExists(function ($sub) use ($authId) {
                $sub->select(DB::raw(1))
                    ->from('request_delegations')
                    ->whereColumn('request_delegations.request_id', 'requests.id')
                    ->where('request_delegations.acting_user_id', $authId)
                    ->whereNull('request_delegations.ended_at');
            });
        } elseif ($effectiveScope === 'mine' && ! $managerFilterActive) {
            $query->where(function ($q) use ($authId) {
                $q->where('assigned_user_id', $authId)
                    ->orWhereExists(function ($sub) use ($authId) {
                        $sub->select(DB::raw(1))
                            ->from('request_delegations')
                            ->whereColumn('request_delegations.request_id', 'requests.id')
                            ->where('request_delegations.acting_user_id', $authId)
                            ->whereNull('request_delegations.ended_at');
                    });
            });
        }

        // Менеджеру не показываем pending — у него нет позиций для работы.
        // РОП/директор/секретарь видят всё (включая pending) для контроля.
        if (! $this->canSeeAll) {
            $query->where('status', '!=', RequestStatus::Pending->value);
        }

        // Phase 1.10: bucket-фильтр (группа статусов).
        $bucketStatuses = $this->statusesForBucket();
        $query->whereIn('status', $bucketStatuses);

        // Phase 1.11 + 2026-06-03: bucket=overdue — СТРОГО SLA-просрочка.
        // «Просрочено» = истёкший дедлайн (attention_reason=sla_breach + level=1).
        // Событийные сигналы (fresh_assignment «новая назначенная», client_replied
        // «клиент ответил», postponed_resume) тоже имеют level=1, но это НЕ
        // просрочка — их менеджер видит в active-пуле, в overdue не показываем.
        if ($this->bucket === 'overdue') {
            $query->where('attention_level', 1)
                ->where('attention_reason', AttentionReason::SlaBreach->value);
        }

        // Постпродажа: closed_won заявки с непрочитанным постпродажным письмом.
        // Фильтр по attention_reason (НЕ по attention_level — sweepOverdue
        // сбрасывает level в 0 для silent-статусов, к которым относится
        // closed_won). Снимается при открытии карточки (onManagerOpened).
        if ($this->bucket === 'postsale') {
            $query->where('attention_reason', AttentionReason::PostSale->value)
                ->whereNotNull('attention_required_at');
        }

        // Уточняющий status-фильтр внутри bucket'а — только если значение
        // принадлежит текущему bucket'у (защита от рассинхронизации URL).
        $validStatus = $this->status !== '' && in_array($this->status, $bucketStatuses, true);
        if ($validStatus) {
            $query->where('status', $this->status);
        }

        // Фильтр «нераспределённые» — assigned_user_id IS NULL. Работает
        // независимо от scope/status, для пункта навигации «Нераспределённые».
        //
        // 2026-05-26: отрезаем terminal-статусы (closed_*). Для автозакрытых
        // заявок (parser_no_content) есть отдельный пул /dashboard/requests/
        // auto-closed — там их можно восстановить. В «Нераспределённых» им
        // делать нечего: это не открытые заявки, а уже закрытые системой,
        // их не надо никому распределять.
        if ($this->unassignedOnly) {
            $query->whereNull('assigned_user_id')
                ->whereIn('status', array_map(
                    fn (RequestStatus $s) => $s->value,
                    array_filter(RequestStatus::cases(), fn (RequestStatus $s) => ! $s->isTerminal()),
                ));
        }

        // Контроль номера 1С: пометка в строках + фильтр по наличию.
        if ($this->oneCFilter === 'missing') {
            $query->whereNull('onec_number');
        } elseif ($this->oneCFilter === 'present') {
            $query->whereNotNull('onec_number');
        }

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            // Номер 1С хранится без ведущих нулей — ищем и по введённой форме,
            // и по форме без нулей («000327068» находит «327068»), и по чистой
            // цифровой части («КП 000327068» из буфера 1С тоже находит).
            // Числовые fallback'и (без нулей / чистые цифры) — ТОЛЬКО для поиска
            // по номеру 1С. Если пользователь ввёл наш внутренний код заявки
            // (M-2026-9129 / М-2026-9129), их не применяем: иначе из кода
            // извлекается ГОД «2026» и onec_number ilike '%2026%' ловит почти
            // все заявки (кейс: «M-2026-9129» находил M-2026-8165 по году).
            $isInternalCode = preg_match('/[МM]-?\d{4}-\d+/iu', trim($this->search)) === 1;
            $searchNoZeros = trim((string) preg_replace('/(?<!\d)0+(?=\d)/', '', $this->search));
            $likeNoZeros = ! $isInternalCode && $searchNoZeros !== '' && $searchNoZeros !== $this->search
                ? '%'.$searchNoZeros.'%'
                : null;
            $digits = preg_match('/(\d{4,})/', $searchNoZeros, $dm) === 1 ? $dm[1] : null;
            $likeDigits = ! $isInternalCode && $digits !== null && $digits !== $searchNoZeros && $digits !== $this->search
                ? '%'.$digits.'%'
                : null;
            $query->where(function ($q) use ($like, $likeNoZeros, $likeDigits) {
                // Базовые поля заявки.
                $q->where('internal_code', 'ilike', $like)
                    ->orWhere('subject', 'ilike', $like)
                    ->orWhere('client_email', 'ilike', $like)
                    ->orWhere('client_name', 'ilike', $like)
                    // Номер заявки/КП из 1С.
                    ->orWhere('onec_number', 'ilike', $like)
                    ->when($likeNoZeros !== null, fn ($w) => $w->orWhere('onec_number', 'ilike', $likeNoZeros))
                    ->when($likeDigits !== null, fn ($w) => $w->orWhere('onec_number', 'ilike', $likeDigits))
                    // Позиции заявки — артикул / название (parsed_*).
                    // EXISTS-subquery вместо JOIN, чтобы не дублировать
                    // строки и не ломать пагинацию.
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))
                            ->from('request_items')
                            ->whereColumn('request_items.request_id', 'requests.id')
                            ->where('request_items.is_active', true)
                            ->where(function ($w) use ($like) {
                                $w->where('request_items.parsed_article', 'ilike', $like)
                                    ->orWhere('request_items.parsed_name', 'ilike', $like);
                            });
                    })
                    // M-SKU каталога через linked catalog_item_id.
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))
                            ->from('request_items')
                            ->join('catalog_items', 'catalog_items.id', '=', 'request_items.catalog_item_id')
                            ->whereColumn('request_items.request_id', 'requests.id')
                            ->where('request_items.is_active', true)
                            ->where('catalog_items.sku', 'ilike', $like);
                    })
                    // КП-коды (наши Quotation, КП-2026-NNNN).
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))
                            ->from('quotations')
                            ->whereColumn('quotations.request_id', 'requests.id')
                            ->where('quotations.internal_code', 'ilike', $like);
                    })
                    // Номер выставленного счёта (Invoice, «6847»).
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))
                            ->from('invoices')
                            ->whereColumn('invoices.request_id', 'requests.id')
                            ->where('invoices.invoice_number', 'ilike', $like);
                    })
                    // Номер отправленного КП/счёта из исходящих писем
                    // (outbound_quotes.document_number — реальные КП идут
                    // через детектор, не через внутренний редактор Quotation).
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))
                            ->from('outbound_quotes')
                            ->whereColumn('outbound_quotes.request_id', 'requests.id')
                            ->where('outbound_quotes.document_number', 'ilike', $like);
                    });
            });
        }

        // Плоский список с догрузкой по скроллу (infinite scroll). Группировку
        // по статусу убрали (путала оператора) — реальный статус виден чипом в
        // каждой строке. Тянем perPage строк в текущем порядке сортировки;
        // $total — для футера/empty-state, $hasMore — нужен ли sentinel догрузки.
        $total = (clone $query)->count();
        $rows = $query->take($this->perPage)->get();
        $hasMore = $rows->count() < $total;

        // Счётчики для filter-chips и left-list-nav.
        $countsBase = Request::query()
            ->when($this->delegatedOnly, fn ($q) => $q->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('request_delegations')
                    ->whereColumn('request_delegations.request_id', 'requests.id')
                    ->where('request_delegations.acting_user_id', auth()->id())
                    ->whereNull('request_delegations.ended_at');
            }))
            ->when(! $this->delegatedOnly && $effectiveScope === 'mine', fn ($q) => $q->where('assigned_user_id', auth()->id()));

        // Bucket-counts: для верхней chip-row (активные / на паузе / закрытые / все).
        $openValues = array_map(
            fn (RequestStatus $s) => $s->value,
            array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isOpenForAssignment()),
        );
        $bucketCounts = [
            'active' => (clone $countsBase)
                ->whereIn('status', array_filter(
                    $openValues,
                    fn ($v) => $this->canSeeAll || $v !== RequestStatus::Pending->value,
                ))
                ->count(),
            'overdue' => (clone $countsBase)
                ->where('attention_level', 1)
                ->where('attention_reason', AttentionReason::SlaBreach->value)
                ->whereIn('status', array_filter(
                    $openValues,
                    fn ($v) => $this->canSeeAll || $v !== RequestStatus::Pending->value,
                ))
                ->count(),
            'paused' => (clone $countsBase)
                ->where('status', RequestStatus::Paused->value)
                ->count(),
            'closed' => (clone $countsBase)
                ->whereIn('status', [
                    RequestStatus::ClosedWon->value,
                    RequestStatus::ClosedLost->value,
                ])
                ->count(),
            // Постпродажа: заказы со счётом/оплатой/успехом и непрочитанным
            // постпродажным письмом.
            'postsale' => (clone $countsBase)
                ->whereIn('status', $this->postSaleStatuses())
                ->where('attention_reason', AttentionReason::PostSale->value)
                ->whereNotNull('attention_required_at')
                ->count(),
            // «Все» = Активные + Закрытые + На паузе. Pending («в разборе» —
            // парсер ещё не извлёк позиции) исключаем всегда: он не входит ни в
            // одну из верхних корзин, иначе сумма не сходится (Все − активные −
            // закрытые − пауза = pending).
            'all' => (clone $countsBase)
                ->where('status', '!=', RequestStatus::Pending->value)
                ->count(),
        ];

        // Per-status counts внутри активного bucket'а — для уточняющих chip'ов.
        $statusCounts = [];
        foreach ($bucketStatuses as $sv) {
            $statusCounts[$sv] = (clone $countsBase)->where('status', $sv)->count();
        }

        // Левая навигация: queries «Все открытые», «Нераспределённые», «Мои».
        // Phase 2 saved views (KONE / возраст ≥ 7 / крупные клиенты) — disabled.
        $authId = auth()->id();
        $myAssigned = Request::query()
            ->where(function ($q) use ($authId) {
                $q->where('assigned_user_id', $authId)
                    ->orWhereExists(function ($sub) use ($authId) {
                        $sub->select(DB::raw(1))
                            ->from('request_delegations')
                            ->whereColumn('request_delegations.request_id', 'requests.id')
                            ->where('request_delegations.acting_user_id', $authId)
                            ->whereNull('request_delegations.ended_at');
                    });
            })
            ->whereIn('status', array_map(
                fn (RequestStatus $s) => $s->value,
                array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isOpenForAssignment()),
            ))
            ->count();
        // Постпродажа (мои): заказы текущего менеджера (вкл. активные
        // делегации) в статусах «счёт/оплата/успех», на которые пришло
        // постпродажное письмо и менеджер ещё не открыл карточку. Подсветка
        // в левой навигации.
        $myPostSale = Request::query()
            ->where(function ($q) use ($authId) {
                $q->where('assigned_user_id', $authId)
                    ->orWhereExists(function ($sub) use ($authId) {
                        $sub->select(DB::raw(1))
                            ->from('request_delegations')
                            ->whereColumn('request_delegations.request_id', 'requests.id')
                            ->where('request_delegations.acting_user_id', $authId)
                            ->whereNull('request_delegations.ended_at');
                    });
            })
            ->whereIn('status', $this->postSaleStatuses())
            ->where('attention_reason', AttentionReason::PostSale->value)
            ->whereNotNull('attention_required_at')
            ->count();

        // «Делегированные» — открытые заявки, временно открытые мне коллегой
        // через активную delegation (без своих). Пункт левой навигации виден
        // только когда счётчик > 0.
        $delegatedMine = Request::query()
            ->whereExists(function ($sub) use ($authId) {
                $sub->select(DB::raw(1))
                    ->from('request_delegations')
                    ->whereColumn('request_delegations.request_id', 'requests.id')
                    ->where('request_delegations.acting_user_id', $authId)
                    ->whereNull('request_delegations.ended_at');
            })
            ->whereIn('status', array_map(
                fn (RequestStatus $s) => $s->value,
                array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isOpenForAssignment()),
            ))
            ->count();

        // Counter «Нераспределённые» — только реально открытые (не closed_*).
        // Автозакрытые системой заявки имеют отдельный пул и не должны
        // подсвечивать этот счётчик.
        $unassigned = $this->canSeeAll
            ? Request::query()
                ->whereNull('assigned_user_id')
                ->whereIn('status', array_map(
                    fn (RequestStatus $s) => $s->value,
                    array_filter(RequestStatus::cases(), fn (RequestStatus $s) => ! $s->isTerminal()),
                ))
                ->count()
            : null;
        $allOpen = $this->canSeeAll
            ? Request::query()
                ->whereIn('status', array_map(
                    fn (RequestStatus $s) => $s->value,
                    array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isOpenForAssignment()),
                ))
                ->count()
            : null;

        // Автозакрытые системой заявки (parser_no_content + LLM verdict=close)
        // за последние 30 дней. Видят head_of_sales / director / admin /
        // secretary — ссылка ведёт на /dashboard/requests/auto-closed
        // где можно восстановить и запустить в работу.
        $autoClosed = (auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'admin', 'secretary']))
            ? Request::query()
                ->whereNull('assigned_user_id')
                ->where('status', RequestStatus::ClosedLost->value)
                ->where('closed_lost_reason', ClosedLostReason::ParserNoContent->value)
                ->where('closed_at', '>=', now()->subDays(30))
                ->count()
            : null;

        // Phase: per-manager счётчик открытых заявок для секции «Команда»
        // в Aside. Только для canSeeAll. Один GROUP BY вместо N запросов.
        $managerOpenCounts = [];
        if ($this->canSeeAll) {
            // NB: отдельное имя ($mgrRows) — не затирать список $rows, который
            // уходит во view (иначе route('requests.show', $req) падает на
            // агрегатных строках без id). См. инцидент 2026-06-30.
            $mgrRows = Request::query()
                ->selectRaw('assigned_user_id, COUNT(*) AS cnt')
                ->whereNotNull('assigned_user_id')
                ->whereIn('status', array_map(
                    fn (RequestStatus $s) => $s->value,
                    array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isOpenForAssignment()),
                ))
                ->groupBy('assigned_user_id')
                ->get();
            foreach ($mgrRows as $r) {
                $managerOpenCounts[(int) $r->assigned_user_id] = (int) $r->cnt;
            }
        }

        return view('livewire.requests.pool', [
            'rows' => $rows,
            'total' => $total,
            'hasMore' => $hasMore,
            'effectiveScope' => $effectiveScope,
            'totals' => [
                // «Мои / Команда» в фильтр-баре показывают только открытые
                // (isOpenForAssignment), чтобы счётчики не «висели» после
                // массового закрытия заявок (например bulk-close-historic).
                'mine' => $myAssigned,
                'all' => $allOpen,
                'mine_open' => $myAssigned,
                'unassigned' => $unassigned,
                'all_open' => $allOpen,
                'auto_closed' => $autoClosed,
                'postsale_mine' => $myPostSale,
                'delegated_mine' => $delegatedMine,
            ],
            'statusCounts' => $statusCounts,
            'bucketCounts' => $bucketCounts,
            'bucketStatuses' => $bucketStatuses,
            'managerOpenCounts' => $managerOpenCounts,
        ]);
    }
}
