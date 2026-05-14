<?php

namespace App\Livewire\Requests;

use App\Enums\RequestStatus;
use App\Models\Request as RequestModel;
use App\Services\Request\RequestStateService;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Modal-диалог для перевода заявки в `postponed_until` с явной датой
 * клиента (Phase 1.11, Foundation §5.3 + §5.5).
 *
 * Дата сохраняется в `request_state_changes.payload.postponed_until`,
 * AttentionService::postponedUntilFor() читает её для дедлайна. Если
 * пользователь не задал дату — fallback +7 раб. дней.
 *
 * Семантическое отличие от Pause:
 *  - postponed_until — клиент отложил решение, мы ждём его реакции;
 *    статус остаётся «активным», заявка пересчитывает attention.
 *  - paused — менеджер заморозил заявку, attention снят полностью.
 */
class PostponeDialog extends Component
{
    public int $requestId;
    public bool $open = false;

    #[Validate('required|date|after:today')]
    public string $until = '';

    #[Validate('nullable|string|max:500')]
    public string $comment = '';

    public function mount(RequestModel $request): void
    {
        $this->requestId = $request->id;
    }

    #[On('open-postpone-dialog')]
    public function show(): void
    {
        $this->until = now()->addDays(7)->format('Y-m-d'); // дефолт +1 неделя
        $this->comment = '';
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function save(RequestStateService $service)
    {
        $this->validate();

        $req = RequestModel::findOrFail($this->requestId);
        $until = Carbon::parse($this->until)->setTime(9, 0); // утро рабочего дня

        try {
            $service->transitionTo(
                $req,
                RequestStatus::PostponedUntil,
                auth()->user(),
                [
                    'event' => 'manual',
                    'comment' => $this->comment !== '' ? $this->comment : null,
                    'payload' => [
                        'postponed_until' => $until->toIso8601String(),
                    ],
                ],
            );
        } catch (\DomainException $e) {
            $this->addError('until', $e->getMessage());

            return null;
        }

        $this->open = false;
        $this->dispatch('request-state-changed');
        session()->flash('status', sprintf(
            'Заявка отложена до %s.',
            $until->format('d.m.Y'),
        ));

        return $this->redirect(
            \Illuminate\Support\Facades\Request::header('Referer')
                ?: route('requests.show', $req),
            navigate: false,
        );
    }

    public function minAllowedDate(): string
    {
        return now()->addDay()->format('Y-m-d');
    }

    /**
     * Cap на «отложить»: 90 дней — крайний срок, иначе менеджеры
     * злоупотребляют. Phase 1.11 — config-default, без override.
     */
    public function maxAllowedDate(): string
    {
        $maxDays = (int) config('services.requests.max_postpone_days', 90);

        return now()->addDays($maxDays)->format('Y-m-d');
    }

    public function render()
    {
        return view('livewire.requests.postpone-dialog', [
            'minAllowed' => $this->minAllowedDate(),
            'maxAllowed' => $this->maxAllowedDate(),
        ]);
    }
}
