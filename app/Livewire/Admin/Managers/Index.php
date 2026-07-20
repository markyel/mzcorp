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
        if (! $this->canManage($user)) {
            session()->flash('error', 'Нет доступа к управлению админ-учётками.');

            return;
        }
        $user->forceFill(['archived_at' => now()])->save();

        session()->flash('status', "«{$user->name}» переведён в архив.");
    }

    public function restore(int $userId): void
    {
        $user = User::findOrFail($userId);
        if (! $this->canManage($user)) {
            session()->flash('error', 'Нет доступа к управлению админ-учётками.');

            return;
        }
        $user->forceFill(['archived_at' => null])->save();

        session()->flash('status', "«{$user->name}» восстановлен.");
    }

    /**
     * Может ли текущий пользователь управлять целевым.
     * Правило: админ-юзера может править только другой админ.
     */
    private function canManage(User $target): bool
    {
        $current = auth()->user();
        if (! $current) {
            return false;
        }
        if ($target->hasRole(RoleEnum::Admin->value) && ! $current->hasRole(RoleEnum::Admin->value)) {
            return false;
        }

        return true;
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

        // Админ-юзера видит только другой админ. РОПа/директора не должны
        // видеть admin-учёток вообще (даже в фильтре «все»).
        $current = auth()->user();
        if (! $current?->hasRole(RoleEnum::Admin->value)) {
            $query->whereDoesntHave('roles', fn ($q) => $q->where('name', RoleEnum::Admin->value));
        }

        if ($this->filter === 'archived') {
            $query->archived();
        } elseif ($this->filter === 'all') {
            // Вкладка называется «Все активные» — значит без архивных: они
            // живут в отдельной вкладке «Архив». Раньше здесь не применялся
            // никакой скоуп («обе категории»), и архивные показывались в
            // обеих вкладках сразу, противореча подписи.
            $query->active();
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

    /**
     * Счётчики вкладок. Набор ролей — из RoleEnum::userTabRoles(), НЕ списком
     * здесь: раньше роли были перечислены вручную и тут, и в blade, поэтому
     * «Снабжение» не появилось ни во вкладках, ни в счётчиках.
     *
     * @return array<string, int>  ключи = role enum value + 'archived'
     */
    #[Computed]
    public function counters(): array
    {
        $counters = [];
        foreach (RoleEnum::userTabRoles() as $role) {
            $counters[$role->value] = User::role($role->value)->active()->count();
        }
        $counters['archived'] = User::archived()->count();

        return $counters;
    }

    /**
     * Вкладки-фильтры: роли из enum + «Все активные» и «Архив».
     *
     * @return array<int, array{key: string, label: string, count: ?int}>
     */
    #[Computed]
    public function filterChips(): array
    {
        $counters = $this->counters;

        $chips = array_map(
            static fn (RoleEnum $r): array => [
                'key' => $r->value,
                'label' => $r->pluralLabel(),
                'count' => $counters[$r->value] ?? 0,
            ],
            RoleEnum::userTabRoles(),
        );

        $chips[] = ['key' => 'all', 'label' => 'Все активные', 'count' => null];
        $chips[] = ['key' => 'archived', 'label' => 'Архив', 'count' => $counters['archived']];

        return $chips;
    }

    public function render()
    {
        return view('livewire.admin.managers.index');
    }
}
