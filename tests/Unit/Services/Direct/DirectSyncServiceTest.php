<?php

namespace Tests\Unit\Services\Direct;

use App\Models\DirectPublishedAd;
use App\Services\Direct\DirectSyncService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Решения синхронизации. Правило безопасности: выключаем что угодно, включаем
 * только то, что выключали сами и что уже прошло модерацию — черновик автомат
 * в работу не переводит. Без БД и без сети.
 */
class DirectSyncServiceTest extends TestCase
{
    private function ad(string $sku, int $id, ?string $state): DirectPublishedAd
    {
        $ad = new DirectPublishedAd(['sku' => $sku, 'state' => $state]);
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
            new Collection([$this->ad('M00193', 11, 'ON')]),
            [11 => ['State' => 'ON', 'Status' => 'ACCEPTED']],
            [],
        );

        $this->assertCount(1, $suspend);
        $this->assertSame('M00193', $suspend[0]['sku']);
        $this->assertSame([], $resume);
    }

    public function test_position_back_in_stock_is_switched_on(): void
    {
        [$suspend, $resume] = $this->sync()->decide(
            new Collection([$this->ad('M00193', 11, 'OFF')]),
            [11 => ['State' => 'OFF', 'Status' => 'ACCEPTED']],
            ['M00193' => true],
        );

        $this->assertSame([], $suspend);
        $this->assertCount(1, $resume);
    }

    public function test_draft_is_never_switched_on_by_the_robot(): void
    {
        // Черновик модерации не видел — отправлять его в показ должен человек.
        [, $resume] = $this->sync()->decide(
            new Collection([$this->ad('M00193', 11, 'OFF')]),
            [11 => ['State' => 'OFF', 'Status' => 'DRAFT']],
            ['M00193' => true],
        );

        $this->assertSame([], $resume);
    }

    public function test_state_from_direct_wins_over_our_snapshot(): void
    {
        // Объявление остановили руками в кабинете — второй раз не выключаем.
        [$suspend] = $this->sync()->decide(
            new Collection([$this->ad('M00193', 11, 'ON')]),
            [11 => ['State' => 'OFF', 'Status' => 'ACCEPTED']],
            [],
        );

        $this->assertSame([], $suspend);
    }

    public function test_nothing_to_do_when_everything_matches(): void
    {
        [$suspend, $resume] = $this->sync()->decide(
            new Collection([$this->ad('M00193', 11, 'ON'), $this->ad('M00538', 12, 'OFF')]),
            [11 => ['State' => 'ON', 'Status' => 'ACCEPTED'], 12 => ['State' => 'OFF', 'Status' => 'DRAFT']],
            ['M00193' => true],
        );

        $this->assertSame([], $suspend);
        $this->assertSame([], $resume);
    }
}
