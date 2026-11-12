<?php

namespace App\Console\Commands\Catalog;

use App\Services\QuoteParts\PriceDynamicsService;
use Illuminate\Console\Command;

/**
 * Снимок дня для «Результатов» раздела QuoteParts: сколько позиций прайса с актуальной ценой (в наличии / под
 * заказ). Раз в сутки после последней загрузки каталога; повторный запуск в тот же день перезаписывает снимок.
 */
class CatalogPriceMetricsSnapshotCommand extends Command
{
    protected $signature = 'catalog:price-metrics-snapshot';

    protected $description = 'Daily snapshot of price actuality in the catalog';

    public function handle(PriceDynamicsService $dynamics): int
    {
        $v = $dynamics->snapshot();
        $this->info(sprintf('Актуальных: %d из %d; в наличии %d из %d; под заказ %d из %d.',
            $v['actual_total'], $v['active_total'], $v['in_stock_actual'], $v['in_stock_total'], $v['to_order_actual'], $v['to_order_total']));

        return self::SUCCESS;
    }
}
