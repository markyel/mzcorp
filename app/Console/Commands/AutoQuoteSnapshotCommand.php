<?php

namespace App\Console\Commands;

use App\Services\Quotes\AutoQuoteSnapshotService;
use Illuminate\Console\Command;

/**
 * Фиксация решений авто-КП по свежим заявкам.
 *
 * Пока выдача холостая, снимок — единственный способ честно измерить правило:
 * он ловит цену, скидку и набор проверок такими, какими они были в момент
 * заявки. Когда выдача заработает вживую, отсюда же будет браться то, что
 * уходит клиенту.
 */
class AutoQuoteSnapshotCommand extends Command
{
    protected $signature = 'auto-quote:snapshot
        {--hours=72 : Окно свежих заявок}
        {--limit=500 : Максимум заявок за прогон}
        {--force : Перезаписать существующие снимки (осознанно: история портится)}';

    protected $description = 'Зафиксировать, что авто-КП выдало бы по свежим заявкам';

    public function handle(AutoQuoteSnapshotService $snapshots): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, (int) $this->option('limit'));
        $force = (bool) $this->option('force');

        $requests = $snapshots->pending($hours, $limit);
        if ($requests->isEmpty()) {
            $this->info('Новых заявок без снимка нет.');

            return self::SUCCESS;
        }

        $eligible = 0;
        foreach ($requests as $request) {
            $snapshot = $snapshots->capture($request, $force);
            $eligible += $snapshot->eligible ? 1 : 0;
        }

        $this->info(sprintf(
            'Снимков сделано: %d, из них под автомат: %d (правило %s).',
            $requests->count(),
            $eligible,
            AutoQuoteSnapshotService::RULE_VERSION,
        ));

        return self::SUCCESS;
    }
}
