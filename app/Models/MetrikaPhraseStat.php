<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Визиты и достижения целей за день по фразе Директа и поисковому запросу.
 * См. App\Services\Metrika\MetrikaStatsService::pullPhrases.
 *
 * @property array<string, int>|null $goals
 */
class MetrikaPhraseStat extends Model
{
    protected $fillable = [
        'date', 'counter_id', 'campaign_id', 'condition', 'search_query', 'row_hash', 'visits', 'goals',
    ];

    protected $casts = [
        'date' => 'date',
        'counter_id' => 'integer',
        'campaign_id' => 'integer',
        'visits' => 'integer',
        'goals' => 'array',
    ];

    /** Достижения цели за день. */
    public function reaches(int $goalId): int
    {
        return (int) (($this->goals ?? [])[(string) $goalId] ?? 0);
    }
}
