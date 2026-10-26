<?php

namespace Tests\Unit\QuoteParts;

use App\Jobs\QuoteParts\SyncQuotePartsRfqsJob;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsPriceService;
use App\Services\QuoteParts\QuotePartsRfqReadiness;
use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * Синхронизация запросов QuoteParts идёт минуты, а очередь переотдаёт задачу через 90 с: вторая выдача
 * должна тихо выйти (прогон уже идёт), а не падать MaxAttemptsExceeded в failed_jobs.
 */
class SyncQuotePartsRfqsJobTest extends TestCase
{
    private function client(): QuotePartsClient
    {
        $client = Mockery::mock(QuotePartsClient::class);
        $client->shouldReceive('isConfigured')->andReturnTrue();

        return $client;
    }

    public function test_a_second_delivery_while_the_run_goes_on_does_nothing_and_does_not_fail(): void
    {
        $running = Cache::lock('quoteparts:rfqs-sync', 900);
        $this->assertTrue($running->get());

        $service = Mockery::mock(QuotePartsRfqService::class);
        $service->shouldNotReceive('refreshRecent');

        (new SyncQuotePartsRfqsJob)->handle($service, $this->client());

        $running->release();
    }

    public function test_a_run_refreshes_within_the_budget_then_frees_the_lock(): void
    {
        $service = Mockery::mock(QuotePartsRfqService::class);
        $service->shouldReceive('refreshRecent')->once()->with(SyncQuotePartsRfqsJob::REFRESH_BUDGET_SECONDS)->andReturn(190);
        $prices = Mockery::mock(QuotePartsPriceService::class);
        $prices->shouldReceive('syncOffers')->once()->andReturn(0);
        $prices->shouldReceive('syncDeclines')->once()->andReturn(0);
        $this->app->instance(QuotePartsPriceService::class, $prices);
        $readiness = Mockery::mock(QuotePartsRfqReadiness::class);
        $readiness->shouldReceive('notifyReady')->once()->andReturn([]);
        $this->app->instance(QuotePartsRfqReadiness::class, $readiness);

        (new SyncQuotePartsRfqsJob)->handle($service, $this->client());

        $this->assertTrue(Cache::lock('quoteparts:rfqs-sync', 900)->get(), 'блокировка снята после прогона');
    }

    public function test_the_queue_may_deliver_it_again_and_the_run_fits_the_timeout(): void
    {
        $job = new SyncQuotePartsRfqsJob;

        $this->assertGreaterThan(1, $job->tries, 'вторая выдача через retry_after не должна падать MaxAttemptsExceeded');
        $this->assertLessThan($job->timeout, SyncQuotePartsRfqsJob::REFRESH_BUDGET_SECONDS);
        $this->assertGreaterThanOrEqual($job->timeout, $job->uniqueFor);
    }
}
