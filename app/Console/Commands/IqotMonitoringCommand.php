<?php

namespace App\Console\Commands;

use App\Services\Iqot\IqotMonitoringService;
use Illuminate\Console\Command;

/**
 * Мониторинг цен IQOT («Гибрид»): позиции «К актуализации» с OEM — на мониторинг, ушедшие —
 * снять; ход импортов, сопоставление и цены — к нам. Крон — ежечасно (см. routes/console.php).
 */
class IqotMonitoringCommand extends Command
{
    protected $signature = 'iqot:monitoring
        {--dry-run : Только посчитать, что уйдёт на мониторинг, ничего не отправлять}
        {--full-prices : Забрать цены целиком, а не только изменившиеся}';

    protected $description = 'Sync «К актуализации» positions with OEM to the IQOT hybrid monitoring program and pull prices';

    public function handle(IqotMonitoringService $svc): int
    {
        $result = $svc->run((bool) $this->option('dry-run'), (bool) $this->option('full-prices'));
        $this->info('IQOT monitoring: '.json_encode($result, JSON_UNESCAPED_UNICODE));

        return isset($result['error']) ? self::FAILURE : self::SUCCESS;
    }
}
