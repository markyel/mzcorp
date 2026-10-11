<?php

namespace App\Notifications;

use App\Models\EmailMessage;
use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Автору — письмо так и не ушло: SMTP недоступен дольше, чем длятся
 * автоповторы, или ошибка не временная (OutgoingSendRetryService).
 * Письмо осталось черновиком; ссылка ведёт к нему (заявка или «Почта»).
 */
class OutgoingSendFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $draftId,
        public readonly ?int $mailboxId,
        public readonly ?int $requestId,
        public readonly ?string $internalCode,
        public readonly ?string $subject,
        public readonly ?string $recipient,
    ) {
    }

    public static function from(EmailMessage $draft): self
    {
        $request = $draft->related_request_id ? Request::find($draft->related_request_id) : null;
        $to = collect($draft->to_recipients ?? [])->first();

        return new self(
            draftId: $draft->id,
            mailboxId: $draft->mailbox_id,
            requestId: $request?->id,
            internalCode: $request?->internal_code,
            subject: $draft->subject,
            recipient: is_array($to) ? ($to['email'] ?? null) : (is_string($to) ? $to : null),
        );
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'outgoing_send_failed',
            'draft_id' => $this->draftId,
            'mailbox_id' => $this->mailboxId,
            'request_id' => $this->requestId,
            'internal_code' => $this->internalCode,
            'subject' => mb_substr((string) $this->subject, 0, 200),
            'recipient' => mb_substr((string) $this->recipient, 0, 200),
        ];
    }
}
