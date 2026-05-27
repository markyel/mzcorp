<?php

namespace App\Models;

use App\Enums\ClientNotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись об отправленном автоматическом уведомлении клиенту.
 *
 * Идемпотентность через uniq(request_id, type, scope_key) — см. миграцию.
 */
class ClientNotificationSent extends Model
{
    protected $table = 'client_notifications_sent';

    protected $fillable = [
        'request_id',
        'type',
        'scope_key',
        'outgoing_email_message_id',
        'reply_to_email_message_id',
        'recipient_email',
        'subject',
        'body_rendered_html',
        'body_rendered_plain',
        'sent_at',
        'triggered_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ClientNotificationType::class,
            'sent_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'request_id');
    }

    public function outgoingEmailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'outgoing_email_message_id');
    }

    public function replyToEmailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'reply_to_email_message_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
