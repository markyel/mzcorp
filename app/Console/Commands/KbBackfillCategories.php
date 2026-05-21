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
                            {--sku= : SKU для классификации (один-позиционный режим)}
                            {--by-part-type : Группировать по уникальным part_type — 1 LLM-вызов на тип, bulk UPDATE всех SKU группы}';

    protected $description = 'Заполнить catalog_items.equipment_category_id через rule-based + LLM (gpt-4o-mini)';

    public function handle(CatalogItemCategorizer $categorizer): int
    {
        $apply = (bool) $this->option('apply');
        $ruleOnly = (bool) $this->option('rule-only');
        $reclassify = (bool) $this->option('reclassify');
        $limit = (int) $this->option('limit');
        $sku = trim((string) $this->option('sku'));
        $byPartType = (bool) $this->option('by-part-type');

        if ($byPartType) {
            return $this->runByPartType($categorizer, $apply, $ruleOnly, $reclassify);
        }

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

    /**
     * Группируем неклассифицированные SKU по уникальным part_type,
     * один LLM-вызов на тип → bulk UPDATE всех SKU этого типа.
     *
     * Экономия: 10148 SKU обычно укладываются в ~200 уникальных part_type
     * (50× меньше LLM-вызовов, 50× дешевле, 50× быстрее).
     *
     * Группы с пустым part_type (NULL/'') пропускаются — для них нужен
     * per-item classify (есть в обычном режиме без --by-part-type).
     */
    private function runByPartType(
        CatalogItemCategorizer $categorizer,
        bool $apply,
        bool $ruleOnly,
        bool $reclassify,
    ): int {
        $base = \DB::table('catalog_items')
            ->where('is_active', true)
            ->whereNotNull('part_type')
            ->where('part_type', '!=', '');
        if (! $reclassify) {
            $base->whereNull('equipment_category_id');
        }

        $groups = (clone $base)
            ->selectRaw('part_type, count(*) as cnt')
            ->groupBy('part_type')
            ->orderByDesc('cnt')
            ->get();

        $totalGroups = $groups->count();
        $totalItems = (int) $groups->sum('cnt');

        $this->line('');
        $this->line('=================================================================');
        $this->line(sprintf(
            '  BACKFILL BY PART_TYPE  %s  rule-only=%s  groups=%d  items=%d',
            $apply ? 'APPLY' : 'DRY-RUN',
            $ruleOnly ? 'Y' : 'N',
            $totalGroups,
            $totalItems,
        ));
        $this->line('=================================================================');
        $this->line('');

        if ($totalGroups === 0) {
            $this->info('Нет групп для обработки.');
            return self::SUCCESS;
        }

        $categories = $categorizer->preloadCategories();
        $this->line('KB-категорий: ' . $categories->count());
        $this->line('');

        $stats = [
            'groups_total' => $totalGroups,
            'groups_matched' => 0,
            'groups_no_match' => 0,
            'rule_groups' => 0,
            'llm_groups' => 0,
            'items_updated' => 0,
        ];

        $bar = $this->output->createProgressBar($totalGroups);
        $bar->setFormat(' %current%/%max% [%bar%]  %message%');
        $bar->setMessage('starting');
        $bar->start();

        $startedAt = microtime(true);

        foreach ($groups as $g) {
            $partType = (string) $g->part_type;
            $bar->setMessage(mb_substr($partType, 0, 40) . " ({$g->cnt})");

            // Берём представителя группы — SKU с наибольшим заполнением полей
            // (приоритет items с name+unit_name).
            $sample = CatalogItem::query()
                ->where('is_active', true)
                ->where('part_type', $partType)
                ->when(! $reclassify, fn ($q) => $q->whereNull('equipment_category_id'))
                ->orderByRaw('CASE WHEN unit_name IS NOT NULL AND unit_name <> \'\' THEN 0 ELSE 1 END')
                ->orderByRaw('LENGTH(COALESCE(name, \'\')) DESC')
                ->first();

            if (! $sample) {
                $bar->advance();
                continue;
            }

            try {
                $result = $categorizer->categorize($sample, allowLlm: ! $ruleOnly);
            } catch (\Throwable $e) {
                $this->line('');
                $this->error("  ✗ part_type «{$partType}»: " . $e->getMessage());
                $bar->advance();
                continue;
            }

            $category = $result['category'];
            $method = (string) $result['method'];

            if ($category === null) {
                $stats['groups_no_match']++;
                $bar->advance();
                continue;
            }

            $stats['groups_matched']++;
            if (str_starts_with($method, 'rule_')) {
                $stats['rule_groups']++;
            } elseif (str_starts_with($method, 'llm_')) {
                $stats['llm_groups']++;
            }

            if ($apply) {
                // Bulk UPDATE всех SKU этого part_type ОДНИМ SQL — никаких Eloquent.
                $upd = \DB::table('catalog_items')
                    ->where('is_active', true)
                    ->where('part_type', $partType);
                if (! $reclassify) {
                    $upd->whereNull('equipment_category_id');
                }
                $affected = $upd->update(['equipment_category_id' => $category->id]);
                $stats['items_updated'] += $affected;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->line('');

        $elapsed = microtime(true) - $startedAt;

        $this->line('--- Результаты ---');
        $this->line(sprintf('  Групп обработано:   %d (за %0.1f сек)', $stats['groups_total'], $elapsed));
        $this->info(sprintf('  Группы → категория: %d', $stats['groups_matched']));
        $this->line(sprintf('     · rule-based:    %d', $stats['rule_groups']));
        $this->line(sprintf('     · LLM:           %d', $stats['llm_groups']));
        $this->warn(sprintf('  Без категории:      %d', $stats['groups_no_match']));
        if ($apply) {
            $this->info(sprintf('  SKU обновлено:      %d', $stats['items_updated']));
        } else {
            $this->warn('  DRY RUN — БД НЕ изменена. Используй --apply.');
        }
        $this->line('');
        $this->line('  Оставшиеся NULL (пустой part_type) — обработай per-item:');
        $this->line('    php artisan kb:backfill-categories --apply');
        $this->line('');

        return self::SUCCESS;
    }
}
