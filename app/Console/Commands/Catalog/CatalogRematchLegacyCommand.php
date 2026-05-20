<?php

namespace App\Console\Commands\Catalog;

use App\Models\RequestItem;
use App\Services\Catalog\CatalogResolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill payload.catalog_match.method для legacy-items, у которых
 * catalog_item_id заполнен, но method отсутствует (старый код в
 * `applyCatalogToItem` не всегда писал method, или payload был обнулён
 * вручную).
 *
 * Алгоритм:
 *   1. Найти `request_items` где `match_path = manual` AND
 *      `catalog_item_id IS NOT NULL` (это и есть «legacy» 113 шт по
 *      диагностике от 2026-05-20).
 *   2. Сохранить старый catalog_item_id в payload.previous_catalog_match
 *      и сбросить catalog_item_id = NULL.
 *   3. Прогнать `CatalogResolutionService::matchOrResolve(item)` — он
 *      пробует A→B→C и записывает method в payload.
 *   4. Если matched → ок, новый method заполнен. Если НЕ matched →
 *      восстановить старый catalog_item_id (откатить шаг 2) — лучше
 *      иметь старый match без method, чем потерять привязку.
 *   5. Observer (RequestItemObserver::updated) автоматически дёрнет
 *      detectAndStoreItemPath + recompute parent.
 *
 * Usage:
 *   php artisan catalog:rematch-legacy --dry-run
 *   php artisan catalog:rematch-legacy --limit=500
 */
class CatalogRematchLegacyCommand extends Command
{
    protected $signature = 'catalog:rematch-legacy
        {--dry-run : Только показать что будет сделано, без записи}
        {--limit=1000 : Максимум items за прогон}';

    protected $description = 'Backfill catalog_match.method для legacy items (cat_id есть, method пуст).';

    public function handle(CatalogResolutionService $resolver): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        // Кандидаты: match_path=manual И catalog_item_id IS NOT NULL.
        // Этот случай возникает когда applyCatalogToItem не записал
        // catalog_match.method в payload (старый код / ручное вмешательство).
        $items = RequestItem::query()
            ->where('match_path', 'manual')
            ->whereNotNull('catalog_item_id')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($items->isEmpty()) {
            $this->info('Legacy items не найдено.');
            return self::SUCCESS;
        }

        $this->info("Найдено {$items->count()} legacy items.");
        if ($dry) {
            $this->warn('--dry-run: показ первых 10 и выход.');
            foreach ($items->take(10) as $it) {
                $this->line(sprintf(
                    '  #%-6d cat_id=%-6d art=[%s] name=[%s]',
                    $it->id,
                    $it->catalog_item_id,
                    mb_substr((string) $it->parsed_article, 0, 20),
                    mb_substr((string) $it->parsed_name, 0, 50),
                ));
            }
            return self::SUCCESS;
        }

        $stats = ['rematched' => 0, 'restored' => 0, 'errors' => 0];

        foreach ($items as $item) {
            try {
                $oldCatalogId = $item->catalog_item_id;
                $payload = is_array($item->quality_assessment_payload)
                    ? $item->quality_assessment_payload
                    : [];

                // Сохранить snapshot старого match'a (на случай отката).
                if (! empty($payload['catalog_match'])) {
                    $payload['previous_catalog_match'] = $payload['catalog_match'];
                }
                unset($payload['catalog_match']);

                // Прямой UPDATE через Query Builder — БЕЗ Eloquent save,
                // чтобы observer не запутался в промежуточном NULL-state.
                DB::table('request_items')
                    ->where('id', $item->id)
                    ->update([
                        'catalog_item_id' => null,
                        'quality_assessment_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ]);

                // Перечитать item с обнулённым catalog_item_id.
                $fresh = RequestItem::find($item->id);
                if ($fresh === null) {
                    $stats['errors']++;
                    continue;
                }

                $matched = $resolver->matchOrResolve($fresh);
                if ($matched) {
                    $stats['rematched']++;
                    $this->line(sprintf(
                        '  #%-6d → matched method=%s cat=%d',
                        $item->id,
                        $fresh->quality_assessment_payload['catalog_match']['method'] ?? '?',
                        $fresh->catalog_item_id ?? 0,
                    ));
                } else {
                    // Откат: восстановить старый catalog_item_id чтобы не
                    // потерять существующую привязку. Method останется пуст —
                    // запись остаётся «legacy», но не сломается.
                    DB::table('request_items')
                        ->where('id', $item->id)
                        ->update(['catalog_item_id' => $oldCatalogId]);
                    $stats['restored']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("  #{$item->id}: " . $e->getMessage());
            }
        }

        $this->line('');
        $this->info(sprintf(
            'Готово: rematched=%d · restored=%d · errors=%d',
            $stats['rematched'], $stats['restored'], $stats['errors'],
        ));

        return self::SUCCESS;
    }
}
