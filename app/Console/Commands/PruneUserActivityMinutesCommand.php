<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Прунинг heartbeat-присутствия: удаление строк user_activity_minutes старше
 * `--days` (по умолчанию 365). Раздел «Использование системы» показывает
 * период до 90 дней + произвольные диапазоны, поэтому год истории — с запасом.
 *
 * Таблица растёт примерно на (активные минуты × пользователи) в день — при
 * ~6 менеджерах это десятки тысяч узких строк в месяц, но без прунинга копится
 * бесконечно. Идемпотентно, батчами.
 */
class PruneUserActivityMinutesCommand extends Command
{
    protected $signature = 'usage:prune-activity-minutes
        {--days=365 : Удалять минуты старше N дней}
        {--chunk=10000 : Размер батча удаления}';

    protected $description = 'Удалить старые heartbeat-минуты присутствия (user_activity_minutes)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $chunk = max(1000, (int) $this->option('chunk'));
        $cutoff = now()->subDays($days)->startOfDay();

        $total = 0;
        do {
            $deleted = DB::table('user_activity_minutes')
                ->where('minute', '<', $cutoff)
                ->limit($chunk)
                ->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Удалено heartbeat-минут старше {$days} дн.: {$total}");
        if ($total > 0) {
            Log::info('PruneUserActivityMinutes', ['deleted' => $total, 'cutoff' => $cutoff->toDateString()]);
        }

        return self::SUCCESS;
    }
}
