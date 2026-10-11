<?php

namespace App\Console\Commands;

use App\Jobs\Mail\SendQueuedDraftJob;
use App\Services\Mail\OutgoingSendRetryService;
use Illuminate\Console\Command;

/**
 * Ставит в очередь повторную отправку черновиков, которые не ушли из-за
 * недоступного SMTP и чья следующая попытка уже наступила
 * (OutgoingSendRetryService). Крон раз в минуту; SMTP шлёт SendQueuedDraftJob.
 *
 *   php artisan mail:send-queued --dry-run
 */
class MailSendQueuedCommand extends Command
{
    protected $signature = 'mail:send-queued
        {--limit=50 : Максимум черновиков за прогон}
        {--dry-run : Только показать, что пора отправить}';

    protected $description = 'Повторить отправку писем, не ушедших из-за недоступного SMTP';

    public function handle(OutgoingSendRetryService $retry): int
    {
        $ids = $retry->dueDraftIds(max(1, (int) $this->option('limit')));

        if ($ids->isEmpty()) {
            $this->info('Нет писем в очереди на повтор.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Пора отправить: '.$ids->implode(', '));

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            SendQueuedDraftJob::dispatch((int) $id);
        }
        $this->info('Поставлено в очередь: '.$ids->count());

        return self::SUCCESS;
    }
}
