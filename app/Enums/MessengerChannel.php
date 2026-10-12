<?php

namespace App\Enums;

/** Мессенджер бота заявок (BotConversationService). */
enum MessengerChannel: string
{
    case Telegram = 'telegram';
    case Max = 'max';

    public function label(): string
    {
        return match ($this) {
            self::Telegram => 'Telegram',
            self::Max => 'MAX',
        };
    }
}
