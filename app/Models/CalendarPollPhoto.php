<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Фото опроса: оригинал (path) и копии для галереи — миниатюра и просмотр. */
class CalendarPollPhoto extends Model
{
    protected $fillable = [
        'calendar_poll_id',
        'original_name',
        'path',
        'preview_path',
        'thumb_path',
        'error',
        'width',
        'height',
        'sort',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'sort' => 'integer',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(CalendarPoll::class, 'calendar_poll_id');
    }

    /** Копии для галереи готовы (их делает фоновая задача после загрузки). */
    public function isReady(): bool
    {
        return $this->thumb_path !== null && $this->preview_path !== null;
    }

    public function votes(): HasMany
    {
        return $this->hasMany(CalendarPollVote::class);
    }
}
