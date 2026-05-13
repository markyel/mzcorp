<?php

namespace App\Livewire\Requests;

use App\Models\Request as RequestModel;
use App\Services\Request\RequestPauseService;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Modal-диалог для перевода заявки в paused (Phase 1.10, Foundation §5.4).
 *
 * Открывается из action-panel карточки заявки кнопкой «⏸ Пауза».
 * Slушает `open-pause-dialog`. Cap-проверка `paused_until <= today +
 * max_pause_days` — на сервисе, в UI стоит min/max на date-input.
 *
 * Паттерн: public `int $requestId`, не Eloquent-модель.
 */
class PauseDialog extends Component
{
    public int $requestId;
    public bool $open = false;

    #[Validate('required|date|after:today')]
    public string $until = '';

    #[Validate('required|string|min:3|max:500')]
    public string $reason = '';

    public function mount(RequestModel $request): void
    {
        $this->requestId = $request->id;
    }

    #[On('open-pause-dialog')]
    public function show(): void
    {
        $this->until = now()->addDays(3)->format('Y-m-d'); // дефолт +3 дня
        $this->reason = '';
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function save(RequestPauseService $service)
    {
        $this->validate();

        $req = RequestModel::findOrFail($this->requestId);

        try {
            $service->pauseUntil(
                $req,
                Carbon::parse($this->until)->endOfDay(),
                $this->reason,
                auth()->user(),
            );
        } catch (\DomainException $e) {
            $this->addError('until', $e->getMessage());
            return null;
        }

        $this->open = false;
        $this->dispatch('request-state-changed');
        session()->flash('status', sprintf(
            'Заявка на паузе до %s.',
            Carbon::parse($this->until)->format('d.m.Y'),
        ));

        return $this->redirect(
            \Illuminate\Support\Facades\Request::header('Referer')
                ?: route('requests.show', $req),
            navigate: false,
        );
    }

    /**
     * Максимальная разрешённая дата для date-input (для атрибута max=).
     */
    public function maxAllowedDate(): string
    {
        $maxDays = (int) config('services.requests.max_pause_days', 21);
        return now()->addDays($maxDays)->format('Y-m-d');
    }

    /**
     * Минимальная — завтра.
     */
    public function minAllowedDate(): string
    {
        return now()->addDay()->format('Y-m-d');
    }

    public function render()
    {
        return view('livewire.requests.pause-dialog', [
            'maxAllowed' => $this->maxAllowedDate(),
            'minAllowed' => $this->minAllowedDate(),
            'maxDays' => (int) config('services.requests.max_pause_days', 21),
        ]);
    }
}
