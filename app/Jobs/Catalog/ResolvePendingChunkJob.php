<?php

namespace App\Jobs\Catalog;

use App\Models\RequestItem;
use App\Services\Catalog\CatalogResolutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Резолв одного chunk'а (≤50) pending RequestItem'ов через
 * CatalogResolutionService::matchOrResolve (A→B→C цепочка).
 *
 * Dispatch'ится из `ResolvePendingFromCatalogJob`, который chunkById
 * пробегает всю выборку и для каждой сотни id'шников запускает этот
 * job. Каждый chunk идёт в очередь отдельным job'ом — параллелятся
 * между worker'ами, retry'ются независимо, не накапливают память.
 *
 * Очередь `catalog-resolve` — отдельная от `default` и `mail-sync`.
 * Инцидент 2026-05-28: этот job зацикливался по таймауту (300с не
 * хватало на 50 items в плохую минуту), Laravel пытался mark-failed
 * + INSERT в failed_jobs, supervisor restart возвращал reserved job
 * в очередь с прежним UUID → коллизия `failed_jobs_uuid_unique` →
 * 4 воркера часами крутили этот цикл, забив default очередь.
 *
 * Тюнинг (после 2026-05-28 post-mortem):
 *   - timeout 300 → 600: запас даже при медленном LLM/pgvector.
 *   - ShouldBeUnique по hash itemIds + window 5 мин: повторный
 *     dispatch того же chunk (если кто-то re-released из reserved)
 *     отказывается — нет двух attempt'ов на один и тот же набор.
 *   - $tries=1 сохраняем: retry бесполезен, если действительно
 *     timeout. Лучше job-fail в queue:failed, чем worker-минута
 *     повторно.
 *   - failOnTimeout=true: явно помечаем как failed в catch worker'а,
 *     не позволяя оставаться reserved.
 */
class ResolvePendingChunkJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Тип не пишем (string) — Queueable trait объявляет `public $queue;`
    // без типа, в PHP 8 несовпадающий тип в trait composition = Fatal.
    public $queue = 'catalog-resolve';
    public int $tries = 1;
    public int $timeout = 600;
    public bool $failOnTimeout = true;

    /**
     * @param  array<int, int>  $itemIds  request_items.id для обработки
     */
    public function __construct(public readonly array $itemIds)
    {
    }

    /**
     * Уникальный ключ — md5 от sorted itemIds. Два одинаковых
     * chunk'а в окне 5 минут не запустятся параллельно. Защита
     * от race при worker restart + reserved-reaquire.
     */
    public function uniqueId(): string
    {
        $sorted = $this->itemIds;
        sort($sorted);
        return 'resolve-chunk-'.md5(implode(',', $sorted));
    }

    public function uniqueFor(): int
    {
        return 300; // 5 мин
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
