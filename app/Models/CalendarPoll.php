<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Опрос «Фото для календаря»: галерея фото, список участников, выбор каждого (picks фото). */
class CalendarPoll extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'title',
        'description',
        'picks',
        'winners',
        'status',
        'closes_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'picks' => 'integer',
        'winners' => 'integer',
        'closes_at' => 'datetime',
    ];

    public function photos(): HasMany
    {
        return $this->hasMany(CalendarPollPhoto::class)->orderBy('sort')->orderBy('id');
    }

    public function voters(): HasMany
    {
        return $this->hasMany(CalendarPollVoter::class)->orderBy('name');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Принимает голоса: открыт и срок (если задан) не прошёл. */
    public function acceptsVotes(): bool
    {
        return $this->status === self::STATUS_OPEN && ($this->closes_at === null || $this->closes_at->isFuture());
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->status === self::STATUS_DRAFT => 'черновик',
            $this->status === self::STATUS_CLOSED => 'завершён',
            ! $this->acceptsVotes() => 'срок вышел',
            default => 'идёт голосование',
        };
    }
}
