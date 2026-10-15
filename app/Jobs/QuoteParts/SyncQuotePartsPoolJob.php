<?php

namespace App\Jobs\QuoteParts;

use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsPoolService;
use App\Services\QuoteParts\QuotePartsSupplierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Передать в QuoteParts новые позиции пула и досылать изменившиеся.
 * Один прогон за раз: lock дольше retry_after очереди (90 с).
 */
class SyncQuotePartsPoolJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(QuotePartsPoolService $pool, QuotePartsClient $client): void
    {
        if (! $client->isConfigured()) {
            Log::info('SyncQuotePartsPoolJob: QuoteParts не настроен — пропуск');

            return;
        }

        $lock = Cache::lock('quoteparts:pool-sync', 900);
        if (! $lock->get()) {
            return;
        }

        try {
            $stats = $pool->sync();
            if ($stats['sent'] > 0 || $stats['failed'] > 0) {
                Log::info('QuoteParts pool synced', $stats);
            }

            // Ответы приглашённых поставщиков (принял, предложил другие условия, отказался).
            app(QuotePartsSupplierService::class)->refreshStatuses();

            // Новые товары, получившие M-артикул: предварительные позиции → постоянные.
            try {
                $promoted = app(\App\Services\QuoteParts\QuotePartsDraftService::class)->promoteMatched($pool);
                if ($promoted > 0) {
                    Log::info('QuoteParts drafts promoted', ['count' => $promoted]);
                }
            } catch (\Throwable $e) {
                Log::warning('QuoteParts draft promotion failed', ['error' => $e->getMessage()]);
            }
        } finally {
            $lock->release();
        }
    }
}
