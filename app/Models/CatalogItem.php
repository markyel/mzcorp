<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Реплика строки корпоративного каталога (MDB → push API).
 *
 * См. миграцию `2026_05_12_180001_create_catalog_items_table.php` и
 * `App\Services\Catalog\CatalogImportService`.
 *
 * Источник истины — MDB на офисной машине. Любые UPDATE здесь
 * перезатираются следующим snapshot'ом. Никогда не правим вручную
 * из админки (если что-то не так — правим в MDB).
 */
class CatalogItem extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'name_en',
        'unit_name',
        'part_type',
        'brand',
        'brand_article',
        'form_factor',
        'size_a',
        'size_b',
        'size_c',
        'size_d',
        'size_e',
        'size_f',
        'weight',
        'price',
        'stock_available',
        'source_hash',
        'is_active',
        'last_imported_at',
        'last_import_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_imported_at' => 'datetime',
            'size_a' => 'decimal:3',
            'size_b' => 'decimal:3',
            'size_c' => 'decimal:3',
            'size_d' => 'decimal:3',
            'size_e' => 'decimal:3',
            'size_f' => 'decimal:3',
            'weight' => 'decimal:3',
            'price' => 'decimal:2',
            'stock_available' => 'integer',
        ];
    }

    public function lastImport(): BelongsTo
    {
        return $this->belongsTo(CatalogImport::class, 'last_import_id');
    }
}
