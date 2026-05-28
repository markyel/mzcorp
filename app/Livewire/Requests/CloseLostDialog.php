<?php

namespace App\Livewire\Requests;

use App\Enums\BlocklistEntrySource;
use App\Enums\BlocklistEntryType;
use App\Enums\ClosedLostReason;
use App\Enums\RequestStatus;
use App\Models\Request as RequestModel;
use App\Services\Mail\SenderBlocklistService;
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
 *
 * Особый случай — reason=Spam: после транзита статуса отправитель
 * добавляется в `sender_blocklist`. Пользователь выбирает scope
 * блокировки — только email или весь домен. Если email письма пуст
 * (редкий edge-case), Spam-вариант недоступен.
 */
class CloseLostDialog extends Component
{
    public int $requestId;
    public bool $open = false;

    #[Validate('required|string')]
    public string $reason = '';

    #[Validate('nullable|string|max:2000')]
    public string $comment = '';

    /** 'email' | 'domain' — выбор пользователя при reason=Spam. */
    public string $blocklistScope = 'email';

    public function mount(RequestModel $request): void
    {
        $this->requestId = $request->id;
    }

    #[On('open-close-lost-dialog')]
    public function show(): void
    {
        $this->reason = '';
        $this->comment = '';
        $this->blocklistScope = 'email';
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function save(RequestStateService $service, SenderBlocklistService $blocklist)
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

        // Подготовка spam-варианта: вычисляем from_email и валидируем scope
        // ДО транзита, чтобы не закрыть заявку, если стоп-лист откажется
        // принять (например, пустой/невалидный from_email).
        $blocklistContext = null;
        if ($reasonEnum === ClosedLostReason::Spam) {
            $fromEmail = (string) ($req->emailMessage?->from_email ?? '');
            if (trim($fromEmail) === '') {
                $this->addError('reason', 'У заявки нет исходного письма с адресом отправителя — нельзя автоматически добавить в стоп-лист. Выберите другую причину.');
                return null;
            }

            $type = $this->blocklistScope === 'domain'
                ? BlocklistEntryType::Domain
                : BlocklistEntryType::Email;

            $rawValue = $type === BlocklistEntryType::Domain
                ? (string) strstr($fromEmail, '@')
                : $fromEmail;
            // strstr c '@' даёт '@domain' — срезаем @
            if ($type === BlocklistEntryType::Domain) {
                $rawValue = ltrim($rawValue, '@');
            }

            $normalized = $blocklist->normalizeFor($type, $rawValue);
            if ($normalized === null) {
                $this->addError('reason', 'Не удалось распознать '.($type === BlocklistEntryType::Domain ? 'домен' : 'адрес').' отправителя ('.$fromEmail.'). Выберите другую причину.');
                return null;
            }

            $blocklistContext = [
                'type' => $type,
                'rawValue' => $rawValue,
                'normalized' => $normalized,
            ];
        }

        try {
            $payload = [
                'closed_lost_reason' => $reasonEnum->value,
                'closed_lost_comment' => trim($this->comment) ?: null,
                'comment' => $reasonEnum->label(),
            ];

            $service->transitionTo($req, RequestStatus::ClosedLost, auth()->user(), $payload);
        } catch (\DomainException $e) {
            $this->addError('reason', $e->getMessage());
            return null;
        }

        // Транзит прошёл — теперь блокируем отправителя. Если block упадёт
        // (например, race с параллельным CloseLost от другого менеджера —
        // запись уже существует), это не критично: статус уже закрыт.
        if ($blocklistContext !== null) {
            try {
                $blocklist->block(
                    $blocklistContext['rawValue'],
                    $blocklistContext['type'],
                    BlocklistEntrySource::FromRequest,
                    auth()->user(),
                    $req,
                    'Из заявки '.$req->internal_code.' ('.trim($this->comment).')',
                );
                session()->flash('status', 'Заявка закрыта; отправитель добавлен в стоп-лист.');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('CloseLostDialog: blocklist add failed (status already changed)', [
                    'request_id' => $req->id,
                    'error' => $e->getMessage(),
                ]);
                session()->flash('status', 'Заявка закрыта, но добавление в стоп-лист не удалось — попробуйте вручную.');
            }
        } else {
            session()->flash('status', 'Заявка закрыта как потеря.');
        }

        $this->open = false;
        $this->dispatch('request-state-changed');

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

    /**
     * Адрес и домен отправителя для preview при reason=Spam.
     */
    public function senderInfo(): array
    {
        $req = RequestModel::find($this->requestId);
        $email = $req?->emailMessage?->from_email;
        if (! $email) {
            return ['email' => null, 'domain' => null];
        }
        $atPos = strrpos($email, '@');

        return [
            'email' => $email,
            'domain' => $atPos !== false ? substr($email, $atPos + 1) : null,
        ];
    }

    public function render()
    {
        return view('livewire.requests.close-lost-dialog', [
            'reasons' => $this->reasons(),
            'senderInfo' => $this->senderInfo(),
        ]);
    }
}
