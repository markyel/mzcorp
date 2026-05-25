<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app уведомление автору тикета о том, что админ оставил ответ.
 *
 * Только DatabaseChannel — email уже отправляется отдельным путём через
 * SupportTicketReplyMail из SupportTicketService::notifyOnReply, дублировать
 * через NotificationChannel::mail смысла нет.
 *
 * kind=support_reply показывается в bell-dropdown (см.
 * resources/views/livewire/notifications/bell.blade.php).
 * Клик ведёт на /support/{ticket}.
 */
class SupportTicketReplyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly SupportTicketMessage $message,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Данные для bell. Кли́ч `kind` парсится в bell.blade.php.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'support_reply',
            'ticket_id' => $this->ticket->id,
            'subject' => mb_substr((string) $this->ticket->subject, 0, 200),
            'reply_preview' => mb_substr(strip_tags((string) $this->message->body), 0, 140),
            'message_id' => $this->message->id,
            'replied_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
