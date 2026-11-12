<?php

namespace App\Services\QuoteParts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Результаты раздела QuoteParts (12.11.2026): растёт ли доля позиций прайса с актуальной ценой (в наличии / под
 * заказ) и куда идут закупочные цены — дешевеют закупки или дорожают. Истории до 12.11.2026 нет: импорт каталога
 * её не хранил, данные копятся с этого дня (снимок раз в сутки, изменения закупочной цены — при каждой загрузке).
 */
class PriceDynamicsService
{
    /** Изменение меньше этого (в долях) — «без изменений»: копейки округлений 1С. */
    public const FLAT = 0.005;

    /** Снимок дня: прайс — активные позиции каталога; «в наличии» — остаток больше нуля. */
    public function snapshot(?Carbon $day = null): array
    {
        $row = DB::table('catalog_items')->where('is_active', true)->selectRaw(
            'count(*) as active_total,
             count(*) filter (where is_price_actual) as actual_total,
             count(*) filter (where coalesce(stock_available, 0) > 0) as in_stock_total,
             count(*) filter (where coalesce(stock_available, 0) > 0 and is_price_actual) as in_stock_actual'
        )->first();
        $values = [
            'active_total' => (int) $row->active_total,
            'actual_total' => (int) $row->actual_total,
            'in_stock_total' => (int) $row->in_stock_total,
            'in_stock_actual' => (int) $row->in_stock_actual,
            'to_order_total' => (int) $row->active_total - (int) $row->in_stock_total,
            'to_order_actual' => (int) $row->actual_total - (int) $row->in_stock_actual,
        ];
        DB::table('price_actuality_snapshots')->updateOrInsert(
            ['snapshot_on' => ($day ?? now())->toDateString()],
            $values + ['updated_at' => now(), 'created_at' => now()],
        );

        return $values;
    }

    /**
     * Доля актуальных цен по дням: всего, в наличии, под заказ (в процентах).
     *
     * @return list<array{day: string, all: float, in_stock: float, to_order: float, actual: int, total: int}>
     */
    public function actuality(int $days = 120): array
    {
        return DB::table('price_actuality_snapshots')->where('snapshot_on', '>=', now()->subDays($days)->toDateString())
            ->orderBy('snapshot_on')->get()
            ->map(fn ($r) => [
                'day' => (string) $r->snapshot_on,
                'all' => self::pct($r->actual_total, $r->active_total),
                'in_stock' => self::pct($r->in_stock_actual, $r->in_stock_total),
                'to_order' => self::pct($r->to_order_actual, $r->to_order_total),
                'actual' => (int) $r->actual_total,
                'total' => (int) $r->active_total,
            ])->all();
    }

    /**
     * Куда идут цены закупки (решение заказчика 12.11.2026). Событие — закупочное решение: цена «Закупаем здесь»,
     * выгруженная в 1С с завершением сбора цен (price_purchase_decisions). Сравнение — с последней известной ценой
     * позиции в той же валюте до решения: прошлым решением или закупкой по выгрузке истории цен 1С, что новее.
     * Закупочная цена каталога не годится: в ней логистика и таможня. По неделям решения: сколько дешевле, сколько
     * дороже, медиана изменения; решения без сопоставимой прошлой цены — отдельно.
     *
     * @return list<array{week: string, n: int, up: int, down: int, flat: int, median: float, unmatched: int, vs_own: int}>
     */
    public function purchaseTrend(int $weeks = 26): array
    {
        $since = now()->subWeeks($weeks)->startOfWeek();
        $decisions = DB::table('price_purchase_decisions')->where('decided_at', '>=', $since)
            ->get(['catalog_item_id', 'price', 'currency', 'decided_at']);
        if ($decisions->isEmpty()) {
            return [];
        }
        $items = $decisions->pluck('catalog_item_id')->unique();
        $earlier = DB::table('price_purchase_decisions')->whereIn('catalog_item_id', $items)
            ->get(['catalog_item_id', 'price', 'currency', 'decided_at as at'])->map(fn ($r) => (object) ((array) $r + ['own' => true]));
        $purchases = DB::table('catalog_supplier_prices')->whereIn('catalog_item_id', $items)->where('price', '>', 0)->whereNotNull('priced_at')
            ->get(['catalog_item_id', 'price', 'currency', 'priced_at as at'])->map(fn ($r) => (object) ((array) $r + ['own' => false]));

        return self::trend($decisions, $earlier->concat($purchases)->groupBy('catalog_item_id'));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $decisions  catalog_item_id, price, currency, decided_at
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, object>>  $known  по позиции: прошлые цены (at, own)
     * @return list<array{week: string, n: int, up: int, down: int, flat: int, median: float, unmatched: int, vs_own: int}>
     */
    public static function trend(\Illuminate\Support\Collection $decisions, \Illuminate\Support\Collection $known): array
    {
        return $decisions->groupBy(fn ($d) => Carbon::parse($d->decided_at)->startOfWeek()->toDateString())->sortKeys()
            ->map(function ($group, $week) use ($known) {
                $changes = [];
                $unmatched = 0;
                $vsOwn = 0;
                foreach ($group as $d) {
                    $at = Carbon::parse($d->decided_at);
                    $currency = self::currency($d->currency);
                    // Последняя известная цена до решения: прошлое решение или закупка 1С — что новее.
                    $prev = ($known[$d->catalog_item_id] ?? collect())
                        ->filter(fn ($p) => self::currency($p->currency) === $currency && (float) $p->price > 0 && Carbon::parse($p->at)->lt($at))
                        ->sortByDesc(fn ($p) => Carbon::parse($p->at)->getTimestamp())->first();
                    if ($prev === null) {
                        $unmatched++;

                        continue;
                    }
                    $vsOwn += $prev->own ? 1 : 0;
                    $changes[] = (float) $d->price / (float) $prev->price - 1;
                }
                $changes = collect($changes);

                return [
                    'week' => (string) $week,
                    'n' => $changes->count(),
                    'up' => $changes->filter(fn ($x) => $x > self::FLAT)->count(),
                    'down' => $changes->filter(fn ($x) => $x < -self::FLAT)->count(),
                    'flat' => $changes->filter(fn ($x) => abs($x) <= self::FLAT)->count(),
                    'median' => round(self::median($changes->all()) * 100, 1),
                    'unmatched' => $unmatched,
                    'vs_own' => $vsOwn,
                ];
            })->values()->all();
    }

    /** Валюта для сравнения: «руб»/«руб.»/«RUR» — RUB. */
    public static function currency(?string $currency): string
    {
        $c = mb_strtoupper(trim((string) $currency));

        return in_array($c, ['РУБ', 'РУБ.', 'RUR', '₽', ''], true) ? 'RUB' : $c;
    }

    private static function pct(int|string|null $part, int|string|null $whole): float
    {
        return (int) $whole > 0 ? round((int) $part / (int) $whole * 100, 1) : 0.0;
    }

    /** @param list<float> $sorted */
    public static function median(array $sorted): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        sort($sorted);

        return $n % 2 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2;
    }
}
