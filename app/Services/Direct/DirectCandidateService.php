<?php

namespace App\Services\Direct;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Очередь позиций на рекламу в Директе.
 *
 * Рекламируем только то, на что можем сразу дать цену: остаток на складе И
 * актуальная цена — тот же критерий, по которому заявка с явным артикулом
 * уходит в автоматическое КП.
 *
 * Порядок очереди — по деньгам: сколько эта позиция принесла оплаченными
 * счетами за год, затем по числу заявок. Первые N (настройка «сколько
 * объявлений держим») идут в работу, остальные ждут.
 */
class DirectCandidateService
{
    /** Окно, за которое считаем спрос и деньги. */
    public const DEMAND_MONTHS = 12;

    private const CACHE_TTL = 600; // 10 минут: каталог меняется импортом пару раз в сутки

    /**
     * Очередь кандидатов с метриками спроса.
     *
     * @return Collection<int, object>
     */
    public function queue(int $limit = 50): Collection
    {
        $limit = max(1, min($limit, 500));

        return Cache::remember(
            'direct:candidates:'.$limit,
            self::CACHE_TTL,
            fn () => collect(DB::select($this->sql(), [$limit]))
        );
    }

    /** Сколько позиций вообще годны к показу (весь пул, без ограничения). */
    public function readyCount(): int
    {
        return (int) Cache::remember('direct:ready_count', self::CACHE_TTL, fn () => DB::selectOne("
            select count(*) n from catalog_items
            where is_active and stock_available > 0 and price > 0 and is_price_actual
        ")->n);
    }

    public function forget(): void
    {
        Cache::forget('direct:ready_count');
        for ($i = 1; $i <= 500; $i++) {
            Cache::forget('direct:candidates:'.$i);
        }
    }

    private function sql(): string
    {
        $months = self::DEMAND_MONTHS;

        return "
            with ready as (
                select id, sku, name, brand, brand_article, price, stock_available, articles
                from catalog_items
                where is_active and stock_available > 0 and price > 0 and is_price_actual
            ),
            demand as (
                select ri.catalog_item_id cid,
                       count(distinct r.id) reqs,
                       count(distinct r.id) filter (where r.status = 'closed_won') won,
                       coalesce(sum(distinct i.amount_snapshot), 0) paid
                from request_items ri
                join requests r on r.id = ri.request_id
                left join invoices i on i.request_id = r.id and i.status = 'paid'
                where r.created_at > now() - interval '{$months} months'
                  and ri.catalog_item_id is not null
                group by ri.catalog_item_id
            )
            select ready.sku, ready.name, ready.brand, ready.brand_article,
                   ready.price, ready.stock_available, ready.articles,
                   coalesce(demand.reqs, 0) reqs,
                   coalesce(demand.won, 0) won,
                   coalesce(demand.paid, 0) paid
            from ready
            left join demand on demand.cid = ready.id
            order by coalesce(demand.paid, 0) desc, coalesce(demand.reqs, 0) desc, ready.sku
            limit ?
        ";
    }
}
