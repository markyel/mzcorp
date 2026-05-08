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
     */
    public function category(): BelongsTo
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
}
