<?php

namespace App\Services\Supplier;

use App\Models\RequestItem;
use App\Models\Supplier;
use Illuminate\Support\Collection;

/**
 * Подбор релевантных поставщиков под позицию заявки (Фаза 3.1, Foundation §4.2).
 * Релевантен поставщик, чья матрица ассортимента покрывает БРЕНД И КАТЕГОРИЮ
 * позиции (или явную пару бренд×категория). Матч по тексту с нормализацией —
 * таксономии каталога/KB и описаний поставщиков разные (см. [[catalog-matching]]).
 *
 * Контракт для Фазы 3.2 (dispatch): группировка supplier × items[].
 */
class SupplierMatchService
{
    /**
     * Релевантные поставщики под позицию (из тех, у кого построена матрица).
     *
     * @return Collection<int, Supplier>
     */
    public function relevantSuppliers(RequestItem $item): Collection
    {
        $brand = $this->norm($item->brand?->name ?: (string) $item->parsed_brand);
        $categoryTerms = $this->itemCategoryTerms($item);

        if ($brand === '' && $categoryTerms === []) {
            return collect(); // ни бренда, ни категории — подбирать не по чему
        }

        return Supplier::query()
            ->whereNotNull('assortment_matrix')
            ->get()
            ->filter(fn (Supplier $s) => $this->matches($s, $brand, $categoryTerms))
            ->values();
    }

    /**
     * Покрывает ли матрица поставщика данные бренд + категорию.
     *
     * @param  array<int, string>  $categoryTerms  нормализованные термины категории (имя + синонимы)
     */
    public function matches(Supplier $supplier, string $brand, array $categoryTerms): bool
    {
        $m = is_array($supplier->assortment_matrix) ? $supplier->assortment_matrix : [];
        $brands = array_map([$this, 'norm'], (array) ($m['brands'] ?? []));
        $cats = array_map([$this, 'norm'], (array) ($m['categories'] ?? []));

        $brandKnown = $brand !== '';
        $catKnown = $categoryTerms !== [];

        // Явные пары бренд×категория — сильнейший сигнал.
        foreach ((array) ($m['pairs'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $pb = $this->norm((string) ($p['brand'] ?? ''));
            $pc = $this->norm((string) ($p['category'] ?? ''));
            $pbHit = ! $brandKnown || $this->softEq($pb, $brand);
            $pcHit = ! $catKnown || $this->termHit([$pc], $categoryTerms);
            if ($pbHit && $pcHit && ($brandKnown || $catKnown)) {
                return true;
            }
        }

        $brandHit = $brandKnown && $this->listHit($brands, [$brand]);
        $catHit = $catKnown && $this->listHit($cats, $categoryTerms);

        if ($brandKnown && $catKnown) {
            return $brandHit && $catHit;
        }
        if ($brandKnown) {
            return $brandHit;
        }

        return $catHit;
    }

    /**
     * Термины категории позиции: имя KB-категории + синонимы. Пусто если
     * категория не определена.
     *
     * @return array<int, string>
     */
    private function itemCategoryTerms(RequestItem $item): array
    {
        $cat = $item->relationLoaded('kbCategory') ? $item->kbCategory : $item->kbCategory()->first();
        if ($cat === null) {
            return [];
        }
        $terms = [(string) $cat->name];
        foreach ((array) ($cat->synonyms ?? []) as $syn) {
            if (is_string($syn) && trim($syn) !== '') {
                $terms[] = $syn;
            }
        }

        return array_values(array_unique(array_filter(array_map([$this, 'norm'], $terms))));
    }

    /**
     * Любой из терминов пересекается со списком (soft: подстрока в любую сторону).
     *
     * @param  array<int, string>  $list
     * @param  array<int, string>  $terms
     */
    private function listHit(array $list, array $terms): bool
    {
        foreach ($list as $l) {
            if ($this->termHit([$l], $terms)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $listTerms
     * @param  array<int, string>  $terms
     */
    private function termHit(array $listTerms, array $terms): bool
    {
        foreach ($listTerms as $l) {
            if ($l === '') {
                continue;
            }
            foreach ($terms as $t) {
                if ($t === '') {
                    continue;
                }
                if ($this->softEq($l, $t) || str_contains($l, $t) || str_contains($t, $l)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function softEq(string $a, string $b): bool
    {
        return $a !== '' && $a === $b;
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[«»"„“”]/u', '', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }
}
