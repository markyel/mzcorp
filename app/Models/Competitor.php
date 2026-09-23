<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Конкурент и то, что о нём говорят клиенты.
 *
 * @property string $name
 * @property ?float $rating
 */
class Competitor extends Model
{
    public const PLATFORMS = [
        'yandex_maps' => 'Яндекс.Карты',
        '2gis' => '2ГИС',
        'zoon' => 'Zoon',
        'google_maps' => 'Google Карты',
        'site' => 'Сайт компании',
        'other' => 'Другое',
    ];

    protected $fillable = [
        'name', 'site', 'platform', 'platform_url',
        'rating', 'ratings_count', 'reviews_count',
        'notes', 'is_active', 'created_by_user_id',
    ];

    protected $casts = [
        'rating' => 'float',
        'ratings_count' => 'integer',
        'reviews_count' => 'integer',
        'is_active' => 'boolean',
    ];

    public function reviews(): HasMany
    {
        return $this->hasMany(CompetitorReview::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(CompetitorInsight::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function platformLabel(): string
    {
        return self::PLATFORMS[$this->platform] ?? (string) $this->platform;
    }

    /** Витрина площадки одной строкой: «4,9 · 167 оценок · 60 отзывов». */
    public function scoreLine(): string
    {
        $parts = [];
        if ($this->rating !== null) {
            $parts[] = rtrim(rtrim(number_format($this->rating, 1, ',', ''), '0'), ',');
        }
        if ($this->ratings_count) {
            $parts[] = $this->ratings_count.' '.self::plural($this->ratings_count, 'оценка', 'оценки', 'оценок');
        }
        if ($this->reviews_count) {
            $parts[] = $this->reviews_count.' '.self::plural($this->reviews_count, 'отзыв', 'отзыва', 'отзывов');
        }

        return implode(' · ', $parts);
    }

    /** Русское склонение: локаль приложения английская, trans_choice тут не помощник. */
    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        if ($n >= 11 && $n <= 19) {
            return $many;
        }

        return match ($n % 10) {
            1 => $one,
            2, 3, 4 => $few,
            default => $many,
        };
    }

    /** Отзывы текстом — то, что уходит в разбор. */
    public function reviewsBrief(int $limit = 60): string
    {
        $lines = [];
        foreach ($this->reviews()->orderBy('id')->limit($limit)->get() as $review) {
            $head = $review->author ? $review->author.': ' : '';
            $rating = $review->rating !== null ? ' ['.rtrim(rtrim(number_format($review->rating, 1, ',', ''), '0'), ',').']' : '';
            $lines[] = '— '.$head.trim($review->quote).$rating;
        }

        return implode("\n", $lines);
    }
}
