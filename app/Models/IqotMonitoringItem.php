<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция каталога на мониторинге цен IQOT (гибрид: цены сайтов поставщиков + КП по почте).
 * Одна строка на catalog_item. См. App\Services\Iqot\IqotMonitoringService.
 */
class IqotMonitoringItem extends Model
{
    public const SOURCE_REFRESH = 'refresh';

    public const SOURCE_MANUAL = 'manual';

    public const MATCH_LABELS = [
        'pending' => 'сопоставляется',
        'matched' => 'сопоставлена',
        'ambiguous' => 'неоднозначно',
        'rejected' => 'не принята',
    ];

    protected $fillable = [
        'catalog_item_id', 'client_ref', 'source', 'active',
        'payload', 'payload_hash', 'sent_at', 'import_id', 'import_status', 'import_error',
        'match_status', 'match_note',
        'last_priced_at', 'prices_changed_at', 'best_price', 'best_source', 'best_supplier_domain', 'best_url',
        'price_min', 'price_median', 'price_max', 'sources_priced', 'has_kp', 'sources', 'prices_synced_at',
        'deactivated_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'payload' => 'array',
            'sources' => 'array',
            'has_kp' => 'boolean',
            'sent_at' => 'datetime',
            'last_priced_at' => 'datetime',
            'prices_changed_at' => 'datetime',
            'prices_synced_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'best_price' => 'decimal:2',
            'price_min' => 'decimal:2',
            'price_median' => 'decimal:2',
            'price_max' => 'decimal:2',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
