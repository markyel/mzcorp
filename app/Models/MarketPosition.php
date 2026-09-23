<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сводка: где мы среди конкурентов.
 *
 * @property string $body
 */
class MarketPosition extends Model
{
    protected $fillable = [
        'body', 'snapshot', 'competitors_count', 'reviews_count', 'model', 'created_by_user_id',
    ];

    protected $casts = [
        'competitors_count' => 'integer',
        'reviews_count' => 'integer',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
