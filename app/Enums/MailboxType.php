<?php

namespace App\Enums;

/**
 * Тип почтового ящика.
 *
 * shared   — общий ящик (sales@..., info@...) без привязки к менеджеру.
 * personal — личный ящик менеджера (см. Foundation §1.5 sticky-роутинг).
 */
enum MailboxType: string
{
    case Shared = 'shared';
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Shared => 'Общий',
            self::Personal => 'Личный',
        };
    }
}
