<?php

namespace Tests\Unit\Support;

use App\Support\ScheduledTaskName;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use PHPUnit\Framework\TestCase;

class ScheduledTaskNameTest extends TestCase
{
    public function test_command_name_without_arguments_and_job_class_basename(): void
    {
        $mutex = $this->createMock(EventMutex::class);

        $this->assertSame('suppliers:remind', ScheduledTaskName::of(new Event($mutex, "'/usr/bin/php8.3' 'artisan' suppliers:remind")));
        $this->assertSame('reports:weekly-generate', ScheduledTaskName::of(new Event($mutex, "'/usr/bin/php8.3' 'artisan' reports:weekly-generate --send")));
        $this->assertSame('mail:sync', ScheduledTaskName::of(new Event($mutex, "'/usr/bin/php8.3' 'artisan' mail:sync --folder=inbox")));

        $job = (new CallbackEvent($mutex, fn () => null))->name('App\Jobs\QuoteParts\SyncQuotePartsRfqsJob');
        $this->assertSame('SyncQuotePartsRfqsJob', ScheduledTaskName::of($job));
    }
}
