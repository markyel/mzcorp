<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ежемесячный отчёт об оказанных маркетинговых услугах по форме Приложения № 1
 * к договору. payload — заполненная форма (разделы, показатели, план на
 * следующий месяц): отчёт остаётся таким, каким его сдали, даже если журнал
 * потом правили.
 */
class MarketingReport extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINAL = 'final';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Черновик',
        self::STATUS_FINAL => 'Сдан',
    ];

    protected $fillable = [
        'period',
        'status',
        'payload',
        'finalized_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'payload' => 'array',
            'finalized_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** «Сентябрь 2026» — для заголовков и имени файла. */
    public function periodLabel(): string
    {
        return self::monthLabel($this->period);
    }

    public static function monthLabel(Carbon|string|null $period): string
    {
        $c = $period instanceof Carbon ? $period : Carbon::parse($period ?: now());
        $months = [1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
            'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

        return $months[$c->month].' '.$c->year;
    }
}
