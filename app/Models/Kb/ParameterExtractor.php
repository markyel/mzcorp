<?php

namespace App\Models\Kb;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParameterExtractor extends Model
{
    protected $table = 'parameter_extractors';

    protected $fillable = [
        'category_id',
        'brand_id',
        'source_field',
        'triggered_by_sku_pattern_id',
        'rules',
        'pre_normalize_rules',
        'post_extract_rules',
        'test_examples',
        'priority',
        'is_active',
        'description',
    ];

    protected $casts = [
        'rules' => 'array',
        'pre_normalize_rules' => 'array',
        'post_extract_rules' => 'array',
        'test_examples' => 'array',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ManufacturerBrand::class, 'brand_id');
    }

    public function triggeredBySkuPattern(): BelongsTo
    {
        return $this->belongsTo(BrandSkuPattern::class, 'triggered_by_sku_pattern_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
