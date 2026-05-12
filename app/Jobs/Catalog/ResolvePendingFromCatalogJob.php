<?php

namespace App\Jobs\Catalog;

use App\Services\Catalog\CatalogResolutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bulk-резолв `internal_catalog_pending` позиций после успешного
 * импорта каталога. Дёргается из CatalogImportController после
 * успешной транзакции CatalogImportService.
 *
 * ShouldBeUnique с окном 1 минута — если в эту минуту прилетели
 * два snapshot'а подряд (например, retry от офисного скрипта), нет
 * смысла гонять резолв дважды; следующий импорт всё равно перетрёт
 * последние данные.
 */
class ResolvePendingFromCatalogJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function uniqueId(): string
    {
        return 'resolve-pending-from-catalog';
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    public function handle(CatalogResolutionService $service): void
    {
        $service->resolveAllPending();
    }
}
