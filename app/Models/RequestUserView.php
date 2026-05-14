<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «Менеджер открыл карточку заявки в момент last_seen_at».
 *
 * Pivot между Request и User, используется AttentionService::onManagerOpened
 * (Detail::mount) для снятия attention_reason=ClientReplied и для будущих
 * unread-индикаторов (счётчик новых inbound с моменте последнего просмотра).
 *
 * Unique (request_id, user_id) — upsert при каждом открытии карточки.
 */
class RequestUserView extends Model
{
    protected $fillable = [
        'request_id',
        'user_id',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
