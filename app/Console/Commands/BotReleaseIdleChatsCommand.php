<?php

namespace App\Console\Commands;

use App\Services\Bot\BotLiveChatService;
use Illuminate\Console\Command;

/**
 * Живые чаты, где BotLiveChatService::IDLE_MINUTES никто ничего не писал, — обратно боту: клиенту —
 * что разговор с менеджером закончен, даже если сам он больше не пишет. Раз в минуту.
 */
class BotReleaseIdleChatsCommand extends Command
{
    protected $signature = 'bot:release-idle-chats';

    protected $description = 'Return idle live bot chats to the bot';

    public function handle(BotLiveChatService $live): int
    {
        $n = $live->releaseIdle();
        if ($n > 0) {
            $this->info("Вернули боту: {$n}.");
        }

        return self::SUCCESS;
    }
}
