<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Зафиксированное изменение цены каталожной позиции (было → стало).
 * Пишется CatalogImportService при апсёрте. См. миграцию.
 *
 * @property int $catalog_item_id
 * @property string $sku
 * @property ?string $old_price
 * @property ?string $new_price
 * @property ?string $old_price_min
 * @property ?string $new_price_min
 */
class CatalogPriceChange extends Model
{
    protected $fillable = [
        'catalog_item_id',
        'sku',
        'old_price',
        'new_price',
        'old_price_min',
        'new_price_min',
        'import_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_price' => 'decimal:2',
            'new_price' => 'decimal:2',
            'old_price_min' => 'decimal:2',
            'new_price_min' => 'decimal:2',
            'changed_at' => 'datetime',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(CatalogImport::class, 'import_id');
    }

    /** Абсолютная дельта по основной цене (стало − было), либо null. */
    public function priceDelta(): ?float
    {
        if ($this->old_price === null || $this->new_price === null) {
            return null;
        }

        return round((float) $this->new_price - (float) $this->old_price, 2);
    }
}
