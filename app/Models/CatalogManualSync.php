<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Загрузка выгрузки 1С с актуализированными ценами (раздел «Синхронизация каталога»): разбор
 * построчно и итог применения. См. App\Services\Catalog\CatalogManualSyncService.
 */
class CatalogManualSync extends Model
{
    public const STATUS_PARSED = 'parsed';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    /** Статусы строки разбора. */
    public const ROW_CHANGED = 'changed';

    public const ROW_SAME = 'same';

    public const ROW_UNKNOWN = 'unknown';

    public const ROW_INVALID = 'invalid';

    protected $fillable = [
        'user_id', 'original_name', 'file_path', 'status',
        'rows_total', 'rows_matched', 'rows_changed', 'rows_unknown', 'rows_invalid', 'rows',
        'catalog_import_id', 'applied_by_user_id', 'applied_at', 'error',
    ];

    protected $casts = [
        'rows' => 'array',
        'applied_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }
}
