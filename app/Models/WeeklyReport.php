<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Замороженный снимок еженедельного отчёта менеджера. См.
 * WeeklyManagerReportService (расчёт) и reports:weekly-generate (генерация).
 *
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property array $data
 * @property ?\Illuminate\Support\Carbon $emailed_at
 */
class WeeklyReport extends Model
{
    protected $fillable = [
        'user_id', 'period_start', 'period_end', 'data', 'emailed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'data' => 'array',
            'emailed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
