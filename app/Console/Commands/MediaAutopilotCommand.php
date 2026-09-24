<?php

namespace App\Console\Commands;

use App\Services\Marketing\MediaAutopilotService;
use Illuminate\Console\Command;

/**
 * Ежедневный прогон медиаплана: черновики по темам, которым пора, и публикация
 * в каналы, где она разрешена. См. routes/console.php.
 */
class MediaAutopilotCommand extends Command
{
    protected $signature = 'media:autopilot {--no-publish : только черновики, ничего не публиковать}';

    protected $description = 'Draft due media topics and publish them where the channel allows it';

    public function handle(MediaAutopilotService $service): int
    {
        $res = $service->run(publish: ! $this->option('no-publish'));

        $this->info(sprintf('Медиаплан: черновиков %d, опубликовано %d.', $res['drafted'], $res['published']));
        foreach ($res['skipped'] as $line) {
            $this->line('  — '.$line);
        }

        return self::SUCCESS;
    }
}
