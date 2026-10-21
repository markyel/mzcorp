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
        'review_status',
        'reviewed_by_user_id',
        'reviewed_at',
        'rfq_remote_id',
        'rfq_tag',
        'request_id',
        'clarify_asked_at',
        'clarify_cleared_at',
        'ignored_at',
        'ignored_by_user_id',
    ];

    /** Разбор отклонения (не оригинала): ждёт, принято к закупке, отклонено. */
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_ACCEPTED = 'accepted';

    public const REVIEW_REJECTED = 'rejected';

    protected $casts = [
        'tiers' => 'array',
        'price' => 'decimal:4',
        'valid_until' => 'date',
        'superseded' => 'boolean',
        'remote_updated_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'clarify_asked_at' => 'datetime',
        'clarify_cleared_at' => 'datetime',
        'ignored_at' => 'datetime',
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

    /** Заявка, по которой шёл запрос QuoteParts (ответ на запрос по заявке). */
    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    /** Цена годится как цена позиции: оригинал или отклонение, принятое к закупке. */
    public function countsAsItemPrice(): bool
    {
        // «Игнорировать» — снабжение решило, что предложение не о том: цены позиции нет.
        if ($this->ignored_at !== null) {
            return false;
        }

        return ($this->kind ?? 'original') === 'original' || $this->review_status === self::REVIEW_ACCEPTED;
    }

    /** Действует: не вытеснен и не истёк. */
    public function isUsable(): bool
    {
        return ! $this->superseded && in_array($this->status, ['active', 'expiring'], true);
    }
}
