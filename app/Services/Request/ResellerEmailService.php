<?php

namespace App\Services\Request;

use App\Models\ResellerEmail;

/**
 * Ручной статус «перепродавец» на уровне e-mail клиента. Чисто ручной (нет
 * авто-механизма, в отличие от DealerEmailService): есть запись → перепродавец.
 * На распределение НЕ влияет. Ставит/снимает менеджер в любой заявке; статус
 * виден во всех заявках этого e-mail (адрес хранится нормализованным).
 */
class ResellerEmailService
{
    public function isReseller(string $email): bool
    {
        $normalized = $this->normalize($email);

        return $normalized !== '' && ResellerEmail::query()->where('email', $normalized)->exists();
    }

    public function mark(string $email, ?int $userId): bool
    {
        $normalized = $this->normalize($email);
        if ($normalized === '') {
            return false;
        }
        ResellerEmail::query()->updateOrCreate(
            ['email' => $normalized],
            ['marked_by_user_id' => $userId],
        );

        return true;
    }

    public function unmark(string $email): bool
    {
        $normalized = $this->normalize($email);
        if ($normalized === '') {
            return false;
        }
        ResellerEmail::query()->where('email', $normalized)->delete();

        return true;
    }

    private function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
