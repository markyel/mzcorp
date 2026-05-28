<?php

namespace App\Enums;

/**
 * Источник добавления записи в стоп-лист.
 *
 * manual       — добавлено вручную из админки /dashboard/sender-blocklist
 *                (РОП или admin).
 * from_request — добавлено через action «Закрыть как спам» на карточке
 *                заявки. В `added_from_request_id` ссылка на исходник.
 */
enum BlocklistEntrySource: string
{
    case Manual = 'manual';
    case FromRequest = 'from_request';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Вручную',
            self::FromRequest => 'Из заявки',
        };
    }
}
