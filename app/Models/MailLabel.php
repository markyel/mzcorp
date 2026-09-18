<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Метка письма в почтовом клиенте. Набор ЛИЧНЫЙ: у каждого менеджера свой,
 * чужие метки он не видит и не трогает (решение заказчика 18.09.2026, см.
 * миграцию 2026_09_18_190000_make_mail_labels_personal).
 */
class MailLabel extends Model
{
    /** Палитра меток: ключ → [фон, текст] из токенов дизайн-системы. */
    public const COLORS = [
        'sky' => ['var(--sky-50)', 'var(--sky-700)'],
        'emerald' => ['var(--emerald-50)', 'var(--emerald-700)'],
        'amber' => ['var(--amber-50)', 'var(--amber-800)'],
        'red' => ['var(--red-50)', 'var(--red-700)'],
        'violet' => ['var(--violet-50, #f5f3ff)', 'var(--violet-700, #6d28d9)'],
        'neutral' => ['var(--neutral-100)', 'var(--fg-2)'],
    ];

    public const NAME_MAX = 40;

    protected $fillable = ['owner_user_id', 'name', 'color', 'sort_order', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['sort_order' => 'int'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** Метки конкретного пользователя — единственный допустимый способ их читать. */
    public function scopeOwnedBy(Builder $q, ?User $user): Builder
    {
        return $q->where('owner_user_id', $user?->id ?? 0);
    }

    public function belongsToUser(?User $user): bool
    {
        return $user !== null && (int) $this->owner_user_id === (int) $user->id;
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(EmailMessage::class, 'email_message_labels', 'mail_label_id', 'email_message_id');
    }

    /** Цвет фона метки; неизвестный ключ не должен ронять отрисовку. */
    public function bg(): string
    {
        return (self::COLORS[$this->color] ?? self::COLORS['neutral'])[0];
    }

    public function fg(): string
    {
        return (self::COLORS[$this->color] ?? self::COLORS['neutral'])[1];
    }

    /** Нормализовать имя: без лишних пробелов, не длиннее NAME_MAX. */
    public static function normalizeName(?string $raw): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '');

        return mb_substr($name, 0, self::NAME_MAX);
    }

    public static function isValidColor(?string $color): bool
    {
        return $color !== null && array_key_exists($color, self::COLORS);
    }
}
