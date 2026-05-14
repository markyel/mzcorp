<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Batch уточняющих вопросов клиенту (Foundation §6.2).
 *
 * Один batch = одно исходящее письмо с N вопросами. Создаётся в
 * ClarificationPanel в табе «Позиции» карточки заявки. После отправки
 * (ComposeForm::send) status → 'sent', заявка получает
 * AwaitingClientClarification.
 */
class ClarificationBatch extends Model
{
    public const STATUS_DRAFTED = 'drafted';
    public const STATUS_SENT = 'sent';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'request_id',
        'created_by_user_id',
        'status',
        'general_question',
        'draft_email_id',
        'sent_message_id',
        'sent_at',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ClarificationQuestion::class, 'batch_id')->orderBy('id');
    }

    public function draftEmail(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'draft_email_id');
    }

    public function sentMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'sent_message_id');
    }
}
