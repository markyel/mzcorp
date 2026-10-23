<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Фото в пачке раздела «Фото»: исходник, результат без фона, позиция каталога по имени файла. */
class PhotoBatchItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'photo_batch_id',
        'original_name',
        'sku',
        'sku_exact',
        'catalog_item_id',
        'source_path',
        'result_path',
        'status',
        'error',
        'cm_image_id',
        'saved_to_catalog',
    ];

    protected $casts = [
        'sku_exact' => 'boolean',
        'saved_to_catalog' => 'boolean',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PhotoBatch::class, 'photo_batch_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /** Имя файла результата: то же имя, расширение .jpg (белый фон — JPEG). */
    public function resultName(): string
    {
        return pathinfo($this->original_name, PATHINFO_FILENAME).'.jpg';
    }
}
