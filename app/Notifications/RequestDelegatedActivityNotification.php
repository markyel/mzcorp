<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Acting-менеджеру (кому временно делегирована заявка отсутствующего коллеги)
 * — о новом СОБЫТИИ по делегированной заявке (новое письмо клиента).
 * Database-канал (колокольчик в топбаре); email шлётся отдельно через
 * SystemNotificationMailer (DelegatedRequestNotifier), т.к. MAIL_MAILER=log.
 */
class RequestDelegatedActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly string $internalCode,
        public readonly ?string $subject,
        public readonly ?string $clientName,
    ) {
    }

    public static function from(Request $request): self
    {
        return new self(
            requestId: $request->id,
            internalCode: $request->internal_code,
            subject: $request->subject,
            clientName: $request->client_name ?: $request->client_email,
        );
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'delegated_activity',
            'request_id' => $this->requestId,
            'internal_code' => $this->internalCode,
            'subject' => mb_substr((string) $this->subject, 0, 200),
            'client_name' => mb_substr((string) $this->clientName, 0, 200),
        ];
    }
}
