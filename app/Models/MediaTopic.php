<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Тема публикаций: о чём говорим и как часто.
 *
 * @property string $source
 */
class MediaTopic extends Model
{
    public const SOURCES = [
        'manual' => 'Пишем сами',
        'catalog_new' => 'Новые позиции каталога',
        'catalog_price' => 'Снижение цен',
        'stock_arrivals' => 'Поступления на склад',
        'request_tips' => 'Советы по оформлению заявок',
        'news' => 'Новости компании',
    ];

    public const WEEKDAYS = [
        1 => 'понедельник',
        2 => 'вторник',
        3 => 'среда',
        4 => 'четверг',
        5 => 'пятница',
        6 => 'суббота',
        7 => 'воскресенье',
    ];

    protected $fillable = [
        'title', 'brief', 'source', 'cadence_days', 'next_due_on',
        'publish_weekday', 'is_active', 'created_by_user_id',
    ];

    protected $casts = [
        'cadence_days' => 'integer',
        'next_due_on' => 'date',
        'publish_weekday' => 'integer',
        'is_active' => 'boolean',
    ];

    public function publications(): HasMany
    {
        return $this->hasMany(MediaPublication::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    /** Регулярная тема, которой пора: срок наступил или прошёл. */
    public function isDue(): bool
    {
        return $this->is_active
            && $this->cadence_days
            && $this->next_due_on !== null
            && $this->next_due_on->startOfDay()->lte(now()->startOfDay());
    }

    public function weekdayLabel(): ?string
    {
        return self::WEEKDAYS[$this->publish_weekday] ?? null;
    }

    /**
     * Следующий срок после публикации: шаг регулярности, а потом — подтяжка к
     * своему дню недели. Считаем от текущего срока, а не от «сегодня»: иначе
     * расписание уезжает каждый раз, когда материал выпустили с опозданием.
     */
    public function nextDueAfterPublish(): Carbon
    {
        $base = $this->next_due_on && $this->next_due_on->isFuture() ? $this->next_due_on : now();
        $next = $base->copy()->addDays(max(1, (int) $this->cadence_days));

        return $this->alignToWeekday($next);
    }

    /** Ближайший «свой» день недели, начиная с указанной даты. */
    public function alignToWeekday(Carbon $date): Carbon
    {
        if (! $this->publish_weekday) {
            return $date;
        }

        $date = $date->copy()->startOfDay();
        // ISO: 1 — понедельник, 7 — воскресенье.
        $shift = ((int) $this->publish_weekday - (int) $date->isoWeekday() + 7) % 7;

        return $date->addDays($shift);
    }

    public function cadenceLabel(): string
    {
        return match (true) {
            ! $this->cadence_days => 'по случаю',
            $this->cadence_days === 1 => 'ежедневно',
            $this->cadence_days === 7 => 'раз в неделю',
            $this->cadence_days === 14 => 'раз в две недели',
            $this->cadence_days === 30 => 'раз в месяц',
            default => 'раз в '.$this->cadence_days.' дн.',
        };
    }
}
