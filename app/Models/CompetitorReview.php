<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отзыв о конкуренте — дословно, со ссылкой на источник.
 *
 * @property string $quote
 */
class CompetitorReview extends Model
{
    protected $fillable = [
        'competitor_id', 'source', 'source_url', 'author',
        'quote', 'rating', 'posted_on', 'created_by_user_id',
    ];

    protected $casts = [
        'rating' => 'float',
        'posted_on' => 'date',
    ];

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
