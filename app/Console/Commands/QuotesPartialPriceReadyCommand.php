<?php

namespace App\Console\Commands;

use App\Enums\RequestActivityType;
use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\RequestItem;
use App\Services\Quotes\QuoteCoverageService;
use App\Services\Request\AttentionService;
use App\Services\Request\RequestActivityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * «Частичное КП», этап 3 (18.11.2026): заявка в «Частичном КП», и у позиции без КП появилась цена — в 1С (цена
 * товара каталога стала актуальной) или поставщик ответил ценой на наш запрос. Менеджеру — напоминание «можно дослать
 * КП» в пуле (attention, держится до открытия карточки); в карточке у позиции пометка «цена есть». По каждой позиции
 * напоминаем один раз (request_items.quote_price_ready_at). КП из 1С сами не шлём — его готовит менеджер.
 */
class QuotesPartialPriceReadyCommand extends Command
{
    protected $signature = 'quotes:partial-price-ready
        {--dry-run : Только показать, по каким заявкам напомнили бы}';

    protected $description = 'Напомнить «можно дослать КП», когда у позиции без КП появилась цена';

    public function handle(QuoteCoverageService $coverage, AttentionService $attention, RequestActivityService $activity): int
    {
        $dry = (bool) $this->option('dry-run');
        $requests = 0;
        $items = 0;

        // «Частичное КП» статусом или вехой (после КП ждём ответа клиента на вопрос и т.п.).
        Request::query()->where(fn ($q) => $q->where('status', RequestStatus::PartiallyQuoted->value)
            ->orWhere('peak_status', RequestStatus::PartiallyQuoted->value))->orderBy('id')
            ->each(function (Request $r) use ($coverage, $attention, $activity, $dry, &$requests, &$items) {
                if (! QuoteCoverageService::isPartial($r)) {
                    return;
                }
                $open = $coverage->uncovered($r);
                $sources = $coverage->priceSources($open);
                $fresh = $open->filter(fn (RequestItem $i) => isset($sources[(int) $i->id]) && $i->quote_price_ready_at === null)->values();
                if ($fresh->isEmpty()) {
                    return;
                }
                $requests++;
                $items += $fresh->count();
                $this->line(sprintf('%s  %s', $r->internal_code, $fresh->map(fn (RequestItem $i) => mb_strimwidth((string) $i->parsed_name, 0, 45, '…')
                    .' ('.QuoteCoverageService::PRICE_LABELS[$sources[(int) $i->id]].')')->implode('; ')));
                if ($dry) {
                    return;
                }

                try {
                    RequestItem::query()->whereIn('id', $fresh->pluck('id'))->update(['quote_price_ready_at' => now()]);
                    $activity->touch($r, RequestActivityType::PricesActualized);
                    $attention->onPartialQuotePriceReady($r->fresh() ?? $r);
                    Log::info('quotes:partial-price-ready: price appeared for items without a quote', [
                        'request_id' => $r->id,
                        'items' => $fresh->mapWithKeys(fn (RequestItem $i) => [$i->id => $sources[(int) $i->id]])->all(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('quotes:partial-price-ready failed for request', ['request_id' => $r->id, 'error' => $e->getMessage()]);
                }
            });

        $this->info(sprintf('%sнапоминаний: %d заявок, позиций с ценой: %d', $dry ? '[dry-run] ' : '', $requests, $items));

        return self::SUCCESS;
    }
}
