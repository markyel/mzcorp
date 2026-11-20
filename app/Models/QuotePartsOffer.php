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
        // Наименование предлагаемого товара от поставщика (любой вид), необязательное.
        'description',
        // Поставщик писал по-китайски: в полях выше — перевод, здесь — его текст по полям.
        'original_texts',
        // Цена агента (UniSystem): реальный поставщик за ней — Sicor, Donati…; null — не агент или не назвал.
        'origin_supplier',
        // Базис поставки цены («EXW Europe») и за что цена, когда не за единицу (кусок каната 135 м).
        'incoterm',
        'incoterm_place',
        'price_for_qty',
        'price_for_unit',
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
        // Пересчёт на нашу единицу (снабжение): цена поставщика за unit_from_qty — нам нужна за unit_to_qty.
        'unit_from_qty',
        'unit_to_qty',
        'unit_set_by_user_id',
        // Цена для 1С вручную (снабжение/менеджер): главнее цены поставщика и пересчёта единицы.
        'manual_price',
        'manual_price_by_user_id',
        'manual_price_at',
    ];

    /** Разбор отклонения (не оригинала): ждёт, принято к закупке, отклонено. */
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_ACCEPTED = 'accepted';

    public const REVIEW_REJECTED = 'rejected';

    protected $casts = [
        'tiers' => 'array',
        'original_texts' => 'array',
        'price' => 'decimal:4',
        'price_for_qty' => 'decimal:3',
        'unit_from_qty' => 'decimal:3',
        'unit_to_qty' => 'decimal:3',
        'manual_price' => 'decimal:4',
        'manual_price_at' => 'datetime',
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

    /** Действующие предложения — то же, что isUsable(), запросом. */
    public function scopeUsable(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('superseded', false)->whereIn('status', ['active', 'expiring'])
            ->where(fn ($w) => $w->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()));
    }

    /** Действует: не вытеснен и не истёк (и по статусу QuoteParts, и по сроку действия цены). */
    public function isUsable(): bool
    {
        return ! $this->superseded && in_array($this->status, ['active', 'expiring'], true) && ! $this->isExpired();
    }

    /**
     * Срок цены прошёл: статус QuoteParts меняется с опозданием, и цена «до 16.11» 17.11 ещё шла в выгрузку
     * как действующая (АТ-Электро, YuanQi, 17.11.2026).
     */
    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->lt(now()->startOfDay());
    }

    /**
     * Во сколько раз цена поставщика отличается от цены нашей единицы: снабжение указало «цена за unit_from_qty —
     * нужна за unit_to_qty» (OTI: за 1 ступень, позиция — комплект на 10 → ×10). 1 — пересчёта нет.
     */
    public function unitFactor(): float
    {
        $from = (float) ($this->unit_from_qty ?? 0);
        $to = (float) ($this->unit_to_qty ?? 0);

        return $from > 0 && $to > 0 ? $to / $from : 1.0;
    }

    public function hasUnitConversion(): bool
    {
        return abs($this->unitFactor() - 1.0) > 1e-9;
    }

    /**
     * Цена нашей единицы — в выгрузку 1С, выбор поставщика и сравнение: заданная вручную (UniSystem M16131, 20.11.2026:
     * разбор взял итог с доставкой и НДС), иначе цена поставщика с пересчётом единицы.
     */
    public function exportPrice(): ?float
    {
        if ($this->manual_price !== null) {
            return round((float) $this->manual_price, 4);
        }

        return $this->price !== null ? round((float) $this->price * $this->unitFactor(), 4) : null;
    }

    public function hasManualPrice(): bool
    {
        return $this->manual_price !== null;
    }

    /** «цена поставщика 901,47 EUR, в 1С — 746,64 (указана вручную)» — в комментарий 1С; null — не задана. */
    public function manualPriceNote(): ?string
    {
        if ($this->manual_price === null) {
            return null;
        }
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', ' '), '0'), ',');

        return 'цена поставщика '.($this->price !== null ? $num($this->price).' '.$this->currency : '—').', в 1С — '.$num($this->manual_price).' (указана вручную)';
    }

    /** «цена поставщика 62,90 USD за 1, пересчитано на 10» — в комментарий 1С; null — пересчёта нет. */
    public function unitConversionNote(): ?string
    {
        if (! $this->hasUnitConversion() || $this->price === null) {
            return null;
        }
        $num = fn ($v, $d) => rtrim(rtrim(number_format((float) $v, $d, ',', ' '), '0'), ',');

        return 'цена поставщика '.$num($this->price, 4).' '.$this->currency.' за '.$num($this->unit_from_qty, 3)
            .', пересчитано на '.$num($this->unit_to_qty, 3);
    }

    /**
     * Цена за кусок / упаковку / набор из нескольких единиц: «за 135 m (≈ 239,67 RUB за m)»; null — за единицу.
     */
    public function priceForNote(): ?string
    {
        $qty = $this->price_for_qty !== null ? (float) $this->price_for_qty : 0.0;
        if ($qty <= 1) {
            return null;
        }
        $num = fn ($v, $d) => rtrim(rtrim(number_format((float) $v, $d, ',', ' '), '0'), ',');
        $unit = trim((string) $this->price_for_unit) !== '' ? ' '.trim((string) $this->price_for_unit) : '';
        $per = $this->price !== null ? ' (≈ '.$num((float) $this->price / $qty, 2).' '.$this->currency.' за'.($unit !== '' ? $unit : ' ед.').')' : '';

        return 'цена за '.$num($qty, 3).$unit.$per;
    }
}
