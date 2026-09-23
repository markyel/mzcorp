<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Памятка по фирменному стилю — профиль, изложенный для человека.
 *
 * @property string $body
 */
class MediaProfileGuide extends Model
{
    protected $fillable = [
        'body', 'profile_snapshot', 'entries_count', 'model', 'created_by_user_id',
    ];

    protected $casts = ['entries_count' => 'integer'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Актуальна ли: профиль с момента сборки не менялся. */
    public function isFresh(): bool
    {
        return trim((string) $this->profile_snapshot) === trim(MediaProfileEntry::asBrief());
    }
}
