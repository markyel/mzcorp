<?php

namespace App\Livewire\Admin\Managers;

use App\Models\User;
use App\Services\Request\ManagerUnavailabilityService;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Modal-диалог для пометки менеджера как «недоступен»
 * (Foundation Фаза 2 — отпуск / командировка / больничный).
 *
 * Семантика — DELEGATION. Заявка остаётся за менеджером, но на время
 * его отсутствия другой менеджер получает временный доступ. При
 * возвращении (markAvailable) delegation закрывается.
 *
 * Слушает `open-unavailability {userId}`. После save:
 *  - mark unavailable;
 *  - если стоял чекбокс «открыть заявки коллегам» — batch delegation;
 *  - flash + emit `manager-availability-changed`.
 */
class UnavailabilityDialog extends Component
{
    public ?int $userId = null;
    public bool $open = false;

    #[Validate('required|date|after:today')]
    public string $until = '';

    #[Validate('required|string|min:3|max:500')]
    public string $reason = '';

    public bool $delegate = true;

    #[On('open-unavailability')]
    public function show(int $userId): void
    {
        $this->userId = $userId;
        $this->until = now()->addDays(7)->format('Y-m-d');
        $this->reason = '';
        $this->delegate = true;
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->userId = null;
    }

    public function save(ManagerUnavailabilityService $svc)
    {
        $this->validate();
        if (! $this->userId) {
            $this->close();

            return null;
        }
        $user = User::findOrFail($this->userId);

        try {
            $svc->markUnavailable(
                $user,
                Carbon::parse($this->until)->endOfDay(),
                $this->reason,
                auth()->user(),
            );
            $delegateStats = null;
            if ($this->delegate) {
                $delegateStats = $svc->delegateActiveRequests($user, auth()->user());
            }
        } catch (\DomainException $e) {
            $this->addError('reason', $e->getMessage());

            return null;
        }

        $this->open = false;
        $this->dispatch('manager-availability-changed');
        session()->flash('status', sprintf(
            '«%s» недоступен до %s.%s',
            $user->name,
            Carbon::parse($this->until)->format('d.m.Y'),
            $delegateStats
                ? sprintf(' Открыто коллегам: %d заявок (пропущено: %d).', $delegateStats['delegated'], $delegateStats['skipped'])
                : '',
        ));

        return null;
    }

    public function render()
    {
        $user = $this->userId ? User::find($this->userId) : null;

        return view('livewire.admin.managers.unavailability-dialog', [
            'user' => $user,
            'minAllowed' => now()->addDay()->format('Y-m-d'),
            'maxAllowed' => now()->addDays(120)->format('Y-m-d'),
        ]);
    }
}
