<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Аудит-запись одной выгрузки каталога. См. миграцию
 * `2026_05_12_180000_create_catalog_imports_table.php` и
 * `App\Services\Catalog\CatalogImportService`.
 */
class CatalogImport extends Model
{
    public const MODE_FULL = 'full';
    public const MODE_DELTA = 'delta';

    protected $fillable = [
        'mode',
        'source',
        'client_ip',
        'rows_total',
        'rows_created',
        'rows_updated',
        'rows_unchanged',
        'rows_soft_deleted',
        'duration_ms',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'rows_total' => 'integer',
            'rows_created' => 'integer',
            'rows_updated' => 'integer',
            'rows_unchanged' => 'integer',
            'rows_soft_deleted' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
