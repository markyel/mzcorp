<?php

namespace App\Jobs\Catalog;

use App\Services\Catalog\CatalogEmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 2 use-case C: incremental update эмбеддингов каталога после
 * `CatalogImportService::import()`. Запускается из API-контроллера
 * сразу после успешной транзакции импорта, в той же логике что
 * ResolvePendingFromCatalogJob.
 *
 * Internally дёргает `CatalogEmbeddingService::syncAll(force: false)` —
 * проходит по всем active items, считает source_hash, и embed'ит только
 * те, где хеш не совпал с сохранённым в catalog_item_embeddings.
 *
 * ShouldBeUnique с окном 5 минут — защита от двойного запуска при
 * retry POST /api/catalog/import.
 *
 * Стоимость: при инкрементальной выгрузке (10-50 изменений в день)
 * ~$0.0001/прогон. На full re-sync (первая заливка / смена модели)
 * ~$0.05 на 30k items.
 */
class EmbedCatalogChangesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800; // 30 минут, на случай первой full-sync

    public function uniqueId(): string
    {
        return 'embed-catalog-changes';
    }

    public function uniqueFor(): int
    {
        return 5 * 60;
    }

    public function handle(CatalogEmbeddingService $svc): void
    {
        if (! (bool) config('services.catalog_name_match.enabled', true)) {
            return;
        }
        $svc->syncAll(force: false);
    }
}
