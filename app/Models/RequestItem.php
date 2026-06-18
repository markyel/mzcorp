<?php

namespace App\Models;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\ManufacturerBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Позиция заявки.
 *
 * Phase 1.8b: parsed_*-поля из RequestItemParsingService.
 * Phase 2.0: KB-поля — identification_category_id, manufacturer_brand_id,
 *   equipment_unit_id, quality_assessment_status/payload (заполняются
 *   QualityAssessmentService через ResolveKbJob после persist).
 */
class RequestItem extends Model
{
    protected $fillable = [
        'request_id',
        'position',
        'parsed_name',
        'parsed_brand',
        'parsed_article',
        'parsed_qty',
        'parsed_unit',
        // Мерные позиции: вторая физическая размерность (длина/масса/объём
        // на единицу qty). Например, поручень 43.560 м × 2 шт →
        // parsed_qty=2, parsed_unit='шт.', parsed_length=43.560, parsed_length_unit='м'.
        // Менеджер может переключить billing_unit='м' для пересчёта total
        // как price × qty × length (см. effectiveQty()).
        'parsed_length',
        'parsed_length_unit',
        'billing_unit',
        // Phase 2.0+: coarse-категория от парсера (одна из 19 значений
        // App\Constants\CoarseCategories::ALL). Заполняется ParseItemsPrompt v5,
        // используется CategoryRefinementService для активации LLM-pathway.
        'category',
        'supplier_note',
        'data_source',
        // Phase 2: привязка к фото-вложению email'а, из которого Vision-парсер
        // извлёк позицию (см. add_image_attachment_id_to_request_items_table).
        'image_attachment_id',
        // Phase 2 use-case A/B: привязка позиции к товару каталога. Заполняется
        // CatalogResolutionService (M-SKU resolve / article-match).
        'catalog_item_id',
        'status',
        'is_active',
        // Phase 2.0 KB resolutions:
        'identification_category_id',
        'manufacturer_brand_id',
        'equipment_unit_id',
        'quality_assessment_status',
        'quality_assessment_payload',
        // Phase reply-suggestion: позиция предложена парсером из reply'я,
        // ждёт подтверждения менеджера (см. RequestItemPersister).
        'suggestion_status',
        'suggestion_confidence',
        'suggestion_source_email_id',
        // Snapshot входной сложности позиции (см. App\Enums\MatchPath).
        // Присваивается в RequestItemObserver через
        // RequestComplexityService::detectAndStoreItemPath из
        // `payload.catalog_match.method` (A/B/C/manual_link). После
        // ручной правки менеджером значение не меняется — это
        // характеристика того, как заявка пришла.
        'match_path',
        // Список дублей из исходного парсинга, схлопнутых dedupeWithinList
        // в эту позицию-победителя. Заполняется RequestItemPersister при
        // первичном создании; не меняется при дальнейших правках. Каждый
        // элемент: {source, name, article, qty, reason, dedup_key}.
        'parsing_merged_from',
        // Письмо-источник, из которого позиция была спаршена. Заполняется
        // RequestItemPersister (всегда = message->id), бэкфиллится по времени
        // для исторических данных. Драйвит провенанс при ручном разъединении
        // заявки (RequestSplitService / SplitDialog).
        'source_email_message_id',
    ];

    protected function casts(): array
    {
        return [
            'parsed_qty' => 'decimal:3',
            'parsed_length' => 'decimal:3',
            'is_active' => 'boolean',
            'quality_assessment_payload' => 'array',
            'suggestion_confidence' => 'float',
            'suggestion_source_email_id' => 'integer',
            'source_email_message_id' => 'integer',
            'match_path' => \App\Enums\MatchPath::class,
            'parsing_merged_from' => 'array',
        ];
    }

    /**
     * Pending = создана парсером из reply, ждёт подтверждения.
     */
    public function isPendingSuggestion(): bool
    {
        return $this->suggestion_status === 'pending';
    }

    /**
     * Мерная позиция = парсер вытащил вторую размерность (например,
     * «2 шт × 43.56 м» для поручня). Используется для UI-highlight'а и
     * для активации editable billing_unit dropdown'а.
     */
    public function isMeasured(): bool
    {
        return $this->parsed_length !== null
            && (float) $this->parsed_length > 0
            && $this->parsed_length_unit !== null;
    }

    /**
     * Единица, по которой считается total. По умолчанию = parsed_unit.
     * Менеджер может переопределить через billing_unit (обычно меняет
     * на parsed_length_unit, чтобы цена per-meter × длина).
     */
    public function effectiveUnit(): ?string
    {
        return $this->billing_unit ?: $this->parsed_unit;
    }

    /**
     * Эффективное количество для расчёта total:
     *   - если billing_unit совпадает с parsed_length_unit → qty × length
     *     (цена за метр, нужно посчитать общую длину);
     *   - иначе → просто qty (цена за штуку/комплект, длина игнорируется).
     */
    public function effectiveQty(): float
    {
        $qty = (float) ($this->parsed_qty ?? 0);
        if ($qty <= 0) {
            return 0.0;
        }

        if ($this->isMeasured()
            && $this->effectiveUnit() !== null
            && mb_strtolower(trim((string) $this->effectiveUnit()))
                === mb_strtolower(trim((string) $this->parsed_length_unit))) {
            return $qty * (float) $this->parsed_length;
        }

        return $qty;
    }

    /**
     * Total = catalogItem->price × effectiveQty(). Null если нет цены
     * или нет qty. Точно та же формула что в _item-row.blade.php / _position-card.blade.php
     * — централизована здесь, чтобы UI и сервисы считали одинаково.
     */
    public function total(): ?float
    {
        $ci = $this->catalogItem;
        if (! $ci || $ci->price === null) {
            return null;
        }
        $effQty = $this->effectiveQty();
        if ($effQty <= 0) {
            return null;
        }
        return (float) $ci->price * $effQty;
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    /**
     * Письмо, из которого позиция была спаршена (провенанс). Может быть null
     * для позиций до ввода колонки/бэкфилла. Используется RequestSplitService
     * и бейджем «источник N поз.» в карточке заявки.
     */
    public function sourceEmail(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'source_email_message_id');
    }

    /**
     * Резолвленная KB-категория (Phase 2.0). Null если не разобрано или
     * QualityAssessment вернул `not_covered` / `assessment_failed`.
     *
     * Метод намеренно называется `kbCategory`, а не `category`, потому что в
     * таблице есть строковая колонка `category` (coarse-категория от парсера),
     * которая в `$fillable`. Имя `category` совпадало с relation → Eloquent
     * в `getAttribute()` отдавал строку из `$attributes` и relation был
     * затенён (eager-load работал, но property-access через `$item->category`
     * возвращал строку и `->slug` валился ErrorException → 500 в карточке).
     */
    public function kbCategory(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'identification_category_id');
    }

    /**
     * Резолвленный KB-бренд (Phase 2.0).
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(ManufacturerBrand::class, 'manufacturer_brand_id');
    }

    /**
     * Фото-вложение письма, из которого Vision извлёк эту позицию (если есть).
     * Null для позиций из текста/документов или если Vision вернул кривой
     * image_index. Используется в карточке заявки для thumbnail-превью.
     */
    public function imageAttachment(): BelongsTo
    {
        return $this->belongsTo(EmailAttachment::class, 'image_attachment_id');
    }

    /**
     * Все фото, привязанные к позиции (many-to-many). Pivot `is_main`
     * помечает главное (оно же дублируется в image_attachment_id для
     * thumbnail-превью), `sort_order` — порядок в галерее. Одно вложение
     * может быть привязано к нескольким позициям (общий план).
     *
     * Сортировка: главное первым, затем по sort_order/id.
     */
    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(
            EmailAttachment::class,
            'request_item_photos',
            'request_item_id',
            'email_attachment_id',
        )
            ->withPivot(['is_main', 'sort_order'])
            ->withTimestamps()
            ->orderByDesc('request_item_photos.is_main')
            ->orderBy('request_item_photos.sort_order')
            ->orderBy('request_item_photos.id');
    }

    /**
     * Каталожная позиция (Phase 2 use-case A/B). Заполнено если
     * `CatalogResolutionService` нашёл match по sku или brand_article.
     * Null — позиция не сматчена.
     */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    /** Запросы цены поставщикам по этой позиции (Фаза 3.2/3.3). */
    public function supplierInquiryItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\SupplierInquiryItem::class);
    }

    /**
     * Уточняющие вопросы по этой позиции (Foundation §6.2).
     * Может быть несколько — каждый batch формирует свой ряд.
     */
    public function clarificationQuestions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ClarificationQuestion::class);
    }

    /**
     * Строки исходящих КП/счетов, привязанные к этой позиции
     * (через OutboundQuoteItemMatcher). Может быть несколько — позиция
     * могла переотправляться в разных КП, либо одна позиция заявки
     * раскладывается на несколько строк КП (split delivery / аналоги).
     */
    public function outboundQuoteItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OutboundQuoteItem::class, 'matched_request_item_id');
    }
}
