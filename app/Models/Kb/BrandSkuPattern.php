<?php

namespace App\Models\Kb;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandSkuPattern extends Model
{
    protected $table = 'brand_sku_patterns';

    protected $fillable = [
        'brand_id',
        'pattern',
        'series_name',
        'description',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ManufacturerBrand::class, 'brand_id');
    }

    public function parameterExtractors(): HasMany
    {
        return $this->hasMany(ParameterExtractor::class, 'triggered_by_sku_pattern_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
