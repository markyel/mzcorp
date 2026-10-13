<?php

namespace App\Console\Commands;

use App\Services\Bot\BotChatDigestService;
use Illuminate\Console\Command;

/**
 * Письма-сводки менеджерам о непрочитанных сообщениях клиентов в Telegram/MAX.
 * См. BotChatDigestService. Расписание — routes/console.php, раз в 2 минуты.
 */
class BotChatDigestCommand extends Command
{
    protected $signature = 'bot:chat-digest';

    protected $description = 'Письмо менеджеру о сообщениях клиентов в Telegram/MAX, которые он не открыл';

    public function handle(BotChatDigestService $digest): int
    {
        $sent = $digest->run();
        if ($sent > 0) {
            $this->info('Отправлено писем: '.$sent);
        }

        return self::SUCCESS;
    }
}
