<?php

namespace App\Jobs\Catalog;

use App\Models\RequestItem;
use App\Services\Catalog\CatalogResolutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Резолв одного chunk'а (≤100) pending RequestItem'ов через
 * CatalogResolutionService::matchOrResolve (A→B→C цепочка).
 *
 * Dispatch'ится из `ResolvePendingFromCatalogJob`, который chunkById
 * пробегает всю выборку и для каждой сотни id'шников запускает этот
 * job. Каждый chunk идёт в очередь отдельным job'ом — параллелятся
 * между worker'ами, retry'ются независимо, не накапливают память.
 *
 * Тюнинг (после 2026-05-24 boevoj test'a):
 *   - chunk size 50 (в caller'е) + $timeout=300 — реальный C-stage
 *     стоит ~2-3с/item (pgvector HNSW + LLM gpt-4o-mini validation),
 *     то есть 50 × 3 = 150с с запасом до 300.
 *   - $tries=1 — retry бесполезен: если timeout сработал, повторный
 *     запуск тоже не уложится. Лучше job-fail и видеть это в
 *     queue:failed, чем тратить worker-минуту повторно.
 */
class ResolvePendingChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    /**
     * @param  array<int, int>  $itemIds  request_items.id для обработки
     */
    public function __construct(public readonly array $itemIds)
    {
    }

    public function handle(CatalogResolutionService $service): void
    {
        if (empty($this->itemIds)) {
            return;
        }

        // Перечитаем items по id'шникам — между dispatch и handle могло
        // пройти время, item мог быть удалён / уже сматчен другим путём.
        // Фильтр повторяет условия из ResolvePendingFromCatalogJob —
        // только что нужно резолвить.
        $items = RequestItem::query()
            ->whereIn('id', $this->itemIds)
            ->where('is_active', true)
            ->whereNull('catalog_item_id')
            ->cursor();

        $stats = ['checked' => 0, 'matched' => 0];
        foreach ($items as $item) {
            $stats['checked']++;
            try {
                if ($service->matchOrResolve($item)) {
                    $stats['matched']++;
                }
            } catch (\Throwable $e) {
                Log::warning('ResolvePendingChunkJob: matchOrResolve failed for item (non-fatal)', [
                    'request_item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
                // Не падаем — следующий item может сматчиться.
            }
        }

        Log::info('ResolvePendingChunkJob done', [
            'chunk_size' => count($this->itemIds),
            'checked' => $stats['checked'],
            'matched' => $stats['matched'],
        ]);
    }
}
