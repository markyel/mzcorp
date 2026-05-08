<?php

namespace App\Models\Kb;

use App\Models\RequestItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentCategory extends Model
{
    protected $table = 'equipment_categories';

    protected $fillable = [
        'slug',
        'name',
        'compatible_equipment',
        'is_industry_specific',
        'synonyms',
        'description',
        'is_active',
    ];

    protected $casts = [
        'compatible_equipment' => 'array',
        'synonyms' => 'array',
        'is_industry_specific' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function coarseCategories(): HasMany
    {
        return $this->hasMany(EquipmentCategoryCoarse::class, 'category_id');
    }

    public function identificationRules(): HasMany
    {
        return $this->hasMany(IdentificationRule::class, 'category_id');
    }

    public function parameterExtractors(): HasMany
    {
        return $this->hasMany(ParameterExtractor::class, 'category_id');
    }

    public function requestItems(): HasMany
    {
        return $this->hasMany(RequestItem::class, 'identification_category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
