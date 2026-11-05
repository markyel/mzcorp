<?php

namespace App\Support;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;

/**
 * Короткое имя задачи расписания для выключателя SCHEDULE_DISABLED (routes/console.php):
 * у команды — её имя («suppliers:remind», без аргументов), у задачи-класса — имя класса
 * без пространства имён («SyncQuotePartsRfqsJob»).
 */
final class ScheduledTaskName
{
    public static function of(Event $event): string
    {
        if ($event instanceof CallbackEvent) {
            $description = (string) $event->description;

            return class_basename($description !== '' ? $description : '');
        }

        // «'/usr/bin/php8.3' 'artisan' suppliers:remind --x» → «suppliers:remind»
        if (preg_match("/'?artisan'?\s+([A-Za-z0-9:_\-]+)/", (string) $event->command, $m) === 1) {
            return $m[1];
        }

        return (string) $event->command;
    }
}
