<?php

namespace Tests\Unit\Services\Direct;

use App\Services\Direct\DirectBidService as Bids;
use Tests\TestCase;

/**
 * Выбор ставки по ступеням аукциона.
 *
 * Замер 22.09.2026: нижняя ступень у наших фраз 7–36 ₽ (медиана 12), а мы
 * стояли с 3 ₽ и не участвовали в торгах вовсе. Стратегия прежняя — дешёвый
 * клик по узкой фразе, поэтому берём самую дешёвую ступень, дающую трафик,
 * и всегда ограничиваем потолком. Без БД и без сети.
 */
class DirectBidServiceTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function auction(): array
    {
        return [
            ['TrafficVolume' => 62, 'Bid' => 32_100_000, 'Price' => 27_500_000],
            ['TrafficVolume' => 15, 'Bid' => 27_500_000, 'Price' => 18_700_000],
            ['TrafficVolume' => 5, 'Bid' => 12_000_000, 'Price' => 12_000_000],
        ];
    }

    public function test_tiers_are_sorted_from_cheap_to_dear(): void
    {
        $this->assertSame([5 => 12.0, 15 => 27.5, 62 => 32.1], Bids::tiers($this->auction()));
    }

    public function test_cheapest_tier_wins_by_default(): void
    {
        // Цель 0 = «любой трафик»: берём вход в аукцион.
        $this->assertSame(12.0, Bids::pickBid(Bids::tiers($this->auction()), 0, 25.0));
    }

    public function test_target_volume_moves_up_the_ladder(): void
    {
        $this->assertSame(27.5, Bids::pickBid(Bids::tiers($this->auction()), 15, 100.0));
        $this->assertSame(32.1, Bids::pickBid(Bids::tiers($this->auction()), 50, 100.0));
    }

    public function test_cap_always_wins(): void
    {
        // Потолок — защита от аукциона, просящего сотни рублей за клик.
        $this->assertSame(20.0, Bids::pickBid(Bids::tiers($this->auction()), 15, 20.0));
        $this->assertSame(10.0, Bids::pickBid(Bids::tiers($this->auction()), 0, 10.0));
    }

    public function test_no_auction_data_falls_back_to_the_cap(): void
    {
        $this->assertSame([], Bids::tiers([]));
        $this->assertSame(25.0, Bids::pickBid([], 0, 25.0));
    }
}
