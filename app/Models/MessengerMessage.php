<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Сообщение в переписке бота заявок: in — от клиента, out — от бота или менеджера. */
class MessengerMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['messenger_chat_id', 'request_id', 'user_id', 'direction', 'kind', 'text', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(MessengerChat::class, 'messenger_chat_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    /** Менеджер, ответивший клиенту из карточки заявки. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
