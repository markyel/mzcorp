<?php

namespace Tests\Unit\Services\Direct;

use App\Services\Direct\DirectStatsService as Stats;
use PHPUnit\Framework\TestCase;

/**
 * Отчёт Директа приходит как TSV, и первым делом в нём встречается пустота:
 * пока отчёт готовится, приходят одни заголовки без строк.
 */
class DirectStatsParseTest extends TestCase
{
    public function test_a_report_with_only_a_header_gives_no_rows(): void
    {
        $this->assertSame([], Stats::parseTsv("Date\tQuery\tImpressions\tClicks\n"));
        $this->assertSame([], Stats::parseTsv(''));
    }

    public function test_rows_are_keyed_by_the_header(): void
    {
        $body = "Date\tQuery\tCriteria\tCriteriaType\tImpressions\tClicks\tCost\n"
            ."2026-09-22\tповодок поручня отис\tповодок поручня otis\tAUTOTARGETING\t3\t1\t12,20\n";

        $rows = Stats::parseTsv($body);

        $this->assertCount(1, $rows);
        $this->assertSame('поводок поручня отис', $rows[0]['Query']);
        $this->assertSame('AUTOTARGETING', $rows[0]['CriteriaType']);
        $this->assertSame('3', $rows[0]['Impressions']);
    }

    public function test_a_broken_line_is_skipped_not_guessed(): void
    {
        $body = "Date\tQuery\tImpressions\n2026-09-22\tзапрос\n2026-09-22\tзапрос\t5\n";

        $rows = Stats::parseTsv($body);

        $this->assertCount(1, $rows);
        $this->assertSame('5', $rows[0]['Impressions']);
    }
}
