<?php

namespace App\Livewire\Admin\Managers;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Livewire\Attributes\Computed;
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

    /** Фильтр: managers | head_of_sales | secretary | director | archived | all */
    #[Url(as: 'filter')]
    public string $filter = 'managers';

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
        // Метрики для чипов фильтра.
        return [
            'managers' => User::role(RoleEnum::Manager->value)->active()->count(),
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
