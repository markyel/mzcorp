<?php

namespace App\Services\Quotes;

use App\Models\CatalogItem;
use App\Models\OutboundQuote;
use App\Models\OutboundQuoteItem;
use App\Models\RequestItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-enrich `RequestItem.catalog_item_id` после OutboundQuoteItemMatcher.
 *
 * Логика: если в КП менеджер вручную подобрал M-SKU из нашего каталога, а
 * позиция заявки была ещё не сматчена с каталогом (`catalog_item_id IS NULL`) —
 * подхватываем catalog_item_id обратно в заявку. Это удобно для:
 *  - Hero «Сматчено N/M»: M-SKU из КП — авторитетный источник;
 *  - Use-case A / brand_article fallback: catalog данные приходят в payload;
 *  - Дальнейшего refresh-парсинга (Phase 3, supplier flow).
 *
 * Не использует RequestItemEditor::linkToCatalog, потому что enrich происходит
 * из job-контекста без User. Audit-запись пишется напрямую в
 * `payload.manual_edits[]` с `source=outbound_quote` и `action=auto_link_from_quote`
 * (формат совместим с UI Activity-tab фильтрами).
 *
 * Идемпотентность: если catalog_item_id уже стоит (любой), enrich пропускается.
 * Намеренно не перезаписываем существующий resolve — manual_link / use-case A/B
 * приоритетнее.
 */
class OutboundQuoteCatalogEnricher
{
    /**
     * @return array{enriched: int, skipped_already_linked: int, skipped_other: int}
     */
    public function enrich(OutboundQuote $quote): array
    {
        $stats = ['enriched' => 0, 'skipped_already_linked' => 0, 'skipped_other' => 0];

        $quote->loadMissing('items.catalogItem', 'items.requestItem');
        // Кандидаты — quote_item'ы с matched_catalog_item_id + matched_request_item_id.
        $candidates = $quote->items->filter(
            fn (OutboundQuoteItem $qi) => $qi->matched_catalog_item_id !== null
                && $qi->matched_request_item_id !== null
        );
        if ($candidates->isEmpty()) {
            return $stats;
        }

        foreach ($candidates as $qi) {
            $ri = $qi->requestItem;
            $catalog = $qi->catalogItem;
            if (! $ri instanceof RequestItem || ! $catalog instanceof CatalogItem) {
                $stats['skipped_other']++;

                continue;
            }
            if ($ri->catalog_item_id !== null) {
                // Уже сматчено — не перезаписываем (manual / A / B / C priority).
                $stats['skipped_already_linked']++;

                continue;
            }

            DB::transaction(function () use ($ri, $catalog, $qi, $quote) {
                $ri->catalog_item_id = $catalog->id;

                $payload = is_array($ri->quality_assessment_payload) ? $ri->quality_assessment_payload : [];

                $payload['catalog_match'] = [
                    'method' => 'outbound_quote',
                    'matched_at' => now()->toIso8601String(),
                    'catalog_item_id' => $catalog->id,
                    'catalog_sku' => $catalog->sku,
                    'source' => 'outbound_quote',
                    'outbound_quote_id' => $quote->id,
                    'outbound_quote_item_id' => $qi->id,
                ];
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

                $audit = is_array($payload['manual_edits'] ?? null) ? $payload['manual_edits'] : [];
                $audit[] = [
                    'action' => 'auto_link_from_quote',
                    'source' => 'outbound_quote',
                    'outbound_quote_id' => $quote->id,
                    'outbound_quote_item_id' => $qi->id,
                    'catalog_item_id' => $catalog->id,
                    'catalog_sku' => $catalog->sku,
                    'by' => 'system',
                    'at' => now()->toIso8601String(),
                ];
                $payload['manual_edits'] = $audit;

                $ri->quality_assessment_payload = $payload;
                $ri->save();
            });

            $stats['enriched']++;

            Log::info('OutboundQuoteCatalogEnricher: auto-linked catalog from outbound quote', [
                'request_item_id' => $ri->id,
                'catalog_item_id' => $catalog->id,
                'catalog_sku' => $catalog->sku,
                'outbound_quote_id' => $quote->id,
                'outbound_quote_item_id' => $qi->id,
            ]);
        }

        return $stats;
    }
}
