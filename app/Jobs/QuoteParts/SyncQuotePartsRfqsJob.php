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
use Illuminate\Support\Facades\Cache;

/**
 * Подтянуть ответы поставщиков по запросам, отправленным через QuoteParts, в заявки.
 *
 * Прогон идёт минуты (190 запросов ~4 мин), а очередь на проде переотдаёт задачу через 90 с
 * (retry_after) второму воркеру. С tries = 1 вторая выдача падала MaxAttemptsExceeded —
 * 6 раз в час в failed_jobs (573 к 26.10.2026), хотя первый воркер всё доделывал. Как у
 * SyncQuotePartsPoolJob: повторы разрешены, один прогон за раз — по lock (вторая выдача тихо
 * выходит), обновление снимков — в пределах бюджета, остальное — следующим прогоном.
 */
class SyncQuotePartsRfqsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    /** Сколько секунд обновлять снимки запросов за прогон (таймаут задачи — 600, дальше офферы и отказы). */
    public const REFRESH_BUDGET_SECONDS = 300;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(QuotePartsRfqService $service, QuotePartsClient $client): void
    {
        if (! $client->isConfigured()) {
            return;
        }

        $lock = Cache::lock('quoteparts:rfqs-sync', 900);
        if (! $lock->get()) {
            return; // прогон уже идёт (очередь переотдала задачу) — не дублируем
        }

        try {
            $service->refreshRecent(self::REFRESH_BUDGET_SECONDS);
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
            // Набралась выборка цен (в т.ч. потому что вышел срок ожидания) — письмо автору запроса.
            try {
                app(\App\Services\QuoteParts\QuotePartsRfqReadiness::class)->notifyReady();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('QuoteParts ready notifications failed', ['error' => $e->getMessage()]);
            }
        } finally {
            $lock->release();
        }
    }
}
