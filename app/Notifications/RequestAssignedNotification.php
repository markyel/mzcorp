<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Менеджеру отправляется когда AssignmentService назначил ему новую заявку
 * (включая reassign-from-unavailable). Foundation Фаза 2 — базовые
 * напоминания.
 *
 * Database channel only — UI показывает через bell-icon в topbar.
 * Email-канал — Phase 6 (digest).
 */
class RequestAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly string $internalCode,
        public readonly ?string $subject,
        public readonly ?string $clientName,
        public readonly string $reason,
    ) {
    }

    public static function from(Request $request, string $reason = 'auto_assign'): self
    {
        return new self(
            requestId: $request->id,
            internalCode: $request->internal_code,
            subject: $request->subject,
            clientName: $request->client_name ?: $request->client_email,
            reason: $reason,
        );
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'request_assigned',
            'request_id' => $this->requestId,
            'internal_code' => $this->internalCode,
            'subject' => mb_substr((string) $this->subject, 0, 200),
            'client_name' => mb_substr((string) $this->clientName, 0, 200),
            'reason' => $this->reason,
        ];
    }
}
