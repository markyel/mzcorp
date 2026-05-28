<?php

namespace App\Enums;

/**
 * Тип записи в стоп-листе отправителей.
 *
 * email  — точное совпадение адреса (с учётом нормализации: lowercase,
 *          plus-addressing срезается → `foo+bar@x.ru` матчится `foo@x.ru`).
 * domain — совпадение по домену И всем поддоменам (суффикс-матч).
 *          Например запись `paulschaab.de` ловит `foo@paulschaab.de`
 *          и `foo@mail.paulschaab.de`, но НЕ `foo@paulschaab.de.evil.com`.
 */
enum BlocklistEntryType: string
{
    case Email = 'email';
    case Domain = 'domain';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Адрес',
            self::Domain => 'Домен',
        };
    }
}
