<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Catalog\EmbedCatalogChangesJob;
use App\Jobs\Catalog\ImportSupplierPricesJob;
use App\Jobs\Kb\AnalyzeArticleCodesJob;
use App\Jobs\Kb\ResolveKbJob;
use App\Jobs\Mail\ParseRequestItemsJob;
use App\Jobs\Marketing\MakeMediaCoverJob;
use App\Jobs\QuoteParts\SyncQuotePartsPoolJob;
use Tests\TestCase;

/** Долгие фоновые задачи — в очереди heavy, и их лимит меньше её retry_after (иначе задача идёт дважды). */
class HeavyQueueTest extends TestCase
{
    public function test_long_background_jobs_go_to_the_heavy_queue_within_its_retry_after(): void
    {
        $retryAfter = (int) config('queue.connections.database-heavy.retry_after');
        $jobs = [
            new ResolveKbJob(1, 186),
            new EmbedCatalogChangesJob,
            new ImportSupplierPricesJob(1),
            new MakeMediaCoverJob(1),
            new SyncQuotePartsPoolJob,
            new AnalyzeArticleCodesJob([]),
        ];

        foreach ($jobs as $job) {
            $this->assertSame('database-heavy', $job->connection, $job::class);
            $this->assertSame('heavy', $job->queue, $job::class);
            $this->assertLessThan($retryAfter, $job->timeout, $job::class);
        }
    }

    public function test_small_request_kb_pass_stays_on_the_common_queue(): void
    {
        $job = new ResolveKbJob(1, 3);

        $this->assertNull($job->connection);
        $this->assertSame(600, $job->timeout);
        $this->assertLessThan(900, $job->timeout); // меньше retry_after общей очереди (960)
    }

    public function test_heavy_connection_reads_the_same_jobs_table(): void
    {
        $this->assertSame('database', config('queue.connections.database-heavy.driver'));
        $this->assertSame(config('queue.connections.database.table'), config('queue.connections.database-heavy.table'));
    }

    public function test_request_parsing_stays_on_the_common_queue(): void
    {
        $this->assertNull((new \ReflectionClass(ParseRequestItemsJob::class))->newInstanceWithoutConstructor()->connection);
    }
}
