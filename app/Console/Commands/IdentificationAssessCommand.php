<?php

namespace App\Console\Commands;

use App\Services\Catalog\IdentificationCoverageService;
use Illuminate\Console\Command;

/**
 * Что для опознания детали у непривязанных позиций открытых заявок уже есть (IdentificationCoverageService):
 * новые и изменившиеся позиции, новые письма клиента. По расписанию раз в 2 минуты.
 *
 *   php artisan identification:assess
 *   php artisan identification:assess --limit=2000
 */
class IdentificationAssessCommand extends Command
{
    protected $signature = 'identification:assess {--limit=40 : сколько позиций за запуск}';

    protected $description = 'Справочник опознания: что из нужного для опознания у позиций уже есть';

    public function handle(IdentificationCoverageService $coverage): int
    {
        $r = $coverage->assessPending(max(1, (int) $this->option('limit')));
        $this->info(sprintf('Позиций: %d, проверено моделью: %d, ошибок: %d.', $r['checked'], $r['assessed'], $r['failed']));

        return self::SUCCESS;
    }
}
