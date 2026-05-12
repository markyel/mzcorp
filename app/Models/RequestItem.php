<?php

namespace App\Models;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\ManufacturerBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected function casts(): array
    {
        return [
            'parsed_qty' => 'decimal:3',
            'is_active' => 'boolean',
            'quality_assessment_payload' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
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
     * Каталожная позиция (Phase 2 use-case A/B). Заполнено если
     * `CatalogResolutionService` нашёл match по sku или brand_article.
     * Null — позиция не сматчена.
     */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }
}
