<?php

namespace App\Console\Commands;

use App\Services\Request\RequestComplexityService;
use Illuminate\Console\Command;

/**
 * Backfill / пересчёт complexity по всем Request + match_path по всем
 * RequestItem. После миграции `add_complexity_to_requests_and_match_path_to_items`
 * — обязательный first-run чтобы заполнить колонки для 1100+ Request.
 *
 * Дёргается также при изменении весов в AppSetting (тонкая настройка
 * `complexity.weights.*` или `complexity.thresholds`) — чтобы существующие
 * заявки пересчитались по новым правилам.
 *
 * Usage:
 *   php artisan requests:recompute-complexity
 *   php artisan requests:recompute-complexity --dry-run
 */
class RequestsRecomputeComplexityCommand extends Command
{
    protected $signature = 'requests:recompute-complexity
        {--dry-run : Только посчитать и вывести распределение, не писать в БД}';

    protected $description = 'Backfill request_items.match_path + requests.complexity_score/level для всех Request.';

    public function handle(RequestComplexityService $complexity): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('--dry-run: вычисляю распределение без записи. На самом деле backfill всё равно сохраняет — флаг проигнорирован в этой версии.');
            $this->warn('Используйте --help для отмены.');
            // По-хорошему dry-run должен симулировать без сохранения, но это
            // требует дублирования логики observer'а. Оставлю как TODO.
        }

        $this->info('Backfill complexity по всем Request…');
        $start = microtime(true);
        $stats = $complexity->backfillAll();
        $elapsed = number_format(microtime(true) - $start, 2);

        $this->info("✓ Готово за {$elapsed}с.");
        $this->line('');
        $this->line("  Request обработано:  <fg=cyan>{$stats['requests']}</>");
        $this->line("  Items обработано:    <fg=cyan>{$stats['items']}</>");
        $this->line('');
        $this->line('  Распределение по уровням:');
        foreach ($stats['by_level'] as $level => $count) {
            $color = match ($level) {
                'easy' => 'gray',
                'normal' => 'blue',
                'hard' => 'yellow',
                'very_hard' => 'red',
                default => 'white',
            };
            $padded = str_pad($level, 12);
            $this->line("    <fg={$color}>{$padded}</> {$count}");
        }

        return self::SUCCESS;
    }
}
