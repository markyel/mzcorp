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
 * Идемпотентность: та же карточка — пропуск. Другая карточка — КП её перебивает только по quoteOverridesCard
 * (одна строка КП на позицию, карточку ставил не человек — или строку привязал человек), с 20.11.2026.
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

        $linesByItem = $candidates->groupBy('matched_request_item_id');

        foreach ($candidates as $qi) {
            $ri = $qi->requestItem;
            $catalog = $qi->catalogItem;
            if (! $ri instanceof RequestItem || ! $catalog instanceof CatalogItem) {
                $stats['skipped_other']++;

                continue;
            }
            $replaced = $ri->catalog_item_id;
            if ($replaced !== null && ($replaced === $catalog->id || ! self::quoteOverridesCard($qi, $ri, $linesByItem->get($ri->id)))) {
                $stats['skipped_already_linked']++;

                continue;
            }

            DB::transaction(function () use ($ri, $catalog, $qi, $quote, $replaced) {
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
                ] + ($replaced !== null ? ['replaced_catalog_item_id' => $replaced] : []);
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
                    'action' => $replaced !== null ? 'relink_from_quote' : 'auto_link_from_quote',
                    'old' => ['catalog_item_id' => $replaced],
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
                'replaced_catalog_item_id' => $replaced,
            ]);
        }

        return $stats;
    }

    /**
     * КП перебивает карточку позиции: что предложили клиенту — то и позиция (MZ2026-02473, 20.11.2026: у «Пускателя
     * LC1DS32» осталась автопривязка M32038, хотя в КП — замена M04160; «ЗАМЕНЕНО на M03809», «снят с производства»,
     * автопривязка к D587 при КП на D557). Только когда ясно, что строка — про эту позицию:
     *  - к позиции привязана одна строка КП с карточкой: две и больше — это вариант + аксессуар («канат» и «барабан для
     *    каната», левая и правая гребёнка) или выбор из нескольких поручней, а не замена;
     *  - карточку позиции ставил не человек — разве что и строку КП к позиции привязал человек.
     *
     * @param  \Illuminate\Support\Collection<int, OutboundQuoteItem>|null  $linesOfItem  строки этого КП с карточкой на этой позиции
     */
    public static function quoteOverridesCard(OutboundQuoteItem $qi, RequestItem $ri, ?\Illuminate\Support\Collection $linesOfItem): bool
    {
        if (! $ri->is_active || $linesOfItem === null || $linesOfItem->count() !== 1) {
            return false;
        }
        if ($qi->match_source === OutboundQuoteItem::MATCH_SOURCE_MANUAL) {
            return true;
        }
        $payload = is_array($ri->quality_assessment_payload) ? $ri->quality_assessment_payload : [];

        return ($payload['catalog_match']['method'] ?? null) !== 'manual_link';
    }
}
