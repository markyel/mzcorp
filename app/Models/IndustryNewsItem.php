<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Новость отрасли из RSS-ленты — сырьё для еженедельного дайджеста.
 * См. App\Services\Marketing\IndustryNewsFeed.
 */
class IndustryNewsItem extends Model
{
    protected $fillable = ['guid', 'title', 'description', 'link', 'published_at', 'feed_url'];

    protected $casts = [
        'published_at' => 'datetime',
    ];
}
