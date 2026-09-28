<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Цель счётчика Метрики — справочник, чтобы в отчётах были названия, а не номера.
 * См. App\Services\Metrika\MetrikaStatsService.
 */
class MetrikaGoal extends Model
{
    protected $fillable = ['counter_id', 'goal_id', 'name', 'type'];

    protected $casts = [
        'counter_id' => 'integer',
        'goal_id' => 'integer',
    ];
}
