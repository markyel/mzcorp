<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись записной книжки маркетинга: подрядчик или площадка по направлению
 * («Календари» → типография, «Выставка» → АО «ВДНХ»).
 *
 * Адресов может быть несколько — храним массивом, а не строкой с переводами
 * строки, чтобы поиск и копирование работали по каждому отдельно.
 */
class MarketingContact extends Model
{
    protected $fillable = [
        'topic',
        'organization',
        'contact_person',
        'emails',
        'phone',
        'folder_path',
        'notes',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'emails' => 'array',
            'is_active' => 'bool',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return array<int, string> */
    public function emailList(): array
    {
        return array_values(array_filter(array_map(
            fn ($e) => trim((string) $e),
            (array) $this->emails
        ), fn ($e) => $e !== ''));
    }

    /** Адреса одной строкой — для копирования в поле «Кому». */
    public function emailsJoined(): string
    {
        return implode(', ', $this->emailList());
    }

    /** Адреса в поле формы — по одному на строку. */
    public function emailsText(): string
    {
        return implode("\n", $this->emailList());
    }

    /**
     * Разобрать адреса из текстового поля: по строкам, запятым, точкам с
     * запятой и пробелам. Дубликаты (в разном регистре) схлопываем.
     *
     * @return array<int, string>
     */
    public static function parseEmails(?string $raw): array
    {
        $parts = preg_split('/[\s,;]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $seen = [];
        foreach ($parts as $part) {
            // Формат «Имя <mail@dom.ru>» — берём адрес из угловых скобок.
            if (preg_match('/<([^>]+)>/', $part, $m) === 1) {
                $part = $m[1];
            }
            $part = trim($part, "<>,;\"' \t");
            if ($part === '' || ! str_contains($part, '@')) {
                continue;
            }
            $key = mb_strtolower($part);
            if (! array_key_exists($key, $seen)) {
                $seen[$key] = $part;
            }
        }

        return array_values($seen);
    }

    /** Поиск по направлению, организации, контакту, адресам и заметке. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace('%', '\%', $term).'%';

        return $q->where(function (Builder $w) use ($like) {
            $w->where('topic', 'ilike', $like)
                ->orWhere('organization', 'ilike', $like)
                ->orWhere('contact_person', 'ilike', $like)
                ->orWhere('notes', 'ilike', $like)
                ->orWhere('folder_path', 'ilike', $like)
                ->orWhereRaw('emails::text ilike ?', [$like]);
        });
    }
}
