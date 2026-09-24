<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

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
        'auto_publish', 'last_posted_at', 'last_error', 'mirror_of_channel_id',
    ];

    protected $casts = [
        'posts_per_week' => 'integer',
        'is_active' => 'boolean',
        'auto_publish' => 'boolean',
        'last_posted_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(MediaPublication::class);
    }

    /** Канал-источник: этот канал повторяет его посты (Дзен ← Telegram). */
    public function mirrorOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'mirror_of_channel_id');
    }

    /** Площадки, которые забирают посты отсюда. */
    public function mirrors(): HasMany
    {
        return $this->hasMany(self::class, 'mirror_of_channel_id');
    }

    /** Свой материал каналу не пишут: он повторяет чужой. */
    public function isMirror(): bool
    {
        return $this->mirror_of_channel_id !== null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected $hidden = ['encrypted_secrets'];

    /**
     * Доступ к площадке: токен и идентификатор места публикации.
     * Хранится шифрованным, как креды в разделе «Доступы».
     *
     * @return array<string, string>
     */
    public function secrets(): array
    {
        if (! $this->encrypted_secrets) {
            return [];
        }

        try {
            $parsed = json_decode(Crypt::decryptString($this->encrypted_secrets), true);

            return is_array($parsed) ? $parsed : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function writeSecrets(array $secrets): void
    {
        $clean = [];
        foreach ($secrets as $k => $v) {
            $v = is_string($v) ? trim($v) : $v;
            if ($v !== null && $v !== '') {
                $clean[$k] = $v;
            }
        }

        $this->encrypted_secrets = $clean === []
            ? null
            : Crypt::encryptString(json_encode($clean, JSON_UNESCAPED_UNICODE));
    }

    public function secret(string $key): ?string
    {
        $v = $this->secrets()[$key] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Канал, в который система умеет публиковать сама. */
    public function isPostable(): bool
    {
        return in_array($this->kind, ['vk', 'telegram'], true);
    }

    /** Доступ настроен: есть токен и адрес места публикации. */
    public function isConnected(): bool
    {
        return match ($this->kind) {
            'vk' => $this->secret('access_token') !== null && $this->secret('owner_id') !== null,
            'telegram' => $this->secret('bot_token') !== null && $this->secret('chat_id') !== null,
            default => false,
        };
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
