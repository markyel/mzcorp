<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция исходящего КП/счёта (одна строка из PDF/XLSX).
 *
 * После `OutboundQuoteItemMatcher` поля `matched_*` указывают на
 * `catalog_items` (по M-SKU) и `request_items` (best-match).
 */
class OutboundQuoteItem extends Model
{
    public const MATCH_SOURCE_SKU_EXACT = 'sku_exact';
    public const MATCH_SOURCE_CATALOG_TO_REQUEST = 'catalog_to_request';
    // M-SKU из quote_item совпал с M-SKU извлечённым из RequestItem.parsed_article/name
    // (клиент сам написал M-SKU в заявке, RequestItem.catalog_item_id ещё null).
    public const MATCH_SOURCE_SKU_TO_REQUEST = 'sku_to_request';
    // catalog_items.name похож на RequestItem.parsed_name (catalog был найден через
    // M-SKU, но request не имеет catalog_item_id). Сильный сигнал каталога позволяет
    // понизить порог fuzzy до 0.50.
    public const MATCH_SOURCE_CATALOG_NAME_TO_REQUEST = 'catalog_name_to_request';
    public const MATCH_SOURCE_FUZZY_ARTICLE = 'fuzzy_article';
    public const MATCH_SOURCE_FUZZY_NAME = 'fuzzy_name';
    public const MATCH_SOURCE_LLM = 'llm';
    public const MATCH_SOURCE_UNMATCHED = 'unmatched';
    // Ручная привязка оператором через UI (Phase следующая — таб «КП»).
    public const MATCH_SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'outbound_quote_id',
        'position',
        'raw_name',
        'raw_article',
        'raw_brand',
        'quantity',
        'unit_measure',
        'unit_quantity',
        'unit_price',
        'line_price',
        'line_total',
        'delivery_days',
        'is_analog',
        'notes',
        'matched_catalog_item_id',
        'matched_request_item_id',
        'match_score',
        'match_source',
        'match_reason',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'line_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'is_analog' => 'boolean',
            'match_score' => 'float',
            'payload' => 'array',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(OutboundQuote::class, 'outbound_quote_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'matched_catalog_item_id');
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(RequestItem::class, 'matched_request_item_id');
    }

    public function isMatched(): bool
    {
        return $this->matched_request_item_id !== null
            || $this->matched_catalog_item_id !== null;
    }
}
