<?php

namespace App\Models\Identification;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Вопрос клиенту для опознания детали данного типа. status: active — подсказка; candidate — найден в письмах
 * менеджеров, станет подсказкой после IdentificationLearner::PROMOTE_AFTER разных писем; disabled — выключен.
 */
class IdentificationHint extends Model
{
    public const ACTIVE = 'active';

    public const CANDIDATE = 'candidate';

    public const DISABLED = 'disabled';

    protected $fillable = ['part_type_id', 'label', 'question', 'goal', 'status', 'source', 'asked_count', 'last_asked_at', 'sort'];

    protected $casts = ['asked_count' => 'integer', 'sort' => 'integer', 'last_asked_at' => 'datetime'];

    public function partType(): BelongsTo
    {
        return $this->belongsTo(IdentificationPartType::class, 'part_type_id');
    }
}
