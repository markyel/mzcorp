<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Цена закупки позиции каталога из 1С: первая (kind=first) и последняя
 * (kind=last) закупка. См. CatalogSupplierPriceImportService.
 */
class CatalogSupplierPrice extends Model
{
    public const KIND_FIRST = 'first';

    public const KIND_LAST = 'last';

    protected $fillable = [
        'catalog_item_id', 'sku', 'kind', 'supplier_name_1c', 'supplier_id',
        'priced_at', 'price', 'currency', 'source', 'import_file',
    ];

    protected $casts = [
        'priced_at' => 'date',
        'price' => 'decimal:4',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
