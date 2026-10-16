<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Цена позиции из ручной синхронизации каталога, которую плановый импорт не откатывает, пока
 * выгрузка mylift отдаёт старую (см. CatalogImportService::holdManualPrices).
 */
class CatalogManualPrice extends Model
{
    protected $fillable = [
        'catalog_item_id', 'sku', 'price', 'price_min', 'purchase_price', 'price_date',
        'catalog_manual_sync_id', 'hold_until',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'price_min' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'price_date' => 'date',
        'hold_until' => 'datetime',
    ];
}
