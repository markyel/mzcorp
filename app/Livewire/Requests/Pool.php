<?php

namespace App\Livewire\Requests;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\Request;
use Illuminate\Support\Collection;
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

    #[Computed]
    public function canSeeAll(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Secretary->value,
        ]));
    }

    public function render()
    {
        $query = Request::query()
            ->with([
                'assignedUser:id,name',
                'emailMessage' => fn ($q) => $q
                    ->select(['id', 'from_email', 'from_name'])
                    ->withCount('attachments'),
                'items' => fn ($q) => $q->orderBy('position')->limit(3),
                'latestAssignment:id,request_id,reason',
            ])
            ->withCount('items')
            ->orderByDesc('id');

        // Менеджер по умолчанию видит свои; РОП/директор — все.
        $effectiveScope = $this->canSeeAll ? $this->scope : 'mine';
        if ($effectiveScope === 'mine') {
            $query->where('assigned_user_id', auth()->id());
        }

        // Менеджеру не показываем pending — у него нет позиций для работы.
        // РОП/директор/секретарь видят всё (включая pending) для контроля.
        if (! $this->canSeeAll) {
            $query->where('status', '!=', RequestStatus::Pending->value);
        }

        // Фильтр по статусу — только если значение валидно.
        $allowedStatuses = $this->canSeeAll
            ? array_map(fn (RequestStatus $s) => $s->value, RequestStatus::cases())
            : [RequestStatus::New->value, RequestStatus::Assigned->value];
        $validStatus = $this->status !== '' && in_array($this->status, $allowedStatuses, true);
        if ($validStatus) {
            $query->where('status', $this->status);
        }

        if ($this->search !== '') {
            $like = '%' . $this->search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('internal_code', 'ilike', $like)
                    ->orWhere('subject', 'ilike', $like)
                    ->orWhere('client_email', 'ilike', $like)
                    ->orWhere('client_name', 'ilike', $like);
            });
        }

        $page = $query->paginate(25);

        // Группировка текущей страницы по статусу — для sticky group-headers
        // в стиле 03-requests.html. Порядок групп: Assigned → New → Pending.
        /** @var Collection<string, Collection<int, Request>> $grouped */
        $grouped = collect($page->items())
            ->groupBy(fn (Request $r) => $r->status->value);

        $groupOrder = [
            RequestStatus::Assigned->value,
            RequestStatus::New->value,
            RequestStatus::Pending->value,
        ];
        $groups = [];
        foreach ($groupOrder as $statusValue) {
            if (! $grouped->has($statusValue)) {
                continue;
            }
            $rows = $grouped->get($statusValue);
            $groups[] = [
                'status' => RequestStatus::from($statusValue),
                'rows' => $rows,
                'count' => $rows->count(),
            ];
        }

        // Счётчики для filter-chips и left-list-nav.
        $countsBase = Request::query()
            ->when($effectiveScope === 'mine', fn ($q) => $q->where('assigned_user_id', auth()->id()));

        $statusCounts = [
            'new' => (clone $countsBase)->where('status', RequestStatus::New->value)->count(),
            'assigned' => (clone $countsBase)->where('status', RequestStatus::Assigned->value)->count(),
        ];
        if ($this->canSeeAll) {
            $statusCounts['pending'] = (clone $countsBase)
                ->where('status', RequestStatus::Pending->value)
                ->count();
        }

        // Левая навигация: queries «Все открытые», «Нераспределённые», «Мои».
        // Phase 2 saved views (KONE / возраст ≥ 7 / крупные клиенты) — disabled.
        $myAssigned = Request::query()
            ->where('assigned_user_id', auth()->id())
            ->whereIn('status', [RequestStatus::New->value, RequestStatus::Assigned->value])
            ->count();
        $unassigned = $this->canSeeAll
            ? Request::query()->whereNull('assigned_user_id')->count()
            : null;
        $allOpen = $this->canSeeAll
            ? Request::query()
                ->whereIn('status', [RequestStatus::New->value, RequestStatus::Assigned->value])
                ->count()
            : null;

        return view('livewire.requests.pool', [
            'page' => $page,
            'groups' => $groups,
            'effectiveScope' => $effectiveScope,
            'totals' => [
                'mine' => Request::where('assigned_user_id', auth()->id())->count(),
                'all' => $this->canSeeAll ? Request::count() : null,
                'mine_open' => $myAssigned,
                'unassigned' => $unassigned,
                'all_open' => $allOpen,
            ],
            'statusCounts' => $statusCounts,
        ]);
    }
}
