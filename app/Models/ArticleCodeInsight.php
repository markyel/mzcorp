<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Разбор кода позиции: настоящий ли это OEM-артикул и какому производителю
 * принадлежит формат (см. ArticleInsightService).
 */
class ArticleCodeInsight extends Model
{
    public const KIND_OEM = 'oem';
    public const KIND_MODEL = 'model';
    public const KIND_INTERNAL = 'internal';
    public const KIND_FRAGMENT = 'fragment';
    public const KIND_UNKNOWN = 'unknown';

    protected $fillable = [
        'code_normalized',
        'raw_sample',
        'kind',
        'manufacturer_name',
        'manufacturer_brand_id',
        'confidence',
        'series_hint',
        'source',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'analyzed_at' => 'datetime',
        ];
    }

    public function manufacturerBrand(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Kb\ManufacturerBrand::class, 'manufacturer_brand_id');
    }

    /** Человекочитаемая метка вида кода. */
    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_OEM => 'OEM-артикул',
            self::KIND_MODEL => 'маркировка модели',
            self::KIND_INTERNAL => 'код клиента',
            self::KIND_FRAGMENT => 'обрывок',
            default => '—',
        };
    }
}
