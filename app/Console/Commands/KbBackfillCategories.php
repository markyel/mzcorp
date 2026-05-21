<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Services\Kb\CatalogItemCategorizer;
use Illuminate\Console\Command;

/**
 * Заполняет catalog_items.equipment_category_id через CatalogItemCategorizer
 * (Phase B / 2026-05-21).
 *
 * Двухэтапный пайплайн: rule-based → LLM fallback. По умолчанию обрабатывает
 * только NULL'ы (т.е. инкрементально), --reclassify обновляет все.
 *
 * Использование:
 *   php artisan kb:backfill-categories --dry-run                  # просмотр без записи
 *   php artisan kb:backfill-categories --apply                    # записать
 *   php artisan kb:backfill-categories --apply --rule-only        # без LLM, только правила
 *   php artisan kb:backfill-categories --apply --limit=100        # ограничить
 *   php artisan kb:backfill-categories --apply --reclassify       # переклассифицировать всё
 *   php artisan kb:backfill-categories --apply --sku=M33763       # одна позиция
 */
class KbBackfillCategories extends Command
{
    protected $signature = 'kb:backfill-categories
                            {--apply : Реально записать в БД (без флага — dry-run)}
                            {--rule-only : Только rule-based, без LLM-fallback}
                            {--reclassify : Обрабатывать всё, включая уже классифицированные}
                            {--limit=0 : Ограничить число позиций (0 = без ограничения)}
                            {--sku= : SKU для классификации (один-позиционный режим)}';

    protected $description = 'Заполнить catalog_items.equipment_category_id через rule-based + LLM (gpt-4o-mini)';

    public function handle(CatalogItemCategorizer $categorizer): int
    {
        $apply = (bool) $this->option('apply');
        $ruleOnly = (bool) $this->option('rule-only');
        $reclassify = (bool) $this->option('reclassify');
        $limit = (int) $this->option('limit');
        $sku = trim((string) $this->option('sku'));

        $query = CatalogItem::query()->where('is_active', true);
        if (! $reclassify) {
            $query->whereNull('equipment_category_id');
        }
        if ($sku !== '') {
            $query->where('sku', $sku);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Нечего обрабатывать.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line('=================================================================');
        $this->line(sprintf(
            '  BACKFILL CATEGORIES  %s  rule-only=%s  reclassify=%s  total=%d',
            $apply ? 'APPLY' : 'DRY-RUN',
            $ruleOnly ? 'Y' : 'N',
            $reclassify ? 'Y' : 'N',
            $total,
        ));
        $this->line('=================================================================');
        $this->line('');

        $categories = $categorizer->preloadCategories();
        $this->line('Активных KB-категорий: ' . $categories->count());
        $this->line('');

        $stats = [
            'total' => 0,
            'rule_match' => 0,
            'llm_match' => 0,
            'no_match' => 0,
            'errors' => 0,
            'unchanged' => 0,
            'updated' => 0,
        ];
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%]  %message%');
        $bar->setMessage('starting');
        $bar->start();

        $startedAt = microtime(true);

        // chunkById вместо chunk: при наших мутациях (запись FK, которая
        // выкидывает строку из WHERE NULL) обычный chunk теряет записи через
        // одну (классический Laravel chunk-pagination bug). chunkById идёт по
        // монотонно возрастающим id и не зависит от изменений в WHERE.
        $query->chunkById(50, function ($chunk) use ($categorizer, $apply, $ruleOnly, &$stats, $bar) {
            foreach ($chunk as $item) {
                $stats['total']++;
                $bar->setMessage(sprintf('sku=%s', mb_substr((string) $item->sku, 0, 20)));

                try {
                    $result = $categorizer->categorize($item, allowLlm: ! $ruleOnly);
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->line('');
                    $this->error('  ✗ ' . $item->sku . ': ' . $e->getMessage());
                    $bar->advance();
                    continue;
                }

                $category = $result['category'];
                $method = (string) $result['method'];

                if ($category === null) {
                    $stats['no_match']++;
                } elseif (str_starts_with($method, 'rule_')) {
                    $stats['rule_match']++;
                } elseif (str_starts_with($method, 'llm_')) {
                    $stats['llm_match']++;
                }

                if ($category && $apply) {
                    if ((int) $item->equipment_category_id !== (int) $category->id) {
                        $item->forceFill(['equipment_category_id' => $category->id])->save();
                        $stats['updated']++;
                    } else {
                        $stats['unchanged']++;
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->line('');
        $this->line('');

        $elapsed = microtime(true) - $startedAt;

        $this->line('--- Результаты ---');
        $this->line(sprintf('  Обработано:        %d  (за %0.1f сек)', $stats['total'], $elapsed));
        $this->info(sprintf('  Rule-match:        %d', $stats['rule_match']));
        $this->info(sprintf('  LLM-match:         %d', $stats['llm_match']));
        $this->warn(sprintf('  Без категории:     %d', $stats['no_match']));
        if ($stats['errors'] > 0) {
            $this->error(sprintf('  Ошибки:            %d', $stats['errors']));
        }
        if ($apply) {
            $this->info(sprintf('  Обновлено в БД:    %d', $stats['updated']));
            $this->line(sprintf('  Без изменений:     %d', $stats['unchanged']));
        } else {
            $this->warn('  DRY RUN — БД НЕ изменена. Используй --apply.');
        }
        $this->line('');

        return self::SUCCESS;
    }
}
