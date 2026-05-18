<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Поиск по каталогу для UI-autocomplete в modal'е manual link
 * (Priority 1, ItemCatalogLinkDialog).
 *
 * SQL ILIKE по sku / brand_article / brand_article_normalized / name.
 * Без векторов — оператор обычно ищет точный SKU/артикул, который видел
 * у клиента или в КП поставщика.
 *
 * Сортировка:
 *  1. exact match по sku (highest);
 *  2. префикс sku;
 *  3. exact match по brand_article_normalized;
 *  4. is_active=true первыми (soft-deleted — в самом конце);
 *  5. sku ASC.
 */
class CatalogSearchService
{
    public const DEFAULT_LIMIT = 20;

    /**
     * @return Collection<int, CatalogItem>
     */
    public function search(string $query, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) < 2) {
            return new Collection();
        }

        $lower = mb_strtolower($query);
        $normalized = CatalogImportService::normalizeArticle($query) ?? '';

        $like = '%' . $this->escapeLike($lower) . '%';
        $normalizedLike = $normalized !== '' ? '%' . $this->escapeLike($normalized) . '%' : null;

        // Используем индексы:
        //   - GIN trgm на lower(name) → ILIKE по name
        //   - GIN trgm на brand_article_normalized → ILIKE по brand_article_normalized
        //   - sku — короткая колонка, обычный ILIKE без полного сканирования
        //     дорогих text-полей
        // Старый вариант (LOWER(sku/brand_article/name) ILIKE + ORDER BY CASE)
        // делал full table scan по 35K строкам — модал открывался 1-2 сек.
        return CatalogItem::query()
            ->select([
                'id', 'sku', 'name', 'brand', 'brand_article',
                'brand_article_normalized', 'unit_name', 'part_type',
                'price', 'stock_available', 'is_active', 'photo_url',
            ])
            ->where(function ($q) use ($like, $normalizedLike) {
                // lower(name) ILIKE → GIN trgm index
                $q->whereRaw('lower(name) LIKE ?', [$like]);
                // brand_article_normalized — uppercase no-sep — GIN trgm index
                if ($normalizedLike !== null) {
                    $q->orWhere('brand_article_normalized', 'ILIKE', $normalizedLike);
                }
                // sku — короткая, B-tree index достаточно
                $q->orWhereRaw('sku ILIKE ?', [$like]);
            })
            ->orderByDesc('is_active')
            ->orderBy('sku')
            ->limit($limit)
            ->get();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
