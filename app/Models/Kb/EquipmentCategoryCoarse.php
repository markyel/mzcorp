<?php

namespace App\Models\Kb;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentCategoryCoarse extends Model
{
    protected $table = 'equipment_category_coarse';

    protected $fillable = [
        'category_id',
        'coarse_category',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'category_id');
    }
}
