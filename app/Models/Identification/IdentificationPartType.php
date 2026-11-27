<?php

namespace App\Models\Identification;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тип детали в справочнике опознания: как узнать по названию позиции (pattern) и какие вопросы задать клиенту.
 * См. миграцию create_identification_hints_tables и IdentificationHintService.
 */
class IdentificationPartType extends Model
{
    protected $fillable = ['key', 'name', 'pattern', 'sort', 'is_active', 'source'];

    protected $casts = ['is_active' => 'boolean', 'sort' => 'integer'];

    public function hints(): HasMany
    {
        return $this->hasMany(IdentificationHint::class, 'part_type_id');
    }
}
