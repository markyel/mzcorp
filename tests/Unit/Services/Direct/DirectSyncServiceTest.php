<?php

namespace Tests\Unit\Services\Direct;

use App\Models\DirectPublishedAd;
use App\Services\Direct\DirectSyncService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Решения конвейера: кому показываться, а кому погаснуть.
 *
 * Важная тонкость Директа, на которой легко ошибиться: `OFF` у объявления
 * значит «не показывается, потому что остановлена кампания», а НАШЕ выключение
 * — это `SUSPENDED`. Поэтому гасим и `OFF` (иначе запуск кампании поднимет
 * разом всё созданное мимо лимита показа), а включаем только `SUSPENDED`.
 *
 * Без БД и без сети.
 */
class DirectSyncServiceTest extends TestCase
{
    private function ad(string $sku, int $id, string $state = 'SUSPENDED', string $status = 'ACCEPTED'): DirectPublishedAd
    {
        $ad = new DirectPublishedAd(['sku' => $sku, 'state' => $state, 'status' => $status]);
        $ad->ad_id = $id;

        return $ad;
    }

    private function states(array $rows): array
    {
        $out = [];
        foreach ($rows as $id => [$state, $status]) {
            $out[$id] = ['State' => $state, 'Status' => $status];
        }

        return $out;
    }

    private function sync(): DirectSyncService
    {
        return app(DirectSyncService::class);
    }

    public function test_position_out_of_stock_is_switched_off(): void
    {
        [$suspend, $resume] = $this->sync()->decide(
            [],                                     // очередь пуста: позиции не стало
            new Collection([$this->ad('M00193', 11, 'ON')]),
            $this->states([11 => ['ON', 'ACCEPTED']]),
            10,
        );

        $this->assertCount(1, $suspend);
        $this->assertStringContainsString('нет остатка', $suspend[0]['reason']);
        $this->assertSame([], $resume);
    }

    public function test_bench_ad_takes_the_free_slot(): void
    {
        [$suspend, $resume] = $this->sync()->decide(
            ['M00193'],
            new Collection([$this->ad('M00193', 11, 'SUSPENDED')]),
            $this->states([11 => ['SUSPENDED', 'ACCEPTED']]),
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
            new Collection([$this->ad('M00193', 11, 'SUSPENDED', 'DRAFT')]),
            $this->states([11 => ['SUSPENDED', 'DRAFT']]),
            10,
        );

        $this->assertSame([], $resume);
    }

    public function test_extra_ads_are_suspended_even_while_the_campaign_is_stopped(): void
    {
        // Кампания остановлена, значит все объявления в состоянии OFF. Лишние
        // надо погасить заранее: иначе запуск кампании поднимет их все разом.
        [$suspend, $resume] = $this->sync()->decide(
            ['A'],
            new Collection([$this->ad('A', 1, 'OFF'), $this->ad('B', 2, 'OFF')]),
            $this->states([1 => ['OFF', 'ACCEPTED'], 2 => ['OFF', 'ACCEPTED']]),
            10,
        );

        $this->assertCount(1, $suspend);
        $this->assertSame('B', $suspend[0]['sku']);
        // A нужен и не выключен нами — трогать нечего, он поднимется с кампанией.
        $this->assertSame([], $resume);
    }

    public function test_draft_is_not_suspended_either(): void
    {
        // «Объявление является черновиком и не может быть остановлено» —
        // и не надо: показов оно не даёт. Погасим после модерации.
        [$suspend] = $this->sync()->decide(
            [],
            new Collection([$this->ad('M00193', 11, 'OFF', 'DRAFT')]),
            $this->states([11 => ['OFF', 'DRAFT']]),
            10,
        );

        $this->assertSame([], $suspend);
    }

    public function test_show_limit_is_respected_and_the_rest_waits(): void
    {
        $ads = new Collection([$this->ad('A', 1, 'ON'), $this->ad('B', 2, 'ON'), $this->ad('C', 3, 'ON')]);
        $states = $this->states([1 => ['ON', 'ACCEPTED'], 2 => ['ON', 'ACCEPTED'], 3 => ['ON', 'ACCEPTED']]);

        [$suspend, $resume] = $this->sync()->decide(['A', 'B', 'C'], $ads, $states, 2);

        $this->assertCount(1, $suspend);
        $this->assertSame('C', $suspend[0]['sku']);
        $this->assertStringContainsString('вытеснена', $suspend[0]['reason']);
        $this->assertSame([], $resume);
    }

    public function test_queue_order_decides_who_shows(): void
    {
        // Первая в очереди ещё черновик — слот достаётся следующей готовой.
        $ads = new Collection([$this->ad('A', 1, 'SUSPENDED', 'DRAFT'), $this->ad('B', 2, 'SUSPENDED')]);
        $states = $this->states([1 => ['SUSPENDED', 'DRAFT'], 2 => ['SUSPENDED', 'ACCEPTED']]);

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
            $this->states([11 => ['SUSPENDED', 'ACCEPTED']]),
            10,
        );

        $this->assertSame([], $suspend);
    }

    public function test_nothing_to_do_when_everything_matches(): void
    {
        [$suspend, $resume] = $this->sync()->decide(
            ['M00193'],
            new Collection([$this->ad('M00193', 11, 'ON'), $this->ad('M00538', 12, 'SUSPENDED', 'DRAFT')]),
            $this->states([11 => ['ON', 'ACCEPTED'], 12 => ['SUSPENDED', 'DRAFT']]),
            10,
        );

        $this->assertSame([], $suspend);
        $this->assertSame([], $resume);
    }
}
