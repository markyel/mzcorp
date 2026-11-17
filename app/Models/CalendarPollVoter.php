<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Участник опроса. Входит по личной одноразовой ссылке (в базе — sha256 токена) или как сотрудник
 * MyLift с тем же адресом. После входа устройство помнится зашифрованной cookie.
 */
class CalendarPollVoter extends Model
{
    protected $fillable = [
        'calendar_poll_id',
        'name',
        'email',
        'user_id',
        'token_hash',
        'token_expires_at',
        'token_used_at',
        'invited_at',
        'last_seen_at',
        'voted_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'token_used_at' => 'datetime',
        'invited_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'voted_at' => 'datetime',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(CalendarPoll::class, 'calendar_poll_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(CalendarPollVote::class);
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->voted_at !== null => 'выбрал',
            $this->last_seen_at !== null => 'открыл, не выбрал',
            $this->invited_at !== null => 'приглашён',
            default => 'не приглашён',
        };
    }
}
