<?php

namespace App\Models\Kb;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IdentificationParameter extends Model
{
    protected $table = 'identification_parameters';

    protected $fillable = [
        'slug',
        'name',
        'value_type',
        'allowed_values',
        'aliases',
        'unit',
        'question_template',
        'description',
        'is_active',
    ];

    protected $casts = [
        'allowed_values' => 'array',
        'aliases' => 'array',
        'is_active' => 'boolean',
    ];

    public function clarificationEvents(): HasMany
    {
        return $this->hasMany(ClarificationQuestionEvent::class, 'parameter_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
