<?php

namespace App\Livewire\Requests;

use App\Enums\ClosedLostReason;
use App\Enums\RequestStatus;
use App\Models\Request as RequestModel;
use App\Services\Request\RequestStateService;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Modal-диалог закрытия заявки как closed_lost с обязательной причиной
 * из ClosedLostReason taxonomy (Phase 1.10, Foundation §5.2).
 *
 * Открывается событием `open-close-lost-dialog`. Для reason'ов с
 * `requiresComment()=true` (client_declined_other, manual_other)
 * комментарий обязателен.
 */
class CloseLostDialog extends Component
{
    public int $requestId;
    public bool $open = false;

    #[Validate('required|string')]
    public string $reason = '';

    #[Validate('nullable|string|max:2000')]
    public string $comment = '';

    public function mount(RequestModel $request): void
    {
        $this->requestId = $request->id;
    }

    #[On('open-close-lost-dialog')]
    public function show(): void
    {
        $this->reason = '';
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

        $reasonEnum = ClosedLostReason::tryFrom($this->reason);
        if ($reasonEnum === null) {
            $this->addError('reason', 'Выберите причину из списка.');
            return null;
        }
        if ($reasonEnum->requiresComment() && trim($this->comment) === '') {
            $this->addError('comment', 'Для этой причины комментарий обязателен.');
            return null;
        }

        $req = RequestModel::findOrFail($this->requestId);

        try {
            $service->transitionTo($req, RequestStatus::ClosedLost, auth()->user(), [
                'closed_lost_reason' => $reasonEnum->value,
                'closed_lost_comment' => trim($this->comment) ?: null,
                'comment' => $reasonEnum->label(),
            ]);
        } catch (\DomainException $e) {
            $this->addError('reason', $e->getMessage());
            return null;
        }

        $this->open = false;
        $this->dispatch('request-state-changed');
        session()->flash('status', 'Заявка закрыта как потеря.');

        return $this->redirect(
            \Illuminate\Support\Facades\Request::header('Referer')
                ?: route('requests.show', $req),
            navigate: false,
        );
    }

    public function reasons(): array
    {
        return array_map(
            fn (ClosedLostReason $r) => ['value' => $r->value, 'label' => $r->label(), 'needsComment' => $r->requiresComment()],
            ClosedLostReason::cases(),
        );
    }

    public function render()
    {
        return view('livewire.requests.close-lost-dialog', [
            'reasons' => $this->reasons(),
        ]);
    }
}
