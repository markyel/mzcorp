<?php

namespace App\Console\Commands\QuoteParts;

use App\Services\QuoteParts\QuotePartsRfqReadiness;
use Illuminate\Console\Command;

/**
 * Письмо автору запроса QuoteParts «цены собраны — можно выбирать». По расписанию это делает
 * SyncQuotePartsRfqsJob; команда — ручной прогон. --mark-only отмечает уже готовые запросы как
 * уведомлённые без писем (при первом включении, чтобы не разослать старые), --dry-run только печатает.
 */
class NotifyQuotePartsReadyCommand extends Command
{
    protected $signature = 'quoteparts:notify-ready {--mark-only : Отметить готовые без писем} {--dry-run : Только показать, какие запросы готовы}';

    protected $description = 'Сообщить авторам запросов QuoteParts, что цены собраны и можно выбирать';

    public function handle(QuotePartsRfqReadiness $readiness): int
    {
        $tags = $readiness->notifyReady(markOnly: (bool) $this->option('mark-only'), dryRun: (bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? 'Готовы' : ($this->option('mark-only') ? 'Отмечены' : 'Уведомлены')).': '.count($tags).($tags ? ' — '.implode(', ', $tags) : ''));

        return self::SUCCESS;
    }
}
