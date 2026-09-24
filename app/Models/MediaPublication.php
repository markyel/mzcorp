<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Материал и его судьба: от черновика до ссылки на публикацию.
 *
 * @property string $status
 */
class MediaPublication extends Model
{
    public const STATUSES = [
        'idea' => 'Идея',
        'draft' => 'Черновик',
        'in_review' => 'На проверке',
        'approved' => 'Согласовано',
        'published' => 'Опубликовано',
        'rejected' => 'Отклонено',
    ];

    protected $fillable = [
        'media_topic_id', 'media_channel_id', 'title', 'body', 'status',
        'planned_for', 'published_at', 'url', 'model',
        'media_profile_review_id', 'created_by_user_id',
    ];

    protected $casts = [
        'planned_for' => 'date',
        'published_at' => 'datetime',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(MediaTopic::class, 'media_topic_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(MediaChannel::class, 'media_channel_id');
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(MediaProfileReview::class, 'media_profile_review_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
