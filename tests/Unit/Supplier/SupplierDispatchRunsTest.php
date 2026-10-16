<?php

namespace Tests\Unit\Supplier;

use App\Jobs\Suppliers\SendSupplierDispatchJob;
use App\Services\Supplier\SupplierDispatchRuns;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Фоновая рассылка запросов поставщикам: ход по заявке, одна рассылка за раз (без базы).
 */
class SupplierDispatchRunsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_one_run_per_request_while_it_goes(): void
    {
        $runs = app(SupplierDispatchRuns::class);
        $run = $runs->start(19856, 7, 'Якубович Дмитрий', ['EVE', 'OSS'], [['supplier' => 'ES', 'at' => '11:38']]);

        $this->assertSame('queued', $runs->active(19856)['status']);
        $this->assertSame(2, $run['total']);

        $runs->update(19856, $run['id'], ['status' => 'running', 'sent' => 1, 'current' => 'OSS']);
        $this->assertSame(1, $runs->current(19856)['sent']);
        $this->assertSame([['supplier' => 'ES', 'at' => '11:38']], $runs->current(19856)['skipped']);

        // Чужая (старая) рассылка не перезаписывает текущую.
        $runs->update(19856, 'other-run', ['sent' => 99]);
        $this->assertSame(1, $runs->current(19856)['sent']);

        $runs->update(19856, $run['id'], ['status' => 'done', 'sent' => 2]);
        $this->assertNull($runs->active(19856));
    }

    public function test_a_stuck_run_does_not_block_a_new_one(): void
    {
        $runs = app(SupplierDispatchRuns::class);
        $runs->start(1, 7, 'М', ['A']);
        Carbon::setTestNow(now()->addMinutes(25));

        $this->assertNull($runs->active(1));
    }

    public function test_a_redelivered_attempt_does_not_mark_the_run_failed(): void
    {
        $runs = app(SupplierDispatchRuns::class);
        $run = $runs->start(2, 7, 'М', ['A', 'B']);
        $job = new SendSupplierDispatchJob($run['id'], 2, [10, 11], [1], null, 7);

        // Очередь переотдала задачу, пока первая попытка ещё шлёт письмо — рассылка идёт.
        $job->failed(new MaxAttemptsExceededException('attempted too many times'));
        $this->assertSame('queued', $runs->current(2)['status']);

        $job->failed(new \RuntimeException('SMTP down'));
        $this->assertSame('failed', $runs->current(2)['status']);
        $this->assertSame('SMTP down', $runs->current(2)['error']);
    }
}
