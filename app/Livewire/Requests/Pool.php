<?php

namespace App\Livewire\Requests;

use App\Enums\Role;
use App\Models\Request;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Список заявок.
 *
 * Менеджер видит только свои назначенные. РОП и директор — все.
 * Phase 1.10 минимум: фильтр «моё/все», поиск по коду/теме/клиенту.
 */
class Pool extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'scope')]
    public string $scope = 'mine'; // mine | all

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingScope(): void
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
            ->orderByDesc('id');

        // Менеджер по умолчанию видит свои; РОП/директор — все.
        $effectiveScope = $this->canSeeAll ? $this->scope : 'mine';
        if ($effectiveScope === 'mine') {
            $query->where('assigned_user_id', auth()->id());
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
        ]);
    }
}
