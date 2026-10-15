<?php

namespace App\Jobs\QuoteParts;

use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsPoolService;
use App\Services\QuoteParts\QuotePartsSupplierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Передать в QuoteParts новые позиции пула и досылать изменившиеся.
 * Один прогон за раз: lock дольше retry_after очереди (90 с).
 * Сначала подписки пула (правила «группа + фильтры») добавляют подходящие позиции; передача
 * идёт порциями не дольше SYNC_BUDGET_SECONDS — если осталось, следующий прогон ставится сам.
 */
class SyncQuotePartsPoolJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    /** Сколько секунд передавать за прогон (таймаут задачи — 600). */
    public const SYNC_BUDGET_SECONDS = 420;

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

        $more = false;
        try {
            try {
                $added = app(\App\Services\QuoteParts\QuotePartsPoolRuleService::class)->apply();
                if ($added > 0) {
                    Log::info('QuoteParts pool rules added items', ['count' => $added]);
                }
            } catch (\Throwable $e) {
                Log::warning('QuoteParts pool rules failed', ['error' => $e->getMessage()]);
            }

            $stats = $pool->sync(self::SYNC_BUDGET_SECONDS);
            $more = $stats['more'];
            if ($more) {
                return; // остальное — следующим прогоном (ниже, после снятия блокировки)
            }
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
            if ($more) {
                self::dispatch()->delay(now()->addSeconds(15));
            }
        }
    }
}
