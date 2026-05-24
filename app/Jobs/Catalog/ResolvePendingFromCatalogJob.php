<?php

namespace App\Jobs\Catalog;

use App\Models\RequestItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Bulk-резолв `internal_catalog_pending` позиций после успешного
 * импорта каталога. Дёргается из CatalogImportController после
 * успешной транзакции CatalogImportService.
 *
 * **Phase rebuild**: вместо обработки всех items в одном job'е (что
 * раньше валилось по timeout=120s при тысячах items с C-stage
 * pgvector+LLM), теперь только chunk-dispatcher:
 *   1. chunkById(100) пробегает выборку pending items.
 *   2. Для каждой сотни id'шников dispatch отдельный
 *      ResolvePendingChunkJob, который и обрабатывает (≤120с на 100).
 *
 * Преимущества:
 *   - параллелизм между worker'ами (4 worker'а × 100 items = 400/раз);
 *   - memory не накапливается между chunk'ами (worker завершается,
 *     --memory=600 защищает);
 *   - retry на chunk'е изолирован — fail одной сотни не валит весь
 *     resolve;
 *   - этот job сам быстрый (только SELECT id + dispatch), укладывается
 *     в любой timeout.
 *
 * ShouldBeUnique окно 1 минута — если в эту минуту прилетели два
 * snapshot'а подряд, повторный resolve не запускается.
 */
class ResolvePendingFromCatalogJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 120; // dispatch'ер сам по себе быстрый — оставляем запас.

    // 2026-05-24: уменьшили с 100 до 50 после боевого теста — C-stage
    // (vector+LLM) стоит ~2-3с/item, 50 items × 3 = 150с укладывается
    // в timeout=300 ChunkJob'а с запасом. 100 валилось по timeout.
    private const CHUNK_SIZE = 50;

    public function uniqueId(): string
    {
        return 'resolve-pending-from-catalog';
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    public function handle(): void
    {
        $totalDispatched = 0;
        $chunks = 0;

        RequestItem::query()
            ->where('is_active', true)
            ->whereNull('catalog_item_id')
            ->where(function ($q) {
                $q->where('quality_assessment_status', 'internal_catalog_pending')
                    ->orWhereNotNull('parsed_article')
                    ->orWhereNotNull('parsed_name');
            })
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function ($items) use (&$totalDispatched, &$chunks) {
                $ids = $items->pluck('id')->all();
                if (! empty($ids)) {
                    ResolvePendingChunkJob::dispatch($ids);
                    $totalDispatched += count($ids);
                    $chunks++;
                }
            });

        Log::info('ResolvePendingFromCatalogJob: chunks dispatched', [
            'chunks' => $chunks,
            'total_items' => $totalDispatched,
            'chunk_size' => self::CHUNK_SIZE,
        ]);
    }
}
