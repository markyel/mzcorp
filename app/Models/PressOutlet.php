<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * СМИ или площадка, куда рассылаем пресс-релизы.
 *
 * @property list<string>|null $emails
 */
class PressOutlet extends Model
{
    public const CATEGORIES = [
        'Лифтовая отрасль',
        'ЖКХ и управление недвижимостью',
        'Строительство и девелопмент',
        'Деловые и промышленные СМИ',
        'Площадки пресс-релизов',
    ];

    public const PRIORITIES = [
        'A' => 'каждый релиз',
        'B' => 'по теме',
        'C' => 'крупные поводы',
    ];

    protected $fillable = [
        'name', 'category', 'priority', 'kind', 'url', 'emails', 'other_contact',
        'terms', 'audience', 'notes', 'email_source', 'is_active',
    ];

    protected $casts = [
        'emails' => 'array',
        'is_active' => 'boolean',
    ];

    public function sends(): HasMany
    {
        return $this->hasMany(PressReleaseSend::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(PressMention::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Адреса продаж и рекламы: релиз туда не шлём — это не редакция. */
    public const COMMERCIAL_EMAIL = '/^(sales?|adv|reklama|director)@/i';

    /** Основной адрес редакции — на него уходит релиз. */
    public function primaryEmail(): ?string
    {
        foreach ($this->emails ?? [] as $email) {
            if (is_string($email) && $email !== '' && ! preg_match(self::COMMERCIAL_EMAIL, $email)) {
                return $email;
            }
        }

        return null;
    }

    /** Домен сайта — короткая подпись в списках. */
    public function host(): string
    {
        $host = (string) parse_url((string) $this->url, PHP_URL_HOST);

        return $host !== '' ? preg_replace('/^www\./', '', $host) : (string) $this->url;
    }
}
