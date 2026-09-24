<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Канал связи с аудиторией: где мы говорим.
 *
 * @property string $kind
 */
class MediaChannel extends Model
{
    public const KINDS = [
        'direct' => 'Яндекс.Директ',
        'mailing' => 'Рассылка',
        'email_block' => 'Блок в письмах',
        'telegram' => 'Telegram',
        'zen' => 'Дзен',
        'vk' => 'ВКонтакте',
        'site' => 'Сайт',
        'other' => 'Другое',
    ];

    protected $fillable = [
        'name', 'kind', 'url', 'handle', 'owner_user_id',
        'posts_per_week', 'is_active', 'notes',
    ];

    protected $casts = [
        'posts_per_week' => 'integer',
        'is_active' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(MediaPublication::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
