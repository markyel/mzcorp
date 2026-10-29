<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Публикация о нас в СМИ — по релизу или сама по себе.
 */
class PressMention extends Model
{
    protected $fillable = [
        'press_release_id', 'press_outlet_id', 'url', 'title', 'published_on', 'notes', 'created_by_user_id',
    ];

    protected $casts = [
        'published_on' => 'date',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(PressRelease::class, 'press_release_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PressOutlet::class, 'press_outlet_id');
    }
}
