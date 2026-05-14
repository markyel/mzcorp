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

    /**
     * Начало периода. Пусто = «прямо сейчас» (now()).
     * Дата в будущем = планирование, до неё менеджер ещё доступен.
     */
    #[Validate('nullable|date|after_or_equal:today')]
    public string $from = '';

    #[Validate('required|date|after:today')]
    public string $until = '';

    #[Validate('required|string|min:3|max:500')]
    public string $reason = '';

    /** Открывать активные заявки коллегам на время отсутствия. */
    public bool $delegate = true;

    #[On('open-unavailability')]
    public function show(int $userId): void
    {
        $this->userId = $userId;
        $this->from = '';                                       // default: с сейчас
        $this->until = now()->addDays(7)->format('Y-m-d');      // default: +7 дн
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

        $from = $this->from !== '' ? Carbon::parse($this->from)->startOfDay() : null;
        $until = Carbon::parse($this->until)->endOfDay();
        $isPlanned = $from !== null && $from->isFuture();

        try {
            $svc->markUnavailable(
                $user,
                $from,
                $until,
                $this->reason,
                auth()->user(),
                $this->delegate, // запоминаем флаг для cron'а planned-apply
            );

            // Если период уже идёт — открываем delegations сразу.
            // Если планируется в будущем — cron `users:apply-planned-unavailability`
            // сделает это в момент наступления (если auto_delegate=true).
            $delegateStats = null;
            if ($this->delegate && ! $isPlanned) {
                $delegateStats = $svc->delegateActiveRequests($user, auth()->user());
            }
        } catch (\DomainException $e) {
            $this->addError('reason', $e->getMessage());

            return null;
        }

        $this->open = false;
        $this->dispatch('manager-availability-changed');

        if ($isPlanned) {
            session()->flash('status', sprintf(
                '«%s» запланировано отсутствие с %s по %s.%s',
                $user->name,
                $from->format('d.m.Y'),
                $until->format('d.m.Y'),
                $this->delegate
                    ? ' В день начала заявки автоматически откроются коллегам.'
                    : '',
            ));
        } else {
            session()->flash('status', sprintf(
                '«%s» недоступен до %s.%s',
                $user->name,
                $until->format('d.m.Y'),
                $delegateStats
                    ? sprintf(' Открыто коллегам: %d заявок (пропущено: %d).', $delegateStats['delegated'], $delegateStats['skipped'])
                    : '',
            ));
        }

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
