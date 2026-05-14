<?php

namespace App\Livewire\Admin\Managers;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Services\Request\ManagerUnavailabilityService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Список пользователей CRM (Phase 1.13).
 *
 * Менеджеры по умолчанию; через фильтр-чипы можно посмотреть РОПов /
 * директоров / архивных. Архивация / восстановление — wire:click + confirm.
 *
 * Доступ через middleware role:head_of_sales,director (см. routes/web.php).
 */
class Index extends Component
{
    use WithPagination;

    /**
     * Фильтр: manager | head_of_sales | secretary | director | archived | all.
     * Значения для ролей совпадают с `roles.name` в БД (см. Role enum) —
     * чтобы whereHas('roles', name=$filter) находил записи.
     */
    #[Url(as: 'filter')]
    public string $filter = 'manager';

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function archive(int $userId): void
    {
        if ($userId === auth()->id()) {
            session()->flash('error', 'Нельзя архивировать собственную учётку.');

            return;
        }

        $user = User::findOrFail($userId);
        $user->forceFill(['archived_at' => now()])->save();

        session()->flash('status', "«{$user->name}» переведён в архив.");
    }

    public function restore(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->forceFill(['archived_at' => null])->save();

        session()->flash('status', "«{$user->name}» восстановлен.");
    }

    /**
     * Снять «недоступен» немедленно (менеджер вернулся раньше срока).
     * Mark-as-unavailable идёт через диалог `open-unavailability {userId}`.
     */
    public function markAvailable(int $userId, ManagerUnavailabilityService $svc): void
    {
        $user = User::findOrFail($userId);
        $svc->markAvailable($user, auth()->user());
        session()->flash('status', "«{$user->name}» снова доступен для распределения.");
    }

    #[On('manager-availability-changed')]
    public function refreshAfterAvailabilityChange(): void
    {
        // Computed-перерасчёт — Livewire сам refresh'нёт users / counters.
        $this->resetPage();
    }

    #[Computed]
    public function users()
    {
        $query = User::query()
            ->with([
                'roles:id,name',
                'ownedMailboxes:id,owner_user_id,email,is_active,last_synced_at,last_error_at',
            ]);

        if ($this->filter === 'archived') {
            $query->archived();
        } elseif ($this->filter === 'all') {
            // ничего, обе категории
        } else {
            $query->active();
            if ($this->filter !== 'any-role') {
                $query->whereHas('roles', fn ($q) => $q->where('name', $this->filter));
            }
        }

        if ($this->search !== '') {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->search) . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'ilike', $needle)
                    ->orWhere('email', 'ilike', $needle);
            });
        }

        return $query
            ->orderBy('archived_at')
            ->orderBy('name')
            ->paginate(25);
    }

    #[Computed]
    public function counters(): array
    {
        // Ключи совпадают с role enum value + 'archived'.
        return [
            'manager' => User::role(RoleEnum::Manager->value)->active()->count(),
            'head_of_sales' => User::role(RoleEnum::HeadOfSales->value)->active()->count(),
            'secretary' => User::role(RoleEnum::Secretary->value)->active()->count(),
            'director' => User::role(RoleEnum::Director->value)->active()->count(),
            'archived' => User::archived()->count(),
        ];
    }

    public function render()
    {
        return view('livewire.admin.managers.index');
    }
}
