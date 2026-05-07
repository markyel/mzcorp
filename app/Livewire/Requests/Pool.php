<?php

namespace App\Livewire\Requests;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\Request;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Пул заявок (Phase 1.8d).
 *
 * Менеджер видит только свои; РОП/директор/секретарь — все (через `scope=all`).
 * В пределах Phase 1 фильтруем по статусу (`new` / `assigned`) и ищем
 * по коду / теме / e-mail и имени клиента.
 *
 * Колонки таблицы: code · заявка(subject) · клиент · статус · менеджер ·
 * позиций · возраст. Поля sticky/SLA/сумма/сматчено — Phase 2.
 */
class Pool extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'scope')]
    public string $scope = 'mine'; // mine | all

    #[Url(as: 'status')]
    public string $status = ''; // '' = все, 'new', 'assigned'

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
            ->with(['assignedUser:id,name', 'emailMessage:id,from_email,from_name'])
            ->withCount('items')
            ->orderByDesc('id');

        // Менеджер по умолчанию видит свои; РОП/директор — все.
        $effectiveScope = $this->canSeeAll ? $this->scope : 'mine';
        if ($effectiveScope === 'mine') {
            $query->where('assigned_user_id', auth()->id());
        }

        // Фильтр по статусу — только если значение валидно.
        $validStatus = $this->status !== '' && in_array(
            $this->status,
            array_map(fn (RequestStatus $s) => $s->value, RequestStatus::cases()),
            true,
        );
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

        return view('livewire.requests.pool', [
            'requests' => $query->paginate(25),
            'effectiveScope' => $effectiveScope,
            'totals' => [
                'mine' => Request::where('assigned_user_id', auth()->id())->count(),
                'all' => $this->canSeeAll ? Request::count() : null,
            ],
            'statusCounts' => [
                'new' => Request::query()
                    ->when($effectiveScope === 'mine', fn ($q) => $q->where('assigned_user_id', auth()->id()))
                    ->where('status', RequestStatus::New->value)
                    ->count(),
                'assigned' => Request::query()
                    ->when($effectiveScope === 'mine', fn ($q) => $q->where('assigned_user_id', auth()->id()))
                    ->where('status', RequestStatus::Assigned->value)
                    ->count(),
            ],
        ]);
    }
}
