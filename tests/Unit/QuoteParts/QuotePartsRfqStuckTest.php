<?php

namespace Tests\Unit\QuoteParts;

use App\Models\QuotePartsRfq;
use Tests\TestCase;

/** «Застрявшие» запросы через QuoteParts — как у прямых запросов: ответа нет, а ход не за поставщиком. */
class QuotePartsRfqStuckTest extends TestCase
{
    private static function rfq(array $suppliers, string $status = 'open', ?string $replyBy = null): QuotePartsRfq
    {
        return new QuotePartsRfq(['status' => $status, 'reply_by' => $replyBy, 'snapshot' => ['suppliers' => $suppliers]]);
    }

    private static function supplier(string $key, string $line, array $extra = []): array
    {
        return array_merge(['external_id' => $key, 'sent' => true, 'send_error' => null, 'last_reply_at' => null, 'reminders_sent' => 0, 'thread' => [],
            'lines' => [['status' => $line, 'postponed_until' => $extra['postponed_until'] ?? null]]], array_diff_key($extra, ['postponed_until' => true]));
    }

    public function test_reasons(): void
    {
        $rfq = self::rfq([
            self::supplier('org:1', 'offered', ['last_reply_at' => '2026-10-20T10:00:00+00:00']),
            self::supplier('org:2', 'pending', ['last_reply_at' => '2026-10-20T10:00:00+00:00', 'thread' => [['direction' => 'in', 'at' => '2026-10-20T10:00:00+00:00']]]),
            self::supplier('org:3', 'question', ['thread' => [['direction' => 'in', 'at' => '2026-10-20T10:00:00+00:00']]]),
            self::supplier('org:4', 'pending', ['reminders_sent' => 2]),
            self::supplier('org:5', 'pending', ['sent' => false, 'send_error' => 'SMTP']),
            self::supplier('org:6', 'pending'),
        ]);

        $this->assertSame(['org:2' => 'no_offer', 'org:3' => 'question', 'org:4' => 'silent', 'org:5' => 'error'], $rfq->stuckSuppliers());
    }

    public function test_our_last_letter_postponed_lines_and_closed_requests_are_not_stuck(): void
    {
        $answered = self::supplier('org:3', 'question', ['last_reply_at' => '2026-10-20T10:00:00+00:00', 'thread' => [
            ['direction' => 'in', 'at' => '2026-10-20T10:00:00+00:00'], ['direction' => 'out', 'at' => '2026-10-20T11:00:00+00:00'],
        ]]);
        $postponed = self::supplier('org:7', 'pending', ['reminders_sent' => 5, 'postponed_until' => now()->addDays(3)->toDateString()]);

        $this->assertSame([], self::rfq([$answered, $postponed])->stuckSuppliers());
        $this->assertSame([], self::rfq([self::supplier('org:4', 'pending', ['reminders_sent' => 2])], 'closed')->stuckSuppliers());
        $this->assertSame(['org:6' => 'silent'], self::rfq([self::supplier('org:6', 'pending')], 'open', now()->subDay()->toDateString())->stuckSuppliers(), 'срок ответа прошёл');
    }
}
