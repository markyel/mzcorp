<?php

namespace App\Services\Procurement;

use App\Models\PriceMonitor;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Пул «К актуализации»: позиции с неактуальной ценой, по которым был спрос за
 * последние WINDOW_DAYS дней. Две причины попадания, сортировка по ним же:
 *   1 — лежит на складе и цены нет: продать можно прямо сейчас, мешает цена;
 *   2 — регулярный спрос (от REGULAR_MIN_REQUESTS заявок за окно).
 * Позиции на мониторинге помечаются, но из списка не убираются.
 *
 * Общий источник для «Снабжения» (вкладка «К актуализации») и раздела «QuoteParts»:
 * оба показывают один и тот же список.
 */
class PriceRefreshCandidates
{
    public const WINDOW_DAYS = 30;

    /** Порог «регулярного спроса» — решение заказчика. */
    public const REGULAR_MIN_REQUESTS = 2;

    /**
     * @param  (Closure(Builder): void)|null  $tap  дополнительный отбор (например, по группе поставщиков)
     * @return list<array{cid:int, sku:string, name:string, brand:?string, price:mixed, stock:int, req_count:int, last_at:mixed, priority:int, reason:string, monitored_until:mixed}>
     */
    public function list(?Closure $tap = null): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $rows = DB::table('request_items')
            ->join('catalog_items', 'catalog_items.id', '=', 'request_items.catalog_item_id')
            ->join('requests', 'requests.id', '=', 'request_items.request_id')
            ->where('request_items.is_active', true)
            ->where('catalog_items.is_active', true)
            ->where('catalog_items.is_price_actual', false)
            ->whereNull('requests.merged_into_id')
            ->where('requests.created_at', '>=', $since)
            ->when($tap, fn (Builder $q) => $tap($q))
            ->select(
                'catalog_items.id as cid',
                'catalog_items.sku',
                'catalog_items.name',
                'catalog_items.brand',
                'catalog_items.price',
                'catalog_items.stock_available',
                DB::raw('count(distinct requests.id) as req_count'),
                DB::raw('max(requests.created_at) as last_at'),
            )
            ->groupBy('catalog_items.id', 'catalog_items.sku', 'catalog_items.name', 'catalog_items.brand',
                'catalog_items.price', 'catalog_items.stock_available')
            ->get();

        $monitored = PriceMonitor::query()->where('is_active', true)->pluck('next_due_at', 'catalog_item_id');

        $out = [];
        foreach ($rows as $r) {
            $stock = (int) ($r->stock_available ?? 0);
            $reqs = (int) $r->req_count;
            $priority = $stock > 0 ? 1 : ($reqs >= self::REGULAR_MIN_REQUESTS ? 2 : 0);
            if ($priority === 0) {
                continue;
            }
            $out[] = [
                'cid' => (int) $r->cid,
                'sku' => $r->sku,
                'name' => $r->name,
                'brand' => $r->brand,
                'price' => $r->price,
                'stock' => $stock,
                'req_count' => $reqs,
                'last_at' => $r->last_at,
                'priority' => $priority,
                'reason' => $priority === 1 ? 'На складе, цены нет' : 'Регулярный спрос',
                'monitored_until' => $monitored[$r->cid] ?? null,
            ];
        }
        usort($out, fn ($a, $b) => [$a['priority'], -$a['req_count'], -$a['stock']]
            <=> [$b['priority'], -$b['req_count'], -$b['stock']]);

        return $out;
    }
}
