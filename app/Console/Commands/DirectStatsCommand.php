<?php

namespace App\Console\Commands;

use App\Services\Direct\DirectStatsService;
use Illuminate\Console\Command;

/**
 * Забрать из Директа разрезы статистики: условия показа и поисковые запросы.
 *
 * Живой счётчик кампании показывает только сумму показов; понять, тянет ли
 * трафик автотаргетинг или наши фразы, можно лишь отчётом — а он отстаёт на
 * часы, поэтому тянем регулярно и переписываем дни целиком.
 */
class DirectStatsCommand extends Command
{
    protected $signature = 'direct:stats {--days=7 : За сколько дней показать сводку}';

    protected $description = 'Статистика Директа: что приносит показы';

    public function handle(DirectStatsService $stats): int
    {
        $res = $stats->pull();
        if ($res['error'] !== null) {
            $this->warn($res['error']);
        } else {
            $this->info("Записано строк: условий показа {$res['criteria']}, поисковых запросов {$res['queries']}.");
        }

        $days = max(1, (int) $this->option('days'));
        $sum = $stats->summary($days);

        $this->line(sprintf(
            'За %d дн.: показов %d (из них автотаргетинг %d), кликов %d, расход %s ₽',
            $days,
            $sum['impressions'],
            $sum['auto']['impressions'],
            $sum['clicks'],
            number_format($sum['cost'], 2, ',', ' '),
        ));

        foreach ($sum['phrases'] as $row) {
            $this->line(sprintf('  фраза %-40s показов %4d, кликов %d', $row['name'], $row['impressions'], $row['clicks']));
        }
        foreach ($sum['queries'] as $row) {
            $this->line(sprintf('  запрос %-40s показов %4d, кликов %d', $row['name'], $row['impressions'], $row['clicks']));
        }

        return self::SUCCESS;
    }
}
