<?php

namespace App\Services\Bot;

use App\Enums\MessengerChannel;

/** Клиент мессенджера по каналу; null — ключ бота не задан. */
class BotMessengers
{
    public function __construct(private readonly BotSettings $settings)
    {
    }

    public function for(MessengerChannel $channel): ?BotMessenger
    {
        $token = $this->settings->token($channel);
        if ($token === null) {
            return null;
        }

        return match ($channel) {
            MessengerChannel::Telegram => new TelegramBotMessenger($token),
            MessengerChannel::Max => new MaxBotMessenger($token),
        };
    }
}
