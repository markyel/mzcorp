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

        // Группа 1: RFQ-заявки в awaiting с отслеживаемой позицией по любому из
        // ставших актуальными товаров.
        $awaitingIds = RequestItem::query()
            ->where('price_refresh_watched', true)
            ->whereIn('catalog_item_id', $this->catalogItemIds)
            ->whereHas('request', fn ($q) => $q->where('price_refresh_state', PriceRefreshState::Awaiting->value))
            ->distinct()
            ->pluck('request_id')
            ->all();

        if ($awaitingIds !== []) {
            Request::query()->whereIn('id', $awaitingIds)->get()
                ->each(fn (Request $r) => $reconciler->reconcile($r));
        }

        // Группа 2: рабочие заявки БЕЗ активного цикла (state=null), у которых
        // активная сматченная позиция = только что актуализированный товар.
        $workingIds = RequestItem::query()
            ->where('is_active', true)
            ->whereNotNull('catalog_item_id')
            ->whereIn('catalog_item_id', $this->catalogItemIds)
            ->whereHas('request', fn ($q) => $q->whereNull('price_refresh_state'))
            ->distinct()
            ->pluck('request_id')
            ->all();

        if ($workingIds !== []) {
            Request::query()->whereIn('id', $workingIds)->get()
                ->each(fn (Request $r) => $reconciler->reconcileWorking($r));
        }
    }
}
