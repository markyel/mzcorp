<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use App\Models\RequestItem;
use Illuminate\Support\Facades\Log;

/**
 * Резолв позиций заявок, помеченных `internal_catalog_pending`, против
 * `catalog_items` после импорта (Phase 2 — use-case A).
 *
 * Логика на одну `RequestItem`:
 *  1. Извлекаем internal SKU из payload (`quality_assessment_payload.internal_catalog_sku`)
 *     или повторно через regex `M\d{4,}` из parsed_article.
 *  2. Ищем `catalog_items` по `sku`, активные (is_active=true).
 *  3. Если нашли — обновляем item:
 *     - parsed_name: если был пустой / 'M02016' (только артикул) → берём из каталога;
 *     - parsed_brand: если пустой → из каталога;
 *     - quality_assessment_status: `sufficient`;
 *     - quality_assessment_payload: добавляем секцию catalog с snapshot полей.
 *  4. Если не нашли — оставляем `internal_catalog_pending`, ждём следующего snapshot'а.
 *
 * НЕ трогает позиции с другими статусами (sufficient/insufficient/not_covered/...) —
 * у них уже определились через KB-цепочку, перетирать нельзя.
 */
class CatalogResolutionService
{
    /**
     * Резолв одной позиции. Возвращает true если применили апдейт.
     */
    public function resolveItem(RequestItem $item): bool
    {
        if ($item->quality_assessment_status !== 'internal_catalog_pending') {
            return false;
        }

        $sku = $this->extractSku($item);
        if ($sku === null) {
            return false;
        }

        $catalog = CatalogItem::query()
            ->where('sku', $sku)
            ->where('is_active', true)
            ->first();
        if ($catalog === null) {
            return false;
        }

        $this->applyCatalogToItem($item, $catalog);

        Log::info('CatalogResolutionService: item resolved', [
            'request_item_id' => $item->id,
            'sku' => $sku,
            'catalog_item_id' => $catalog->id,
        ]);

        return true;
    }

    /**
     * Bulk-резолв всех ожидающих позиций. Используется после успешного
     * импорта каталога (см. CatalogResolutionAfterImportJob).
     *
     * @return array{checked: int, resolved: int}
     */
    public function resolveAllPending(): array
    {
        $checked = 0;
        $resolved = 0;

        RequestItem::query()
            ->where('quality_assessment_status', 'internal_catalog_pending')
            ->where('is_active', true)
            ->chunkById(200, function ($items) use (&$checked, &$resolved) {
                foreach ($items as $item) {
                    $checked++;
                    if ($this->resolveItem($item)) {
                        $resolved++;
                    }
                }
            });

        Log::info('CatalogResolutionService: bulk resolve done', [
            'checked' => $checked,
            'resolved' => $resolved,
        ]);

        return ['checked' => $checked, 'resolved' => $resolved];
    }

    private function extractSku(RequestItem $item): ?string
    {
        $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
        if (! empty($payload['internal_catalog_sku'])) {
            return (string) $payload['internal_catalog_sku'];
        }
        $article = (string) ($item->parsed_article ?? '');
        if ($article === '') {
            return null;
        }
        // Тот же regex что в QualityAssessmentService::detectInternalCatalogSku.
        $pattern = '/(?<![\p{L}\p{N}_])(M\d{4,})(?![\p{L}\p{N}_])/u';
        if (preg_match($pattern, $article, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function applyCatalogToItem(RequestItem $item, CatalogItem $catalog): void
    {
        $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
        $payload['phase'] = 'completed';
        $payload['resolved_at'] = now()->toIso8601String();
        $payload['reason'] = 'catalog_resolved';
        $payload['catalog'] = [
            'catalog_item_id' => $catalog->id,
            'sku' => $catalog->sku,
            'brand' => $catalog->brand,
            'brand_article' => $catalog->brand_article,
            'unit_name' => $catalog->unit_name,
            'part_type' => $catalog->part_type,
            'form_factor' => $catalog->form_factor,
            'price' => $catalog->price,
            'stock_available' => $catalog->stock_available,
        ];

        $dirty = false;

        // parsed_name: если у позиции имя пустое или это просто SKU
        // (типичный сценарий — клиент прислал «Артикул: M02016 — 5 шт»,
        // парсер записал name=«M02016»), берём название из каталога.
        $name = (string) ($item->parsed_name ?? '');
        if ($name === '' || $name === $catalog->sku) {
            $item->parsed_name = mb_substr((string) $catalog->name, 0, 250);
            $dirty = true;
        }

        if (empty($item->parsed_brand) && ! empty($catalog->brand)) {
            $item->parsed_brand = $catalog->brand;
            $dirty = true;
        }

        $item->quality_assessment_status = 'sufficient';
        $item->quality_assessment_payload = $payload;
        $item->save();

        // save() сам ставит dirty=true для status/payload, нет смысла
        // отдельно их учитывать.
        unset($dirty);
    }
}
