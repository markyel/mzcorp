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

        return CatalogItem::query()
            ->select([
                'id', 'sku', 'name', 'brand', 'brand_article',
                'brand_article_normalized', 'unit_name', 'part_type',
                'price', 'stock_available', 'is_active', 'photo_url',
            ])
            ->where(function ($q) use ($like, $normalizedLike, $lower, $normalized) {
                $q->whereRaw('LOWER(sku) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(brand_article) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$like]);
                if ($normalizedLike !== null) {
                    $q->orWhere('brand_article_normalized', 'LIKE', $normalizedLike);
                }
                // exact для приоритизации
                $q->orWhereRaw('LOWER(sku) = ?', [$lower])
                    ->orWhereRaw('LOWER(brand_article) = ?', [$lower]);
                if ($normalized !== '') {
                    $q->orWhere('brand_article_normalized', '=', $normalized);
                }
            })
            ->orderByRaw('CASE WHEN LOWER(sku) = ? THEN 0 ELSE 1 END', [$lower])
            ->orderByRaw('CASE WHEN LOWER(sku) LIKE ? THEN 0 ELSE 1 END', [$this->escapeLike($lower) . '%'])
            ->orderByRaw('CASE WHEN brand_article_normalized = ? THEN 0 ELSE 1 END', [$normalized])
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
