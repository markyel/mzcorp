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

    /**
     * Наша цена против цен поставщиков — без НДС, как в разделе IQOT (IqotPosition::priceComparison):
     * цена каталога указана с НДС, делим на 1+ставка. Цены поставщиков (sources[].net) уже без НДС.
     *
     * @param  float|int|string|null  $catalogPrice  цена каталога с НДС (null — возьмём из позиции)
     * @return array{our_net:?float, rank:?int, total:int, delta:?float, delta_pct:?float}
     */
    public function comparison(float|int|string|null $catalogPrice = null): array
    {
        $rate = (float) app_setting('tax.vat_percent', config('services.tax.vat_percent', 22)) / 100;
        $gross = $catalogPrice ?? $this->catalogItem?->price;
        $ourNet = is_numeric($gross) && (float) $gross > 0 ? round((float) $gross / (1 + $rate), 2) : null;
        $nets = array_values(array_filter(array_map(fn ($s) => $s['net'] ?? null, (array) ($this->sources ?? [])), 'is_numeric'));
        $best = $nets === [] ? null : min($nets);

        return [
            'our_net' => $ourNet,
            'rank' => $ourNet === null || $nets === [] ? null : 1 + count(array_filter($nets, fn ($n) => $n < $ourNet)),
            'total' => count($nets) + ($ourNet !== null ? 1 : 0),
            'delta' => $ourNet !== null && $best !== null ? round($ourNet - $best, 2) : null,
            'delta_pct' => $ourNet !== null && $best ? round(($ourNet - $best) / $best * 100, 1) : null,
        ];
    }
}
