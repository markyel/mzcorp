<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Разбор маркетингового материала по медиапрофилю.
 *
 * @property string $kind
 * @property array<int, array<string, mixed>> $issues
 */
class MediaProfileReview extends Model
{
    public const KINDS = [
        'news' => 'Новость',
        'mailing' => 'Рассылка',
        'booklet' => 'Буклет',
        'other' => 'Другое',
    ];

    protected $fillable = [
        'kind', 'title', 'source_text', 'rewritten_text', 'issues',
        'profile_snapshot', 'model', 'created_by_user_id',
    ];

    protected $casts = ['issues' => 'array'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** Нарушений обязательных требований — то, из-за чего материал не выпускают. */
    public function strictCount(): int
    {
        return count(array_filter($this->issues ?? [], fn ($i) => ($i['severity'] ?? '') === 'strict'));
    }

    public function softCount(): int
    {
        return count($this->issues ?? []) - $this->strictCount();
    }
}
