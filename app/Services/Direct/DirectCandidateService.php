<?php

namespace App\Services\Direct;

use App\Models\CatalogItem;
use App\Models\DirectExcludedItem;
use App\Models\User;
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
              and not exists (select 1 from direct_excluded_items x where x.catalog_item_id = catalog_items.id)
        ")->n);
    }

    public function forget(): void
    {
        Cache::forget('direct:ready_count');
        for ($i = 1; $i <= 500; $i++) {
            Cache::forget('direct:candidates:'.$i);
        }
    }

    /**
     * Исключить позицию из рекламы: по складу и цене она подходит, но сама по
     * себе спросом не пользуется (расходники, комплектующие к другому товару).
     */
    public function exclude(string $sku, ?string $reason, ?User $by): ?DirectExcludedItem
    {
        $item = CatalogItem::query()->where('sku', $sku)->first(['id', 'sku']);
        if ($item === null) {
            return null;
        }

        $excluded = DirectExcludedItem::updateOrCreate(
            ['catalog_item_id' => $item->id],
            [
                'sku' => $item->sku,
                'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 200) : null,
                'excluded_by_user_id' => $by?->id,
            ],
        );
        $this->forget();
        Cache::forget('direct:excluded_ids');
        Cache::forget('yandex_direct_feed:products_yml');

        return $excluded;
    }

    /** Вернуть позицию в очередь. */
    public function restore(string $sku): bool
    {
        $deleted = DirectExcludedItem::query()->where('sku', $sku)->delete() > 0;
        if ($deleted) {
            $this->forget();
            Cache::forget('direct:excluded_ids');
            Cache::forget('yandex_direct_feed:products_yml');
        }

        return $deleted;
    }

    /** @return Collection<int, DirectExcludedItem> */
    public function excluded(): Collection
    {
        return DirectExcludedItem::query()
            ->with(['catalogItem:id,sku,name,price,stock_available', 'excludedBy:id,name'])
            ->orderByDesc('id')
            ->get();
    }

    /** id исключённых позиций — общий фильтр для очереди и фида. */
    public static function excludedItemIds(): array
    {
        return Cache::remember(
            'direct:excluded_ids',
            self::CACHE_TTL,
            fn () => DirectExcludedItem::query()->pluck('catalog_item_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    private function sql(): string
    {
        $months = self::DEMAND_MONTHS;

        return "
            with ready as (
                select id, sku, name, brand, brand_article, price, stock_available, articles
                from catalog_items
                where is_active and stock_available > 0 and price > 0 and is_price_actual
                  -- вручную исключённые из рекламы (раздел «Директ»)
                  and not exists (select 1 from direct_excluded_items x where x.catalog_item_id = catalog_items.id)
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
