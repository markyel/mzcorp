<?php

namespace Tests\Unit\QuoteParts;

use App\Services\QuoteParts\PriceRefreshReadiness;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Готово к актуализации цены: цену дали хотя бы половина спрошенных, или есть хотя бы одно
 * предложение и с запроса прошло больше двух рабочих дней.
 */
class PriceRefreshReadinessTest extends TestCase
{
    private function stat(int $asked, int $offered, string $requestedAt): array
    {
        return [
            'asked' => array_map(fn ($i) => 'sup:'.$i, range(1, $asked)),
            'offered' => $offered > 0 ? array_map(fn ($i) => 'sup:'.$i, range(1, $offered)) : [],
            'requested_at' => Carbon::parse($requestedAt),
        ];
    }

    public function test_at_least_half_answered_is_ready_at_once(): void
    {
        $r = app(PriceRefreshReadiness::class);
        $now = Carbon::parse('2026-10-20 10:00');

        $this->assertTrue($r->isReady($this->stat(3, 2, '2026-10-20 09:00'), $now));
        $this->assertTrue($r->isReady($this->stat(4, 2, '2026-10-20 09:00'), $now), 'ровно половина — готово (с 16.11.2026)');
        $this->assertFalse($r->isReady($this->stat(5, 2, '2026-10-20 09:00'), $now), 'меньше половины — ждём');
    }

    public function test_one_offer_waits_two_business_days(): void
    {
        $r = app(PriceRefreshReadiness::class);
        // Пятница 16.10 15:00 → два рабочих дня истекают во вторник 20.10 в 15:00.
        $stat = $this->stat(5, 1, '2026-10-16 15:00');

        $this->assertSame('2026-10-20 15:00', $r->waitUntil($stat['requested_at'])->format('Y-m-d H:i'));
        $this->assertFalse($r->isReady($stat, Carbon::parse('2026-10-20 14:59')));
        $this->assertTrue($r->isReady($stat, Carbon::parse('2026-10-20 15:01')));
    }

    public function test_no_offers_is_never_ready(): void
    {
        $this->assertFalse(app(PriceRefreshReadiness::class)->isReady($this->stat(3, 0, '2026-09-01 10:00'), Carbon::parse('2026-10-20')));
    }

    /** M03865, 16.11.2026: цену дал 1 из 3, двое отказали — ждать больше некого. */
    public function test_everyone_answered_with_a_price_or_a_refusal_is_ready_at_once(): void
    {
        $r = app(PriceRefreshReadiness::class);
        $now = Carbon::parse('2026-11-13 10:00');
        $stat = $this->stat(3, 1, '2026-11-12 16:33') + ['declined' => ['sup:2', 'sup:3']];

        $this->assertTrue($r->isReady($stat, $now));
        $this->assertFalse($r->isReady(['declined' => ['sup:2']] + $this->stat(3, 1, '2026-11-12 16:33'), $now), 'третий ещё молчит — ждём');
        $this->assertFalse($r->isReady($this->stat(2, 0, '2026-11-12 16:33') + ['declined' => ['sup:1', 'sup:2']], $now), 'все отказали — цены нет');
    }
}
