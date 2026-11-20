<?php

namespace App\Console\Commands;

use App\Models\OutboundQuote;
use App\Models\OutboundQuoteItem;
use App\Services\Quotes\OutboundQuoteCatalogEnricher;
use Illuminate\Console\Command;

/**
 * Карточки позиций по уже пойманным КП (правило OutboundQuoteCatalogEnricher::quoteOverridesCard, 20.11.2026): позиция,
 * к которой привязана одна строка КП с другой карточкой, получает карточку из КП. Строки КП к позициям не перематчиваем —
 * их могли поправить руками. КП — по порядку, поздний перебивает ранний. --dry-run показывает, ничего не меняя.
 */
class QuotesRelinkItemsCommand extends Command
{
    protected $signature = 'quotes:relink-items
        {--days=60 : КП за последние N дней}
        {--except=* : Номера заявок, которые не трогать (сомнительные связки строк КП)}
        {--dry-run : Только показать, какие позиции сменят карточку}';

    protected $description = 'Карточки позиций заявок — по строкам отправленных КП, где КП предложил другой товар';

    public function handle(OutboundQuoteCatalogEnricher $enricher): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = 0;
        $requests = [];
        $except = array_map('trim', (array) $this->option('except'));

        OutboundQuote::query()->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
            ->whereNotNull('request_id')
            ->whereHas('items', fn ($q) => $q->whereNotNull('matched_catalog_item_id')->whereNotNull('matched_request_item_id'))
            ->with(['request:id,internal_code', 'items.catalogItem:id,sku,name', 'items.requestItem.catalogItem:id,sku,name'])
            ->orderBy('id')
            ->each(function (OutboundQuote $quote) use ($enricher, $dry, &$total, &$requests) {
                $lines = $quote->items->filter(fn (OutboundQuoteItem $qi) => $qi->matched_catalog_item_id !== null && $qi->matched_request_item_id !== null);
                $byItem = $lines->groupBy('matched_request_item_id');
                $changes = $lines->filter(fn (OutboundQuoteItem $qi) => $qi->requestItem !== null && $qi->catalogItem !== null
                    && $qi->requestItem->catalog_item_id !== null && $qi->requestItem->catalog_item_id !== $qi->matched_catalog_item_id
                    && OutboundQuoteCatalogEnricher::quoteOverridesCard($qi, $qi->requestItem, $byItem->get($qi->matched_request_item_id)));
                if ($changes->isEmpty() || in_array((string) $quote->request?->internal_code, $except, true)) {
                    return;
                }
                foreach ($changes as $qi) {
                    $old = $qi->requestItem->catalogItem;
                    $this->line(sprintf('%s  «%s»: %s %s → %s %s  [%s]', $quote->request?->internal_code, mb_strimwidth((string) $qi->requestItem->parsed_name, 0, 45, '…'),
                        $old?->sku, mb_strimwidth((string) $old?->name, 0, 40, '…'), $qi->catalogItem->sku, mb_strimwidth((string) $qi->catalogItem->name, 0, 40, '…'), $qi->match_source));
                }
                $total += $changes->count();
                $requests[$quote->request_id] = true;
                if (! $dry) {
                    $enricher->enrich($quote->fresh());
                }
            });

        $this->info(($dry ? '[dry-run] ' : '').'позиций: '.$total.', заявок: '.count($requests));

        return self::SUCCESS;
    }
}
