<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Пачка фото раздела «Фото»: очистка фона через Clipping Magic, архив результатов с теми же именами,
 * фото позиций — в каталог (CatalogItem::clean_photo_path). См. App\Services\Photos\PhotoBatchService.
 */
class PhotoBatch extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'status',
        'test',
        'total',
        'processed',
        'failed',
        'archive_path',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'test' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PhotoBatchItem::class);
    }

    public function inWork(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
    }

    /** Папка пачки на диске local. */
    public function dir(): string
    {
        return 'photo-batches/'.$this->id;
    }
}
