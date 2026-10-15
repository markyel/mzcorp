<?php

namespace App\Jobs\QuoteParts;

use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Подтянуть ответы поставщиков по запросам, отправленным через QuoteParts, в заявки.
 */
class SyncQuotePartsRfqsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function handle(QuotePartsRfqService $service, QuotePartsClient $client): void
    {
        if ($client->isConfigured()) {
            $service->refreshRecent();
            // Офферы поставщиков (ответы на запросы, прайсы) — в «Снабжение».
            try {
                app(\App\Services\QuoteParts\QuotePartsPriceService::class)->syncOffers();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('QuoteParts offers sync failed', ['error' => $e->getMessage()]);
            }
            // Отметки «не сможем предложить» — на случай пропущенного вебхука.
            try {
                app(\App\Services\QuoteParts\QuotePartsPriceService::class)->syncDeclines();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('QuoteParts declines sync failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
