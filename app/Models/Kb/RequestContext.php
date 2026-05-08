<?php

namespace App\Models\Kb;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Документ 2: контекст заявки целиком.
 *
 * Хранит надзаявочную информацию: единицы оборудования, упомянутые источники,
 * метаданные. Заполняется AnalyzeRequestContextJob.
 */
class RequestContext extends Model
{
    protected $table = 'request_context';

    protected $fillable = [
        'request_id',
        'equipment_units',
        'mentioned_sources',
        'metadata',
        'analysis_status',
        'error_message',
        'llm_raw_response',
        'llm_model_version',
        'analyzed_at',
    ];

    protected $casts = [
        'equipment_units' => 'array',
        'mentioned_sources' => 'array',
        'metadata' => 'array',
        'llm_raw_response' => 'array',
        'analyzed_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Request::class);
    }

    /**
     * Найти единицу оборудования по локальному ID (например, "unit_1").
     */
    public function findUnit(string $unitId): ?array
    {
        foreach ($this->equipment_units ?? [] as $unit) {
            if (($unit['id'] ?? null) === $unitId) {
                return $unit;
            }
        }
        return null;
    }
}
