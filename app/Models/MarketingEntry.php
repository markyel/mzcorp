<?php

namespace App\Models;

use App\Enums\MarketingSection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Запись рабочего места «Маркетинг»: пункт плана, заметка или запись журнала
 * выполненных работ. Один тип записи на три сущности — потому что у них общий
 * рубрикатор (раздел формы отчёта) и общий отчётный период, и из-за этого
 * ежемесячный отчёт собирается запросом, а не ручным переносом.
 */
class MarketingEntry extends Model
{
    public const KIND_PLAN = 'plan';

    public const KIND_WORK = 'work';

    public const KIND_NOTE = 'note';

    public const KINDS = [
        self::KIND_PLAN => 'План',
        self::KIND_WORK => 'Работа',
        self::KIND_NOTE => 'Заметка',
    ];

    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    public const STATUS_DROPPED = 'dropped';

    public const STATUSES = [
        self::STATUS_PLANNED => 'Запланировано',
        self::STATUS_IN_PROGRESS => 'В работе',
        self::STATUS_DONE => 'Сделано',
        self::STATUS_DROPPED => 'Снято',
    ];

    /** 1 — высокий, 2 — обычный, 3 — низкий. */
    public const PRIORITIES = [1 => 'Высокий', 2 => 'Обычный', 3 => 'Низкий'];

    protected $fillable = [
        'kind',
        'section',
        'period',
        'title',
        'body',
        'metrics',
        'status',
        'priority',
        'happened_on',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'happened_on' => 'date',
            'metrics' => 'array',
            'priority' => 'int',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sectionEnum(): ?MarketingSection
    {
        return MarketingSection::tryFrom((string) $this->section);
    }

    public function sectionLabel(): string
    {
        return $this->sectionEnum()?->shortLabel() ?? '—';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Первый день месяца — канонический вид отчётного периода. */
    public static function normalizePeriod(Carbon|string|null $period): Carbon
    {
        $c = $period instanceof Carbon ? $period->copy() : Carbon::parse($period ?: now());

        return $c->startOfMonth()->startOfDay();
    }

    public function scopeForPeriod(Builder $q, Carbon|string $period): Builder
    {
        return $q->whereDate('period', self::normalizePeriod($period)->toDateString());
    }

    public function scopeOfKind(Builder $q, string $kind): Builder
    {
        return $q->where('kind', $kind);
    }

    /** Попадает ли запись в отчёт: снятое и незавершённое в отчёт не идут. */
    public function countsAsDone(): bool
    {
        return $this->kind === self::KIND_WORK || $this->status === self::STATUS_DONE;
    }
}
