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
    /**
     * Подбор поставщиков под КАТАЛОЖНУЮ позицию (M-артикул) — для раздела
     * «Снабжение» (Фаза 4B). Бренд/категория берём прямо из catalog_item.
     * Бренд каталога — свободная строка (не обязательно из 43 KB-брендов),
     * поэтому brandCanonical=false (не исключаем по бренду, recall-friendly).
     *
     * @return Collection<int, Supplier>
     */
    public function relevantSuppliersForCatalog(\App\Models\CatalogItem $ci): Collection
    {
        $brand = $this->norm((string) ($ci->brand ?? ''));

        $cat = $ci->relationLoaded('equipmentCategory') ? $ci->equipmentCategory : $ci->equipmentCategory()->first();
        $categoryTerms = [];
        if ($cat !== null) {
            $terms = [(string) $cat->name];
            foreach ((array) ($cat->synonyms ?? []) as $syn) {
                if (is_string($syn) && trim($syn) !== '') {
                    $terms[] = $syn;
                }
            }
            $categoryTerms = array_values(array_unique(array_filter(array_map([$this, 'norm'], $terms))));
        }

        if ($brand === '' && $categoryTerms === []) {
            return collect();
        }

        return Supplier::query()
            ->whereNotNull('assortment_matrix')
            ->get()
            ->filter(fn (Supplier $s) => $this->matches($s, $brand, $categoryTerms, false))
            ->values();
    }

    public function relevantSuppliers(RequestItem $item): Collection
    {
        $brand = $this->itemBrand($item);
        $categoryTerms = $this->itemCategoryTerms($item);
        // Каноничный бренд = распознан KB-резолвером (manufacturer_brand_id).
        // Только такой бренд может ИСКЛЮЧИТЬ поставщика; свободный/каталожный
        // бренд (часто не из наших 43) — не ограничение (макс. recall).
        $brandCanonical = $item->brand !== null;

        if ($brand === '' && $categoryTerms === []) {
            return collect(); // ни бренда, ни категории — подбирать не по чему
        }

        return Supplier::query()
            ->whereNotNull('assortment_matrix')
            ->get()
            ->filter(fn (Supplier $s) => $this->matches($s, $brand, $categoryTerms, $brandCanonical))
            ->values();
    }

    /**
     * Покрывает ли матрица поставщика позицию. Логика (recall-friendly):
     *  - КАТЕГОРИЯ — основной ключ: если поставщик перечислил категории,
     *    позиция должна попасть в них (иначе не подходит);
     *  - пустой список на измерении = «любой» (не ограничивает);
     *  - бренд ИСКЛЮЧАЕТ только при каноничном конфликте (item-бренд распознан
     *    нашим KB, поставщик перечислил бренды, и его там нет);
     *  - явная пара бренд×категория — сильный сигнал «подходит».
     *  - пустая матрица (нет ни категорий, ни брендов) — не подходит.
     *
     * @param  array<int, string>  $categoryTerms  нормализованные термины (имя + синонимы)
     */
    public function matches(Supplier $supplier, string $brand, array $categoryTerms, bool $brandCanonical = false): bool
    {
        $m = is_array($supplier->assortment_matrix) ? $supplier->assortment_matrix : [];
        $brands = array_map([$this, 'norm'], (array) ($m['brands'] ?? []));
        $cats = array_map([$this, 'norm'], (array) ($m['categories'] ?? []));

        $brandKnown = $brand !== '';
        $catKnown = $categoryTerms !== [];
        $brandListed = $brands !== [];
        $catListed = $cats !== [];

        $brandIn = $brandListed && $brandKnown && $this->listHit($brands, [$brand]);
        $catIn = $catListed && $catKnown && $this->listHit($cats, $categoryTerms);

        // Явные ПРАВИЛА с wildcard «ВСЕ» (ручные, приоритетны). Правило
        // {brand, category}, где поле = «ВСЕ» или пусто = любой. Примеры:
        //   {Schneider, ВСЕ} — любое оборудование Schneider;
        //   {ВСЕ, Ролик}     — ролики любых марок;
        //   {ВСЕ, ВСЕ}       — всё.
        foreach ((array) ($m['rules'] ?? []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $rb = $this->norm((string) ($rule['brand'] ?? ''));
            $rc = $this->norm((string) ($rule['category'] ?? ''));
            $brandWild = $rb === '' || $rb === 'все';
            $catWild = $rc === '' || $rc === 'все';
            if ($brandWild && $catWild) {
                return true; // {ВСЕ, ВСЕ} — позиция уже имеет бренд или категорию (гард в relevantSuppliers)
            }
            $brandOk = $brandWild || ($brandKnown && $this->listHit([$rb], [$brand]));
            $catOk = $catWild || ($catKnown && $this->termHit([$rc], $categoryTerms));
            if ($brandOk && $catOk) {
                return true;
            }
        }

        // Явные пары бренд×категория — сильный сигнал.
        foreach ((array) ($m['pairs'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $pc = $this->norm((string) ($p['category'] ?? ''));
            $pb = $this->norm((string) ($p['brand'] ?? ''));
            if ($pc !== '' && $catKnown && $this->termHit([$pc], $categoryTerms)
                && (! $brandKnown || ! $brandCanonical || $pb === '' || $this->softEq($pb, $brand))) {
                return true;
            }
        }

        if ($catListed) {
            if (! $catIn) {
                return false; // перечислены категории, но позиция не в них
            }
            // Бренд исключает только при каноничном конфликте.
            if ($brandListed && $brandCanonical && $brandKnown && ! $brandIn) {
                return false;
            }

            return true;
        }

        // Категории не перечислены → подбор только по бренду.
        if ($brandListed) {
            return $brandIn;
        }

        return false; // пустая матрица
    }

    /**
     * Термины категории позиции: имя KB-категории + синонимы. Фоллбэк — категория
     * привязанного каталожного товара (55% позиций без своей KB-категории, но 70%
     * привязаны к каталогу, который категоризирован на 91%). Пусто, если категория
     * не определена ни у позиции, ни у каталога.
     *
     * @return array<int, string>
     */
    private function itemCategoryTerms(RequestItem $item): array
    {
        $cat = $item->relationLoaded('kbCategory') ? $item->kbCategory : $item->kbCategory()->first();
        if ($cat === null) {
            $catItem = $item->relationLoaded('catalogItem') ? $item->catalogItem : $item->catalogItem()->first();
            $cat = $catItem
                ? ($catItem->relationLoaded('equipmentCategory') ? $catItem->equipmentCategory : $catItem->equipmentCategory()->first())
                : null;
        }
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
     * Бренд позиции: KB-бренд → parsed_brand → бренд привязанного каталожного
     * товара (фоллбэк). Нормализованный, пустая строка если нигде не определён.
     */
    private function itemBrand(RequestItem $item): string
    {
        $brand = $item->brand?->name ?: (string) $item->parsed_brand;
        if (trim($brand) === '') {
            $catItem = $item->relationLoaded('catalogItem') ? $item->catalogItem : $item->catalogItem()->first();
            $brand = (string) ($catItem->brand ?? '');
        }

        return $this->norm($brand);
    }

    /**
     * Пересекается ли хоть одна пара (listTerm, term) — для категорий с учётом
     * русской морфологии (стем по общему префиксу слова).
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
            foreach ($terms as $t) {
                if ($this->phraseMatch($l, $t)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Совпадение двух фраз: равенство / подстрока / пословный стем-матч
     * (ловит «лебёдка» ↔ «лебёдки», «двери» ↔ «двери кабины»).
     */
    private function phraseMatch(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }
        foreach (preg_split('/\s+/u', $a) ?: [] as $wa) {
            foreach (preg_split('/\s+/u', $b) ?: [] as $wb) {
                if ($this->stemEq((string) $wa, (string) $wb)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Слова с одним корнем: общий префикс ≥4 символов и не короче (min длина − 2)
     * — терпит падежные/числовые окончания («лебёдк-а» / «лебёдк-и»).
     */
    private function stemEq(string $w1, string $w2): bool
    {
        $w1 = trim($w1);
        $w2 = trim($w2);
        $l1 = mb_strlen($w1);
        $l2 = mb_strlen($w2);
        if ($l1 < 4 || $l2 < 4) {
            return $w1 === $w2;
        }
        $p = 0;
        $min = min($l1, $l2);
        while ($p < $min && mb_substr($w1, $p, 1) === mb_substr($w2, $p, 1)) {
            $p++;
        }

        return $p >= 4 && $p >= $min - 2;
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
