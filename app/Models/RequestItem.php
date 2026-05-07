<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция заявки (Phase 1.8b минимум).
 *
 * Заполняется RequestItemParsingService из вложений/тела/изображений письма.
 * KB-поля (identification_category_id, manufacturer_brand_id,
 * quality_assessment_*, equipment_unit_id) и связь с каталогом — Phase 2.
 *
 * Поля parsed_name / parsed_article / is_active используются методом
 * RequestItemParsingService::filterNewItems для дедупликации.
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
        'supplier_note',
        'data_source',
        'status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'parsed_qty' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }
}
