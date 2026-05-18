<?php

namespace App\Services\Quotes;

use App\Models\OutboundQuote;
use App\Models\OutboundQuoteItem;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Editor для ручного управления связями строк OutboundQuote ↔ RequestItem.
 *
 * После того как автоматический matcher (M-SKU exact / catalog→request /
 * fuzzy / LLM / Step 2.5 catalog.name) не дотянул match'и до 100%,
 * оператор привязывает оставшиеся строки КП к позициям заявки руками.
 *
 * Параллельно с auto-enrich (через `OutboundQuoteCatalogEnricher`) при
 * успешном ручном match'е, если quote_item имеет matched_catalog_item_id,
 * а RequestItem.catalog_item_id ещё null — auto-link catalog_item_id.
 * Это даёт «обучение на лету»: следующий парс той же или похожей заявки
 * сматчит автоматически через Step 2.
 *
 * Audit в `payload.manual_match` каждого OutboundQuoteItem'а:
 *  {by_user_id, by_name, at, action: 'link'|'unlink'|'rematch',
 *   previous_request_item_id, previous_source, previous_score}.
 */
class OutboundQuoteItemEditor
{
    public function __construct(
        private readonly OutboundQuoteCatalogEnricher $enricher,
    ) {
    }

    /**
     * Привязать строку КП к выбранному RequestItem (или сменить привязку).
     * Триггерит auto-enrich catalog_item_id у RequestItem если был null.
     */
    public function linkToRequestItem(
        OutboundQuoteItem $quoteItem,
        RequestItem $requestItem,
        User $by,
    ): OutboundQuoteItem {
        $quote = $quoteItem->quote;
        $this->ensureSameRequest($quoteItem, $requestItem, $quote);

        $previous = $this->snapshotState($quoteItem);

        DB::transaction(function () use ($quoteItem, $requestItem, $by, $previous) {
            $quoteItem->matched_request_item_id = $requestItem->id;
            $quoteItem->match_source = OutboundQuoteItem::MATCH_SOURCE_MANUAL;
            $quoteItem->match_score = 1.0;
            $quoteItem->match_reason = sprintf(
                'manual link → request_item#%d by user#%d (%s)',
                $requestItem->id,
                $by->id,
                $by->name ?? '?'
            );
            $this->appendAudit($quoteItem, $previous, $by, $previous['request_item_id'] === null ? 'link' : 'rematch');
            $quoteItem->save();
        });

        // Если привязали к RequestItem, и quote_item имеет catalog — Enricher
        // подцепит автоматом (он смотрит на оба matched_* поля и пишет
        // RequestItem.catalog_item_id если null).
        if ($quoteItem->matched_catalog_item_id !== null) {
            try {
                $this->enricher->enrich($quote);
            } catch (\Throwable $e) {
                Log::warning('OutboundQuoteItemEditor: enrich after manual link failed (non-fatal)', [
                    'quote_item_id' => $quoteItem->id,
                    'request_item_id' => $requestItem->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('OutboundQuoteItemEditor: manual link', [
            'quote_item_id' => $quoteItem->id,
            'outbound_quote_id' => $quote->id,
            'request_item_id' => $requestItem->id,
            'previous' => $previous,
            'by_user_id' => $by->id,
        ]);

        return $quoteItem;
    }

    /**
     * Отвязать строку КП от текущего RequestItem (вернуть в unmatched).
     * Каталожная привязка сохраняется (catalog_item_id остаётся).
     */
    public function unlinkFromRequestItem(OutboundQuoteItem $quoteItem, User $by): OutboundQuoteItem
    {
        if ($quoteItem->matched_request_item_id === null) {
            return $quoteItem;
        }

        $previous = $this->snapshotState($quoteItem);

        DB::transaction(function () use ($quoteItem, $by, $previous) {
            $quoteItem->matched_request_item_id = null;
            $quoteItem->match_source = $quoteItem->matched_catalog_item_id !== null
                ? OutboundQuoteItem::MATCH_SOURCE_SKU_EXACT  // catalog есть — оставляем sku_exact
                : OutboundQuoteItem::MATCH_SOURCE_UNMATCHED;
            $quoteItem->match_score = $quoteItem->matched_catalog_item_id !== null ? 1.0 : null;
            $quoteItem->match_reason = sprintf(
                'manual unlink by user#%d (%s); previous request_item#%d',
                $by->id,
                $by->name ?? '?',
                $previous['request_item_id']
            );
            $this->appendAudit($quoteItem, $previous, $by, 'unlink');
            $quoteItem->save();
        });

        Log::info('OutboundQuoteItemEditor: manual unlink', [
            'quote_item_id' => $quoteItem->id,
            'outbound_quote_id' => $quoteItem->outbound_quote_id,
            'previous_request_item_id' => $previous['request_item_id'],
            'by_user_id' => $by->id,
        ]);

        return $quoteItem;
    }

    /**
     * Проверка: RequestItem принадлежит той же заявке что и OutboundQuote.
     * Защита от случайной привязки к чужой заявке через подменённый ID.
     */
    private function ensureSameRequest(OutboundQuoteItem $qi, RequestItem $ri, ?OutboundQuote $quote): void
    {
        if ($quote === null) {
            throw new RuntimeException("OutboundQuoteItem#{$qi->id}: parent quote missing");
        }
        if ($ri->request_id !== $quote->request_id) {
            throw new RuntimeException(sprintf(
                'RequestItem#%d принадлежит заявке #%d, а OutboundQuote#%d — заявке #%d',
                $ri->id, $ri->request_id, $quote->id, $quote->request_id
            ));
        }
        if (! $ri->is_active) {
            throw new RuntimeException("RequestItem#{$ri->id} не активна (soft-deleted)");
        }
    }

    /**
     * @return array{request_item_id: ?int, source: ?string, score: ?float}
     */
    private function snapshotState(OutboundQuoteItem $qi): array
    {
        return [
            'request_item_id' => $qi->matched_request_item_id,
            'source' => $qi->match_source,
            'score' => $qi->match_score !== null ? (float) $qi->match_score : null,
        ];
    }

    private function appendAudit(OutboundQuoteItem $qi, array $previous, User $by, string $action): void
    {
        $payload = is_array($qi->payload) ? $qi->payload : [];
        $audit = is_array($payload['manual_match'] ?? null) ? $payload['manual_match'] : [];
        $audit[] = [
            'action' => $action,
            'by_user_id' => $by->id,
            'by_name' => $by->name ?? null,
            'at' => now()->toIso8601String(),
            'previous_request_item_id' => $previous['request_item_id'],
            'previous_source' => $previous['source'],
            'previous_score' => $previous['score'],
        ];
        $payload['manual_match'] = $audit;
        $qi->payload = $payload;
    }
}
