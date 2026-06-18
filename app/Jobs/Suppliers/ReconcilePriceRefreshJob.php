<?php

namespace App\Jobs\Suppliers;

use App\Enums\PriceRefreshState;
use App\Models\Request;
use App\Models\RequestItem;
use App\Services\Supplier\PriceRefreshReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * После импорта каталога: по списку catalog_item_id, у которых цена стала
 * актуальной, находит заявки в состоянии awaiting с отслеживаемыми позициями
 * по этим товарам и пересчитывает их статус обновления цен (Фаза 3.5).
 * Диспатчится из CatalogImportService::import.
 */
class ReconcilePriceRefreshJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /**
     * @param  array<int, int>  $catalogItemIds  товары, ставшие актуальными
     */
    public function __construct(
        public array $catalogItemIds,
    ) {
        $this->onQueue('default');
    }

    public function handle(PriceRefreshReconciler $reconciler): void
    {
        if ($this->catalogItemIds === []) {
            return;
        }

        // Заявки в awaiting, у которых есть отслеживаемая позиция по любому из
        // ставших актуальными товаров.
        $requestIds = RequestItem::query()
            ->where('price_refresh_watched', true)
            ->whereIn('catalog_item_id', $this->catalogItemIds)
            ->whereHas('request', fn ($q) => $q->where('price_refresh_state', PriceRefreshState::Awaiting->value))
            ->distinct()
            ->pluck('request_id')
            ->all();

        if ($requestIds === []) {
            return;
        }

        Request::query()->whereIn('id', $requestIds)->get()
            ->each(fn (Request $r) => $reconciler->reconcile($r));
    }
}
