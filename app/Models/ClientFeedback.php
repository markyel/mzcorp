<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Впечатление клиента о работе с нами — и что мы с этим сделали.
 *
 * @property string $quote
 * @property string $status
 */
class ClientFeedback extends Model
{
    protected $table = 'client_feedback';

    public const SOURCES = [
        'yandex_maps' => 'Яндекс.Карты',
        'email' => 'Письмо',
        'call' => 'Звонок',
        'meeting' => 'Встреча',
        'survey' => 'Опрос',
        'competitor' => 'Отзывы конкурента',
        'other' => 'Другое',
    ];

    public const STATUSES = [
        'new' => 'Новое',
        'in_progress' => 'В работе',
        'done' => 'Сделано',
        'rejected' => 'Отклонено',
    ];

    protected $fillable = [
        'source', 'source_url', 'client', 'quote', 'topic',
        'status', 'decision', 'owner_user_id', 'resolved_at', 'created_by_user_id',
    ];

    protected $casts = ['resolved_at' => 'datetime'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Открытые — те, по которым решения ещё нет. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['new', 'in_progress'], true);
    }
}
