<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Организация-клиент (раздел «Клиенты»). Юр.идентичность + реквизиты для КП/
 * счёта + скидка. Связь с контактами (email) — M:N через organization_contact.
 *
 * @property string $name
 * @property ?string $inn
 * @property ?string $kpp
 * @property ?string $address
 * @property ?string $requisites_text
 * @property float $discount_percent
 */
class Organization extends Model
{
    protected $fillable = [
        'name',
        'inn',
        'kpp',
        'address',
        'requisites_text',
        'discount_percent',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
        ];
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(ClientContact::class, 'organization_contact')
            ->withTimestamps()
            ->orderBy('email');
    }

    /**
     * Заявки, явно привязанные к этой организации (requests.organization_id).
     * Точная связь — в отличие от косвенной «по email контактов».
     */
    public function requests(): HasMany
    {
        return $this->hasMany(Request::class);
    }

    /**
     * E-mail'ы контактов организации в нижнем регистре — ключ для подсчёта
     * статистики по заявкам (requests.client_email).
     *
     * @return array<int, string>
     */
    public function contactEmails(): array
    {
        return $this->contacts
            ->pluck('email')
            ->filter()
            ->map(fn ($e) => mb_strtolower((string) $e))
            ->unique()
            ->values()
            ->all();
    }
}
