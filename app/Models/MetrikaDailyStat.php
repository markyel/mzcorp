<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Визиты и достижения целей за день: по кампании Директа или по источнику
 * трафика. См. App\Services\Metrika\MetrikaStatsService.
 *
 * @property array<string, int>|null $goals
 */
class MetrikaDailyStat extends Model
{
    public const KIND_DIRECT_CAMPAIGN = 'direct_campaign';

    public const KIND_SOURCE = 'source';

    protected $fillable = [
        'date', 'counter_id', 'kind', 'key', 'name',
        'visits', 'bounce_rate', 'page_depth', 'avg_visit_seconds', 'goals',
    ];

    protected $casts = [
        'date' => 'date',
        'counter_id' => 'integer',
        'visits' => 'integer',
        'bounce_rate' => 'float',
        'page_depth' => 'float',
        'avg_visit_seconds' => 'integer',
        'goals' => 'array',
    ];

    /** Достижения цели за день. */
    public function reaches(int $goalId): int
    {
        return (int) (($this->goals ?? [])[(string) $goalId] ?? 0);
    }
}
