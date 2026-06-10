<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись раздела «Обновления» (changelog).
 *
 * Лента важных для участников изменений системы. Тело — markdown
 * (рендерится через Str::markdown). Доступна на чтение всем ролям без
 * разделения; публикация — privileged (head_of_sales/director/admin).
 *
 * Опубликованной считается запись с is_published=true И published_at в
 * прошлом (scopePublished). Бейдж непрочитанного — по users.updates_seen_at.
 */
class ChangelogEntry extends Model
{
    protected $fillable = [
        'title',
        'body',
        'is_published',
        'published_at',
        'author_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * Опубликованные и видимые сейчас записи (для ленты и дашборда).
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Кол-во непрочитанных пользователем опубликованных записей.
     * Непрочитанные = опубликованные позже updates_seen_at (или все, если
     * пользователь раздел ещё не открывал).
     */
    public static function unreadCountFor(User $user): int
    {
        $seen = $user->updates_seen_at;

        return static::query()
            ->published()
            ->when($seen, fn (Builder $q) => $q->where('published_at', '>', $seen))
            ->count();
    }
}
