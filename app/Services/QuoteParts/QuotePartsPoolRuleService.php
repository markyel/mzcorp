<?php

namespace App\Services\QuoteParts;

use App\Models\QuotePartsPoolItem;
use App\Models\QuotePartsPoolRule;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Подписки пула QuoteParts: позиции «группы + фильтров» держатся в пуле сами.
 *
 *  - match(): какие позиции каталога подходят (активные карточки);
 *  - apply(): добавить подходящие, которых в пуле нет (source = rule) — пачками, без
 *    передачи: её делает фоновая синхронизация порциями;
 *  - stale(): позиции, пришедшие по правилам и больше не подходящие ни к одному
 *    активному, — не удаляются сами, а предлагаются к удалению.
 */
class QuotePartsPoolRuleService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function match(?string $groupCode, array $filters): Builder
    {
        $q = DB::table('catalog_items')->where('catalog_items.is_active', true)->select('catalog_items.id');

        if ($groupCode !== null && $groupCode !== '') {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('catalog_item_supply_groups as g')
                ->whereColumn('g.catalog_item_id', 'catalog_items.id')->where('g.group_code', $groupCode));
        }
        if (! empty($filters['purchased_years'])) {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('catalog_supplier_prices as p')
                ->whereColumn('p.catalog_item_id', 'catalog_items.id')->where('p.priced_at', '>=', now()->subYears((int) $filters['purchased_years'])));
        }
        if (! empty($filters['requested_days'])) {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('request_items as ri')
                ->whereColumn('ri.catalog_item_id', 'catalog_items.id')->where('ri.created_at', '>=', now()->subDays((int) $filters['requested_days'])));
        }
        if (! empty($filters['in_stock'])) {
            $q->where('catalog_items.stock_available', '>', 0);
        }
        if (! empty($filters['price_not_actual'])) {
            $q->where('catalog_items.is_price_actual', false);
        }
        $brands = array_values(array_filter(array_map(fn ($b) => trim((string) $b), (array) ($filters['brands'] ?? []))));
        if ($brands !== []) {
            $q->where(fn ($w) => collect($brands)->each(fn ($b) => $w->orWhere('catalog_items.brand', 'ilike', '%'.$b.'%')));
        }

        return $q;
    }

    /**
     * Сколько подходит и сколько из них ещё не в пуле.
     *
     * @param  array<string, mixed>  $filters
     * @return array{matched:int, new:int}
     */
    public function preview(?string $groupCode, array $filters): array
    {
        $matched = (clone $this->match($groupCode, $filters))->count();
        $new = (clone $this->match($groupCode, $filters))
            ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('quoteparts_pool_items as pi')
                ->whereColumn('pi.catalog_item_id', 'catalog_items.id')->whereNull('pi.removed_at'))
            ->count();

        return ['matched' => $matched, 'new' => $new];
    }

    /**
     * Применить правило (или все активные): подходящие позиции — в пул.
     *
     * @return int сколько добавлено
     */
    public function apply(?QuotePartsPoolRule $rule = null, ?User $by = null): int
    {
        $rules = $rule ? collect([$rule]) : QuotePartsPoolRule::query()->where('is_active', true)->get();
        $added = 0;
        foreach ($rules as $r) {
            $ids = $this->match($r->group_code, (array) $r->filters)->pluck('catalog_items.id')->map(fn ($id) => (int) $id)->all();
            $added += $this->addToPool($ids, $by ?? $r->createdBy, 'rule');
            $r->forceFill(['matched_count' => count($ids), 'applied_at' => now()])->save();
        }

        return $added;
    }

    /**
     * Пачками в пул: новые строки, а убранные раньше — вернуть (передача — фоновой синхронизацией).
     *
     * @param  list<int>  $catalogItemIds
     */
    public function addToPool(array $catalogItemIds, ?User $by, string $source): int
    {
        $added = 0;
        foreach (array_chunk(array_values(array_unique($catalogItemIds)), 1000) as $chunk) {
            $existing = QuotePartsPoolItem::query()->whereIn('catalog_item_id', $chunk)->get(['id', 'catalog_item_id', 'removed_at'])->keyBy('catalog_item_id');
            $revive = $existing->whereNotNull('removed_at')->pluck('id')->all();
            if ($revive !== []) {
                QuotePartsPoolItem::query()->whereIn('id', $revive)->update([
                    'status' => QuotePartsPoolItem::STATUS_PENDING, 'removed_at' => null, 'payload_hash' => null,
                    'last_error' => null, 'source' => $source, 'added_by_user_id' => $by?->id, 'updated_at' => now(),
                ]);
                $added += count($revive);
            }
            $missing = array_values(array_diff($chunk, $existing->keys()->all()));
            if ($missing !== []) {
                $skus = DB::table('catalog_items')->whereIn('id', $missing)->pluck('sku', 'id');
                $rows = [];
                foreach ($missing as $id) {
                    if (isset($skus[$id])) {
                        $rows[] = ['catalog_item_id' => $id, 'sku' => $skus[$id], 'status' => QuotePartsPoolItem::STATUS_PENDING,
                            'source' => $source, 'added_by_user_id' => $by?->id, 'created_at' => now(), 'updated_at' => now()];
                    }
                }
                QuotePartsPoolItem::query()->insert($rows);
                $added += count($rows);
            }
        }

        return $added;
    }

    /**
     * Пришли по правилам, но ни к одному активному больше не подходят.
     */
    public function stale(): \Illuminate\Database\Eloquent\Builder
    {
        $q = QuotePartsPoolItem::query()->inPool()->where('source', 'rule');
        foreach (QuotePartsPoolRule::query()->where('is_active', true)->get() as $r) {
            $match = $this->match($r->group_code, (array) $r->filters);
            $q->whereNotIn('catalog_item_id', $match);
        }

        return $q;
    }
}
