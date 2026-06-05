<?php

namespace App\Console\Commands;

use App\Services\Iqot\IqotDispatchService;
use Illuminate\Console\Command;

/**
 * Лимитированный запуск IQOT-анализа: обновляет пул из проигранных КП и
 * отправляет наиболее приоритетные позиции в IQOT (в рамках дневного лимита).
 * Крон — раз в 2 часа. См. routes/console.php.
 */
class IqotDispatchCommand extends Command
{
    protected $signature = 'iqot:dispatch';

    protected $description = 'Refresh IQOT pool from lost quotes and submit top-priority positions within the daily limit';

    public function handle(IqotDispatchService $svc): int
    {
        $result = $svc->dispatch();
        $this->info('IQOT dispatch: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
