<?php

namespace Tests\Unit\QuoteParts;

use App\Livewire\QuoteParts\Results;
use App\Services\QuoteParts\PriceDynamicsService;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/** «Результаты» раздела QuoteParts: медиана, валюты для сравнения, вкладка рисуется (без базы). */
class PriceDynamicsTest extends TestCase
{
    public function test_median_and_currency(): void
    {
        $this->assertSame(0.0, PriceDynamicsService::median([]));
        $this->assertSame(0.2, PriceDynamicsService::median([0.3, -0.1, 0.2]));
        $this->assertSame(0.05, PriceDynamicsService::median([0.1, 0.0, -0.2, 0.3]));
        $this->assertSame('RUB', PriceDynamicsService::currency('руб'));
        $this->assertSame('RUB', PriceDynamicsService::currency('RUB'));
        $this->assertSame('USD', PriceDynamicsService::currency('usd'));
    }

    public function test_the_results_tab_draws_both_charts(): void
    {
        $svc = Mockery::mock(PriceDynamicsService::class);
        $svc->shouldReceive('actuality')->andReturn([
            ['day' => '2026-11-12', 'all' => 15.5, 'in_stock' => 30.1, 'to_order' => 9.9, 'actual' => 4860, 'total' => 31313],
            ['day' => '2026-11-13', 'all' => 16.0, 'in_stock' => 31.0, 'to_order' => 10.2, 'actual' => 5010, 'total' => 31320],
        ]);
        $svc->shouldReceive('purchaseTrend')->andReturn([
            ['week' => '2026-11-09', 'n' => 9, 'up' => 2, 'down' => 6, 'flat' => 1, 'median' => -7.5, 'unmatched' => 2],
        ]);
        $this->app->instance(PriceDynamicsService::class, $svc);

        Livewire::test(Results::class)
            ->assertSee('16,0%')
            ->assertSee('5 010 из 31 320')
            ->assertSee('дешевле прошлой закупки')
            ->assertSee('-7,5%')
            ->assertSeeHtml('<polyline');
    }
}
