<?php

namespace Tests\Unit\Iqot;

use App\Models\IqotPosition;
use Tests\TestCase;

/**
 * «Запросить КП» по группе: повторно не шлём только то, где КП конкурентов уже есть
 * по недавнему запросу. Дата — last_enqueued_at: analyzed_at опрос IQOT двигает каждый раз.
 */
class IqotRecentOffersTest extends TestCase
{
    private static function position(int $offers, ?string $enqueued): IqotPosition
    {
        $p = new IqotPosition;
        $p->report = ['offers_count' => $offers, 'all_offers' => []];
        $p->analyzed_at = now();
        $p->last_enqueued_at = $enqueued !== null ? now()->sub($enqueued) : null;

        return $p;
    }

    public function test_offers_from_a_recent_request_count(): void
    {
        $this->assertTrue(self::position(3, '10 days')->hasRecentOffers(90));
    }

    public function test_no_offers_is_never_recent(): void
    {
        $this->assertFalse(self::position(0, '1 day')->hasRecentOffers(90));
    }

    public function test_old_request_is_not_recent_though_polled_today(): void
    {
        $this->assertFalse(self::position(5, '120 days')->hasRecentOffers(90));
    }
}
