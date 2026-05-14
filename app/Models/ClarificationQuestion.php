<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Один вопрос клиенту, привязанный к конкретной позиции (request_item_id)
 * или общий (request_item_id IS NULL). Группируется в ClarificationBatch.
 *
 * Phase B (KB enrichment): answer / answered_at / answered_via_message_id
 * заполняются при детектировании ответа клиента на этот вопрос.
 */
class ClarificationQuestion extends Model
{
    protected $fillable = [
        'batch_id',
        'request_item_id',
        'question',
        'target_slot_key',
        'answer',
        'answered_at',
        'answered_via_message_id',
    ];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ClarificationBatch::class, 'batch_id');
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(RequestItem::class);
    }

    public function answeredViaMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'answered_via_message_id');
    }
}
