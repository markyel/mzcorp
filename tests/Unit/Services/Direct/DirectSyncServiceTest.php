<?php

namespace Tests\Unit\Services\Direct;

use App\Models\DirectPublishedAd;
use App\Services\Direct\DirectSyncService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Решения конвейера: кому показываться, а кому погаснуть.
 *
 * Правила, которые здесь и проверяются: показываем первые N очереди, у которых
 * объявление принято модерацией; гасим всё остальное, что горит; черновик в
 * показ не переводим — он проверки не проходил. Без БД и без сети.
 */
class DirectSyncServiceTest extends TestCase
{
    private function ad(string $sku, int $id, ?string $state = 'OFF', ?string $status = 'ACCEPTED'): DirectPublishedAd
    {
        $ad = new DirectPublishedAd(['sku' => $sku, 'state' => $state, 'status' => $status]);
        $ad->ad_id = $id;

        return $ad;
    }

    private function sync(): DirectSyncService
    {
        return app(DirectSyncService::class);
    }

    public function test_position_out_of_stock_is_switched_off(): void
    {
        [$suspend, $resume] = $this->sync()->decide(
            [],                                                   // очередь пуста: позиции не стало
            new Collection([$this->ad('M00193', 11, 'ON')]),
            [11 => ['State' => 'ON', 'Status' => 'ACCEPTED']],
            10,
        );

        $this->assertCount(1, $suspend);
        $this->assertStringContainsString('нет остатка', $suspend[0]['reason']);
        $this->assertSame([], $resume);
    }

    public function test_bench_ad_takes_the_free_slot(): void
    {
        // Позиция в очереди, объявление принято и выключено — включаем.
        [$suspend, $resume] = $this->sync()->decide(
            ['M00193'],
            new Collection([$this->ad('M00193', 11, 'OFF')]),
            [11 => ['State' => 'OFF', 'Status' => 'ACCEPTED']],
            10,
        );

        $this->assertSame([], $suspend);
        $this->assertCount(1, $resume);
        $this->assertSame('M00193', $resume[0]['sku']);
    }

    public function test_draft_is_never_put_on_air_by_the_robot(): void
    {
        [, $resume] = $this->sync()->decide(
            ['M00193'],
            new Collection([$this->ad('M00193', 11, 'OFF', 'DRAFT')]),
            [11 => ['State' => 'OFF', 'Status' => 'DRAFT']],
            10,
        );

        $this->assertSame([], $resume);
    }

    public function test_show_limit_is_respected_and_the_rest_waits(): void
    {
        // Три готовых объявления, показываем два: третье гаснет и ждёт.
        $ads = new Collection([
            $this->ad('A', 1, 'ON'),
            $this->ad('B', 2, 'ON'),
            $this->ad('C', 3, 'ON'),
        ]);
        $states = [
            1 => ['State' => 'ON', 'Status' => 'ACCEPTED'],
            2 => ['State' => 'ON', 'Status' => 'ACCEPTED'],
            3 => ['State' => 'ON', 'Status' => 'ACCEPTED'],
        ];

        [$suspend, $resume] = $this->sync()->decide(['A', 'B', 'C'], $ads, $states, 2);

        $this->assertCount(1, $suspend);
        $this->assertSame('C', $suspend[0]['sku']);
        $this->assertStringContainsString('вытеснена', $suspend[0]['reason']);
        $this->assertSame([], $resume);
    }

    public function test_queue_order_decides_who_shows(): void
    {
        // Первая в очереди ещё черновик — слот достаётся следующей готовой.
        $ads = new Collection([
            $this->ad('A', 1, 'OFF', 'DRAFT'),
            $this->ad('B', 2, 'OFF', 'ACCEPTED'),
        ]);
        $states = [1 => ['State' => 'OFF', 'Status' => 'DRAFT'], 2 => ['State' => 'OFF', 'Status' => 'ACCEPTED']];

        [, $resume] = $this->sync()->decide(['A', 'B'], $ads, $states, 1);

        $this->assertCount(1, $resume);
        $this->assertSame('B', $resume[0]['sku']);
    }

    public function test_state_from_direct_wins_over_our_snapshot(): void
    {
        // Объявление остановили руками в кабинете — второй раз не выключаем.
        [$suspend] = $this->sync()->decide(
            [],
            new Collection([$this->ad('M00193', 11, 'ON')]),
            [11 => ['State' => 'OFF', 'Status' => 'ACCEPTED']],
            10,
        );

        $this->assertSame([], $suspend);
    }

    public function test_nothing_to_do_when_everything_matches(): void
    {
        [$suspend, $resume] = $this->sync()->decide(
            ['M00193'],
            new Collection([$this->ad('M00193', 11, 'ON'), $this->ad('M00538', 12, 'OFF', 'DRAFT')]),
            [11 => ['State' => 'ON', 'Status' => 'ACCEPTED'], 12 => ['State' => 'OFF', 'Status' => 'DRAFT']],
            10,
        );

        $this->assertSame([], $suspend);
        $this->assertSame([], $resume);
    }
}
