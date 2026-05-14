<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Менеджеру отправляется когда AttentionService::sweepOverdue пометил
 * его заявку overdue (level 0 → 1). Только при первом переходе в overdue,
 * не каждые 15 минут — иначе спам.
 *
 * Database channel only.
 */
class RequestAttentionOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly string $internalCode,
        public readonly ?string $subject,
        public readonly string $statusLabel,
        public readonly ?string $attentionReason,
    ) {
    }

    public static function from(Request $request): self
    {
        return new self(
            requestId: $request->id,
            internalCode: $request->internal_code,
            subject: $request->subject,
            statusLabel: $request->status->label(),
            attentionReason: $request->attention_reason?->label(),
        );
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'attention_overdue',
            'request_id' => $this->requestId,
            'internal_code' => $this->internalCode,
            'subject' => mb_substr((string) $this->subject, 0, 200),
            'status_label' => $this->statusLabel,
            'attention_reason' => $this->attentionReason,
        ];
    }
}
