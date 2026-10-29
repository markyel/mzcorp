<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одно письмо рассылки: релиз → редакция.
 */
class PressReleaseSend extends Model
{
    public const STATUSES = [
        'queued' => 'в очереди',
        'sent' => 'отправлено',
        'failed' => 'не ушло',
        'replied' => 'ответили',
    ];

    protected $fillable = [
        'press_release_id', 'press_outlet_id', 'email', 'status', 'email_message_id',
        'message_id', 'error', 'sent_at', 'replied_at', 'reply_email_message_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'replied_at' => 'datetime',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(PressRelease::class, 'press_release_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PressOutlet::class, 'press_outlet_id');
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'reply_email_message_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }
}
