<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Копия оффера поставщика из QuoteParts (QuotePartsOfferSync): ответ на запрос или строка
 * прайса. Показывается в «Снабжении» как ответ поставщика.
 */
class QuotePartsOffer extends Model
{
    protected $table = 'quoteparts_offers';

    protected $fillable = [
        'remote_id',
        'catalog_item_id',
        'sku',
        'supplier_key',
        'supplier_name',
        'price',
        'currency',
        'tiers',
        'lead_time_days',
        'valid_until',
        'kind',
        'source_type',
        'status',
        'superseded',
        'remote_updated_at',
        'brand',
        'part_number',
        'product_name',
        'note',
        'photos',
    ];

    protected $casts = [
        'tiers' => 'array',
        'price' => 'decimal:4',
        'valid_until' => 'date',
        'superseded' => 'boolean',
        'remote_updated_at' => 'datetime',
    ];

    /** Что предложено (вид оффера QuoteParts). */
    public const KINDS = [
        'original' => 'оригинал',
        'analog' => 'аналог',
        'copy' => 'копия',
        'refurbished' => 'восстановленное',
        'replacement' => 'замена',
    ];

    public static function kindLabel(?string $kind): string
    {
        return self::KINDS[$kind ?? 'original'] ?? (string) $kind;
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /** Действует: не вытеснен и не истёк. */
    public function isUsable(): bool
    {
        return ! $this->superseded && in_array($this->status, ['active', 'expiring'], true);
    }
}
