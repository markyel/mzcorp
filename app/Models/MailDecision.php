<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Решение маршрутизатора по письму (журнал mail_decisions). Пишет
 * MailDecisionRecorder из MailRouter::route(); читается в почтовом клиенте и
 * при разборах инцидентов.
 */
class MailDecision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'email_message_id',
        'mailbox_id',
        'stage',
        'outcome',
        'request_id',
        'category',
        'reason',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }
}
