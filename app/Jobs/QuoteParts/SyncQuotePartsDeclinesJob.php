<?php

namespace App\Jobs\QuoteParts;

use App\Services\QuoteParts\QuotePartsPriceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Поставщик в кабинете QuoteParts отметил позиции «не сможем предложить» (или вернул их):
 * обновить копию отметок для «Снабжения».
 */
class SyncQuotePartsDeclinesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(QuotePartsPriceService $prices): void
    {
        $prices->syncDeclines();
    }
}
