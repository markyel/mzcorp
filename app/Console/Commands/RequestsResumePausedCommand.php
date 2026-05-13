<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\Request;
use App\Services\Request\RequestPauseService;
use Illuminate\Console\Command;

/**
 * Cron: возобновить все paused-заявки, чей `paused_until` <= now()
 * (Phase 1.10, Foundation §5.4).
 *
 * Schedule: dailyAt('06:00') — см. routes/console.php / Kernel.
 *
 * Usage:
 *   php artisan requests:resume-paused              # apply
 *   php artisan requests:resume-paused --dry-run    # только показать список
 */
class RequestsResumePausedCommand extends Command
{
    protected $signature = 'requests:resume-paused
        {--dry-run : Только показать список заявок к возобновлению, без изменений}';

    protected $description = 'Снять с паузы все заявки, чей paused_until <= now() (Phase 1.10).';

    public function handle(RequestPauseService $pause): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $due = Request::query()
            ->where('status', RequestStatus::Paused->value)
            ->whereNotNull('paused_until')
            ->where('paused_until', '<=', now())
            ->orderBy('paused_until')
            ->get(['id', 'internal_code', 'paused_until', 'paused_from_status', 'assigned_user_id']);

        if ($due->isEmpty()) {
            $this->info('Нет заявок, готовых к возобновлению.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Найдено заявок к возобновлению: %d', $due->count()));
        $this->table(
            ['ID', 'Code', 'Paused until', 'Resume to', 'Manager'],
            $due->map(fn ($r) => [
                $r->id,
                $r->internal_code,
                $r->paused_until?->toDateTimeString(),
                $r->paused_from_status ?: '(assigned)',
                $r->assigned_user_id ?: '—',
            ])->all(),
        );

        if ($dryRun) {
            $this->warn('--dry-run: ничего не изменено.');
            return self::SUCCESS;
        }

        $count = $pause->applyDuePauses();
        $this->info(sprintf('Возобновлено: %d заявок.', $count));

        return self::SUCCESS;
    }
}
