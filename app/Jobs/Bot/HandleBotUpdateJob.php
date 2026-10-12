<?php

namespace App\Jobs\Bot;

use App\Enums\MessengerChannel;
use App\Services\Bot\BotConversationService;
use App\Services\Bot\BotUpdate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Одно событие бота заявок. Повторную доставку того же события площадкой
 * отсекаем по его id; сообщения одного клиента обрабатываем по очереди
 * (блокировка на пользователя), иначе два быстрых сообщения перетрут шаг диалога.
 */
class HandleBotUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $channel, public readonly array $payload)
    {
    }

    public function handle(BotConversationService $bot): void
    {
        $channel = MessengerChannel::from($this->channel);
        $update = $channel === MessengerChannel::Telegram
            ? BotUpdate::fromTelegram($this->payload)
            : BotUpdate::fromMax($this->payload);
        if ($update === null) {
            return;
        }
        if (! Cache::add('bot:update:'.$channel->value.':'.$update->updateId, 1, now()->addDay())) {
            return;
        }

        Cache::lock('bot:chat:'.$channel->value.':'.$update->userId, 110)->block(100, fn () => $bot->handle($update));
    }
}
