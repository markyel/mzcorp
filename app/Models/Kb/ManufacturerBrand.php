<?php

namespace App\Models\Kb;

use App\Models\RequestItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManufacturerBrand extends Model
{
    protected $table = 'manufacturer_brands';

    protected $fillable = [
        'name',
        'aliases',
        'specialization_tags',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'aliases' => 'array',
        'specialization_tags' => 'array',
        'is_active' => 'boolean',
    ];

    public function skuPatterns(): HasMany
    {
        return $this->hasMany(BrandSkuPattern::class, 'brand_id');
    }

    public function parameterExtractors(): HasMany
    {
        return $this->hasMany(ParameterExtractor::class, 'brand_id');
    }

    public function requestItems(): HasMany
    {
        return $this->hasMany(RequestItem::class, 'manufacturer_brand_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
