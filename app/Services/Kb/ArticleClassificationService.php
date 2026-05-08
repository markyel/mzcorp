<?php

namespace App\Services\Kb;

use App\Models\Kb\BrandSkuPattern;
use App\Models\Kb\SupplierSkuPattern;

/**
 * Документ 3 §4.1: классификация артикула позиции.
 *
 * Возвращает структуру:
 *   ['type' => 'manufacturer_sku' | 'supplier_sku' | 'unknown' | 'absent',
 *    'matched_pattern_id' => int|null,
 *    'matched_supplier_id' => int|null,
 *    'matched_brand_id' => int|null,
 *    ...]
 *
 * SKU поставщиков проверяются ПЕРЕД масками производителей — артикул из их каталога
 * (например, LW-XXXXXXX) побеждает любую совпавшую маску производителя.
 */
class ArticleClassificationService
{
    /**
     * @param array<int, array<string, mixed>>|null $mentionedSources
     */
    public function classify(?string $article, ?array $mentionedSources = null): array
    {
        if ($article === null || trim($article) === '') {
            return ['type' => 'absent'];
        }

        $article = $this->normalize($article);

        // 1) SKU поставщиков
        $supplierPatterns = SupplierSkuPattern::active()->orderBy('priority')->get();
        foreach ($supplierPatterns as $p) {
            if ($this->matches($p->pattern, $article)) {
                return [
                    'type' => 'supplier_sku',
                    'matched_pattern_id' => $p->id,
                    'matched_supplier_id' => $p->supplier_id,
                    'matched_brand_id' => null,
                    'value' => $article,
                    'confidence' => $this->resolveSupplierConfidence($p, $mentionedSources),
                ];
            }
        }

        // 2) Маски производителей
        $brandPatterns = BrandSkuPattern::active()->orderBy('priority')->with('brand')->get();
        foreach ($brandPatterns as $p) {
            if ($this->matches($p->pattern, $article)) {
                return [
                    'type' => 'manufacturer_sku',
                    'matched_pattern_id' => $p->id,
                    'matched_supplier_id' => null,
                    'matched_brand_id' => $p->brand_id,
                    'matched_brand_name' => $p->brand?->name,
                    'matched_series' => $p->series_name,
                    'value' => $article,
                    'confidence' => 0.95,
                ];
            }
        }

        return [
            'type' => 'unknown',
            'matched_pattern_id' => null,
            'matched_supplier_id' => null,
            'matched_brand_id' => null,
            'value' => $article,
        ];
    }

    private function normalize(string $article): string
    {
        $article = trim($article);
        $article = trim($article, "\"' \t\n\r\0\x0B");
        return $article;
    }

    private function matches(string $pattern, string $value): bool
    {
        $delim = '/' . str_replace('/', '\\/', $pattern) . '/u';
        return @preg_match($delim, $value) === 1;
    }

    /**
     * Если в письме упомянут именно этот поставщик — confidence выше.
     *
     * @param array<int, array<string, mixed>>|null $mentionedSources
     */
    private function resolveSupplierConfidence(SupplierSkuPattern $pattern, ?array $mentionedSources): float
    {
        if (!is_array($mentionedSources)) {
            return 0.85;
        }

        foreach ($mentionedSources as $src) {
            if (!is_array($src)) {
                continue;
            }
            if (($src['supplier_id'] ?? null) === $pattern->supplier_id) {
                return 0.98;
            }
        }

        return 0.85;
    }
}
