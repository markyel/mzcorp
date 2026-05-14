<?php

namespace App\Console\Commands;

use App\Services\Request\AttentionService;
use Illuminate\Console\Command;

/**
 * Cron: sweep денормализованного `attention_level` (Phase 1.11,
 * Foundation §5.3).
 *
 * Идёт по requests где attention_required_at < NOW() AND attention_level=0
 * AND status NOT IN (silent) → ставит attention_level=1. Обратно: где
 * attention_level=1 но дедлайн уже в будущем (после resume / transitionTo)
 * → attention_level=0.
 *
 * Schedule: everyFifteenMinutes — компромисс между точностью подсветки
 * в Pool и нагрузкой. 15 мин — приемлемый lag для просрочки в часах/днях.
 *
 * Usage:
 *   php artisan requests:check-attention
 *   php artisan requests:check-attention --dry-run
 */
class RequestsCheckAttentionCommand extends Command
{
    protected $signature = 'requests:check-attention
        {--dry-run : Показать, сколько строк было бы помечено overdue}';

    protected $description = 'Pgsweep attention_level=1 для просроченных заявок (Foundation §5.3).';

    public function handle(AttentionService $attention): int
    {
        if ($this->option('dry-run')) {
            $overdue = \App\Models\Request::query()
                ->whereNotNull('attention_required_at')
                ->where('attention_level', 0)
                ->where('attention_required_at', '<', now())
                ->whereNotIn('status', [
                    \App\Enums\RequestStatus::Paused->value,
                    \App\Enums\RequestStatus::ClosedWon->value,
                    \App\Enums\RequestStatus::ClosedLost->value,
                    \App\Enums\RequestStatus::Pending->value,
                    \App\Enums\RequestStatus::Paid->value,
                ])
                ->count();
            $this->info(sprintf('--dry-run: %d заявок были бы помечены overdue.', $overdue));

            return self::SUCCESS;
        }

        [$marked, $reset] = $attention->sweepOverdue();
        $this->info(sprintf(
            'Attention sweep: %d помечено overdue, %d сброшено в normal.',
            $marked,
            $reset,
        ));

        return self::SUCCESS;
    }
}
