<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Чёрный список M-артикулов каталога (дубли из MDB). Позиции с этими SKU
 * исключены из каталога и не возвращаются при повторных импортах — см.
 * миграцию create_catalog_sku_blacklist_table и CatalogImportService (фильтр
 * снапшота) + команду catalog:blacklist-skus.
 */
class CatalogSkuBlacklist extends Model
{
    protected $table = 'catalog_sku_blacklist';

    protected $fillable = ['sku', 'reason'];

    /**
     * Множество blacklisted SKU в нормализованном виде (UPPER+trim) для
     * быстрой проверки при импорте: [SKU => true].
     *
     * @return array<string, true>
     */
    public static function skuSet(): array
    {
        return static::query()
            ->pluck('sku')
            ->mapWithKeys(fn ($s) => [mb_strtoupper(trim((string) $s)) => true])
            ->all();
    }
}
