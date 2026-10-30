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
        // Поставщик писал по-китайски: в полях выше — перевод, здесь — его текст по полям.
        'original_texts',
        // Цена агента (UniSystem): реальный поставщик за ней — Sicor, Donati…; null — не агент или не назвал.
        'origin_supplier',
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
        'export_comment',
        'export_comment_by_user_id',
    ];

    /** Разбор отклонения (не оригинала): ждёт, принято к закупке, отклонено. */
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_ACCEPTED = 'accepted';

    public const REVIEW_REJECTED = 'rejected';

    protected $casts = [
        'tiers' => 'array',
        'original_texts' => 'array',
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

    /**
     * Что значит вид предложения — для подсказок и комментария в 1С. «Не оригинал» — только копия и аналог:
     * замена — новый артикул самого производителя, восстановленное — б/у оригинал (26.10.2026: «на разборе ·
     * не оригинал» у замены Sicor читалось как «б/у с разборки»).
     */
    public const KIND_HINTS = [
        'analog' => 'другой производитель, не оригинал',
        'copy' => 'не оригинал',
        'refurbished' => 'б/у оригинал, восстановленный',
        'replacement' => 'новый артикул производителя вместо прежнего',
    ];

    /** Статус проверки снабжением (не оригинал / замена / б/у — цена позиции только после «Принять к закупке»). */
    public const REVIEW_LABELS = [
        self::REVIEW_PENDING => 'на проверке у снабжения',
        self::REVIEW_ACCEPTED => 'принято к закупке',
        self::REVIEW_REJECTED => 'отклонено',
    ];

    public static function kindLabel(?string $kind): string
    {
        return self::KINDS[$kind ?? 'original'] ?? (string) $kind;
    }

    public static function kindHint(?string $kind): ?string
    {
        return self::KIND_HINTS[$kind ?? 'original'] ?? null;
    }

    public static function reviewLabel(?string $status): string
    {
        return self::REVIEW_LABELS[$status ?? self::REVIEW_PENDING] ?? self::REVIEW_LABELS[self::REVIEW_PENDING];
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
