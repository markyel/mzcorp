<?php

namespace App\Console\Commands;

use App\Models\OutboundQuote;
use App\Models\Request;
use App\Services\Quotes\CostPlusPriceGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Перепроверка документов, отложенных сторожем цены (CostPlusPriceGuard).
 *
 * В ожидание попадают КП/счета, где завышенная строка стоит на позиции с
 * неактуальной ценой каталога: менеджер обновил закупку в 1С, а импорт до нас
 * ещё не дошёл. После импорта цена становится актуальной — сравниваем снова и
 * пишем менеджеру, только если завышение подтвердилось. Ожидание дольше
 * `services.pricing.cost_plus_guard_pending_days` сторож снимает сам.
 */
class QuotesCostPlusRecheckCommand extends Command
{
    protected $signature = 'quotes:cost-plus-recheck
        {--dry-run : Только показать, что сейчас завышено, без писем и изменений}';

    protected $description = 'Перепроверить КП/счета, отложенные сторожем цены до импорта каталога';

    public function handle(CostPlusPriceGuard $guard): int
    {
        $dry = (bool) $this->option('dry-run');
        $quotes = OutboundQuote::query()
            ->whereNotNull('payload->cost_plus_guard->pending_since')
            ->whereNull('payload->cost_plus_guard->notified_at')
            ->orderBy('id')
            ->get();

        $notified = 0;
        $released = 0;
        foreach ($quotes as $quote) {
            $request = $quote->request_id ? Request::query()->find($quote->request_id) : null;
            if ($request === null) {
                continue;
            }
            try {
                $over = $guard->check($quote, $request, ! $dry) ?? [];
            } catch (\Throwable $e) {
                Log::warning('quotes:cost-plus-recheck failed', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);

                continue;
            }
            $guardState = (array) (($quote->fresh()?->payload ?? [])['cost_plus_guard'] ?? []);
            if (! empty($guardState['notified_at'])) {
                $notified++;
            } elseif (empty($guardState['pending_since'])) {
                $released++;
            }
            $this->line(sprintf('#%d №%s %s: завышено %d (неактуальных %d)%s',
                $quote->id, $quote->document_number, $request->internal_code, count($over),
                count(array_filter($over, fn ($l) => $l['stale'] ?? false)),
                $dry ? '' : (! empty($guardState['notified_at']) ? ' → письмо' : (empty($guardState['pending_since']) ? ' → снят' : ' → ждём'))));
        }

        $this->info(sprintf('Отложенных: %d, писем: %d, снято: %d%s', $quotes->count(), $notified, $released, $dry ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
