<?php

namespace App\Console\Commands\Catalog;

use App\Services\Catalog\CatalogResolutionService;
use Illuminate\Console\Command;

/**
 * Прогон CatalogResolutionService::resolveAllPending — повторная попытка
 * сматчить позиции с `catalog_item_id IS NULL`.
 *
 * Полезно после:
 *   - снижения AppSetting `catalog.name_match.similarity_threshold`
 *   - импорта обновлённого каталога (новые позиции могут покрыть старые
 *     unmatched items)
 *   - правок KB-правил (extractors / identification rules)
 *
 * Делает то же самое, что ResolvePendingFromCatalogJob, но дёргается
 * руками. Идемпотентен — успешно сматченные items не трогает.
 *
 * Usage:
 *   php artisan catalog:resolve-unmatched
 */
class CatalogResolveUnmatchedCommand extends Command
{
    protected $signature = 'catalog:resolve-unmatched';

    protected $description = 'Прогон resolveAllPending: пробует A/B/C для items с catalog_item_id IS NULL.';

    public function handle(CatalogResolutionService $resolver): int
    {
        $this->info('Запускаю resolveAllPending…');
        $start = microtime(true);

        $stats = $resolver->resolveAllPending();

        $elapsed = number_format(microtime(true) - $start, 1);
        $total = ($stats['resolved_a'] ?? 0) + ($stats['matched_b'] ?? 0) + ($stats['matched_c'] ?? 0);

        $this->info("✓ Готово за {$elapsed}с.");
        $this->line('');
        $this->line("  Проверено items:   <fg=cyan>{$stats['checked']}</>");
        $this->line("  Сматчено всего:    <fg=green>{$total}</>");
        $this->line("    A (M-SKU):         <fg=green>{$stats['resolved_a']}</>");
        $this->line("    B (brand_article): <fg=green>{$stats['matched_b']}</>");
        $this->line("    C (name_vector):   <fg=green>{$stats['matched_c']}</>");

        return self::SUCCESS;
    }
}
