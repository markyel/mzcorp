<?php

namespace App\Console\Commands;

use App\Services\Metrika\MetrikaStatsService;
use Illuminate\Console\Command;

/**
 * Забрать из Яндекс Метрики цели счётчиков и визиты по кампаниям Директа и
 * источникам трафика. См. routes/console.php.
 */
class MetrikaPullCommand extends Command
{
    protected $signature = 'metrika:pull {--days= : За сколько дней переписать (по умолчанию окно из конфига)}';

    protected $description = 'Pull Yandex Metrika goals and daily visits by Direct campaign and traffic source';

    public function handle(MetrikaStatsService $metrika): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $res = $metrika->pull($days);

        $this->info("Целей в справочнике: {$res['goals']}, строк статистики: {$res['rows']}.");
        foreach ($res['errors'] as $error) {
            $this->warn('  '.$error);
        }

        return $res['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
