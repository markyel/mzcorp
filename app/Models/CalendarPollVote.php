<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Одно выбранное участником фото и его место в шестёрке (1 — лучшее). */
class CalendarPollVote extends Model
{
    protected $fillable = [
        'calendar_poll_voter_id',
        'calendar_poll_photo_id',
        'rank',
    ];

    protected $casts = ['rank' => 'integer'];

    public function voter(): BelongsTo
    {
        return $this->belongsTo(CalendarPollVoter::class, 'calendar_poll_voter_id');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(CalendarPollPhoto::class, 'calendar_poll_photo_id');
    }
}
