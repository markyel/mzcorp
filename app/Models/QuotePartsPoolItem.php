<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция каталога, работа по которой передана в QuoteParts (см. QuotePartsPoolService).
 */
class QuotePartsPoolItem extends Model
{
    protected $table = 'quoteparts_pool_items';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_ERROR = 'error';

    public const LABELS = [
        self::STATUS_PENDING => 'Передаётся',
        self::STATUS_ACTIVE => 'В работе',
        self::STATUS_PAUSED => 'На паузе',
        self::STATUS_ARCHIVED => 'Убрана',
        self::STATUS_ERROR => 'Ошибка передачи',
    ];

    protected $fillable = [
        'catalog_item_id',
        'sku',
        'status',
        'remote_version',
        'payload_hash',
        'synced_at',
        'last_error',
        'pause_reasons',
        'added_by_user_id',
        'removed_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'removed_at' => 'datetime',
        'pause_reasons' => 'array',
    ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /** Позиции, которые сейчас в пуле (не убраны). */
    public function scopeInPool(Builder $q): Builder
    {
        return $q->whereNull('removed_at');
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }
}
