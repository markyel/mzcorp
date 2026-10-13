<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Сообщение в переписке бота заявок: in — от клиента, out — от бота или менеджера. */
class MessengerMessage extends Model
{
    public const UPDATED_AT = null;

    /** Сообщение клиента в переписке по заявке (BotThreadService::fromClient). */
    public const KIND_CLIENT = 'client';

    protected $fillable = ['messenger_chat_id', 'request_id', 'user_id', 'direction', 'kind', 'text', 'meta', 'read_at', 'emailed_at'];

    protected $casts = ['meta' => 'array', 'read_at' => 'datetime', 'emailed_at' => 'datetime'];

    /**
     * Непрочитанные сообщения клиентов по заявкам менеджера.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $q
     */
    public function scopeUnreadFor($q, int $managerId)
    {
        return $q->where('messenger_messages.direction', 'in')
            ->where('messenger_messages.kind', self::KIND_CLIENT)
            ->whereNull('messenger_messages.read_at')
            ->whereHas('request', fn ($r) => $r->where('assigned_user_id', $managerId));
    }

    /** Менеджер открыл переписку по заявке — её сообщения прочитаны. */
    public static function markReadForRequest(int $requestId): int
    {
        return self::query()
            ->where('request_id', $requestId)
            ->where('direction', 'in')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

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
