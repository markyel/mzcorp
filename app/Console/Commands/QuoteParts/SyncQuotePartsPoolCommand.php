<?php

namespace App\Console\Commands\QuoteParts;

use App\Jobs\QuoteParts\SyncQuotePartsPoolJob;
use App\Models\CatalogItem;
use App\Models\CatalogSupplierPrice;
use App\Models\QuotePartsPoolItem;
use App\Services\QuoteParts\QuotePartsPoolService;
use Illuminate\Console\Command;

/**
 * Досылка пула в QuoteParts после загрузок каталога (по расписанию) и ручной прогон.
 * --dry-run печатает, что ушло бы по первым позициям пула, ничего не отправляя.
 */
class SyncQuotePartsPoolCommand extends Command
{
    protected $signature = 'quoteparts:sync-pool {--dry-run : Показать передаваемую карточку первых позиций} {--limit=3}';

    protected $description = 'Передать в QuoteParts новые и изменившиеся позиции пула';

    public function handle(QuotePartsPoolService $pool): int
    {
        if ($this->option('dry-run')) {
            $rows = QuotePartsPoolItem::query()->inPool()->limit((int) $this->option('limit'))->get();
            if ($rows->isEmpty()) {
                $this->info('Пул пуст.');
            }
            foreach ($rows as $row) {
                $item = CatalogItem::find($row->catalog_item_id);
                $prices = CatalogSupplierPrice::where('catalog_item_id', $row->catalog_item_id)->get();
                $groups = app(\App\Services\Catalog\SupplyGroupService::class)->groupsFor([$item->id])[$item->id] ?? [];
                $this->line(json_encode($pool->payloadFor($item, $prices, $groups), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }

            return self::SUCCESS;
        }

        SyncQuotePartsPoolJob::dispatchSync();
        $this->info('Готово. Статусы — в разделе «QuoteParts».');

        return self::SUCCESS;
    }
}
