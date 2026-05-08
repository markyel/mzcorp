<?php

namespace App\Models\Kb;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IdentificationRule extends Model
{
    protected $table = 'identification_rules';

    protected $fillable = [
        'category_id',
        'applies_to_brands',
        'description',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'applies_to_brands' => 'array',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'category_id');
    }

    public function alternatives(): HasMany
    {
        return $this->hasMany(IdentificationRuleAlternative::class, 'rule_id');
    }

    /**
     * Бренды, к которым применяется правило (резолв jsonb-поля applies_to_brands в коллекцию).
     * null/пустой массив → правило универсальное (все бренды).
     */
    public function getAppliesBrandsAttribute(): Collection
    {
        $ids = $this->applies_to_brands;
        if (empty($ids) || !is_array($ids)) {
            return new Collection();
        }
        return ManufacturerBrand::whereIn('id', $ids)->get();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
