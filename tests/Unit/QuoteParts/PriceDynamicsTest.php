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

    /** A new decision is compared with the latest known price: our previous decision once it is newer than 1C's purchase. */
    public function test_a_decision_is_compared_with_the_latest_known_price(): void
    {
        $known = collect([
            10 => collect([
                (object) ['price' => 100.0, 'currency' => 'USD', 'at' => '2026-09-30', 'own' => false],  // закупка 1С
                (object) ['price' => 90.0, 'currency' => 'USD', 'at' => '2026-11-12 12:00', 'own' => true], // наше решение
                (object) ['price' => 50.0, 'currency' => 'EUR', 'at' => '2026-12-01', 'own' => true],        // другая валюта
            ]),
        ]);
        $decisions = collect([
            (object) ['catalog_item_id' => 10, 'price' => 90.0, 'currency' => 'USD', 'decided_at' => '2026-11-12 12:00'], // против 1С: −10%
            (object) ['catalog_item_id' => 10, 'price' => 99.0, 'currency' => 'USD', 'decided_at' => '2027-03-01 10:00'], // против нашего: +10%
            (object) ['catalog_item_id' => 20, 'price' => 5.0, 'currency' => 'USD', 'decided_at' => '2027-03-01 10:00'],  // сравнить не с чем
        ]);

        $weeks = PriceDynamicsService::trend($decisions, $known);

        $this->assertSame(['n' => 1, 'down' => 1, 'median' => -10.0, 'vs_own' => 0], array_intersect_key($weeks[0], array_flip(['n', 'down', 'median', 'vs_own'])));
        $this->assertSame(['n' => 1, 'up' => 1, 'median' => 10.0, 'unmatched' => 1, 'vs_own' => 1], array_intersect_key($weeks[1], array_flip(['n', 'up', 'median', 'unmatched', 'vs_own'])));
    }

    public function test_the_results_tab_draws_both_charts(): void
    {
        $svc = Mockery::mock(PriceDynamicsService::class);
        $svc->shouldReceive('actuality')->andReturn([
            ['day' => '2026-11-12', 'all' => 15.5, 'in_stock' => 30.1, 'to_order' => 9.9, 'actual' => 4860, 'total' => 31313],
            ['day' => '2026-11-13', 'all' => 16.0, 'in_stock' => 31.0, 'to_order' => 10.2, 'actual' => 5010, 'total' => 31320],
        ]);
        $svc->shouldReceive('purchaseTrend')->andReturn([
            ['week' => '2026-11-09', 'n' => 9, 'up' => 2, 'down' => 6, 'flat' => 1, 'median' => -7.5, 'unmatched' => 2, 'vs_own' => 0],
        ]);
        $this->app->instance(PriceDynamicsService::class, $svc);

        Livewire::test(Results::class)
            ->assertSee('16,0%')
            ->assertSee('5 010 из 31 320')
            ->assertSee('дешевле прошлой цены')
            ->assertSee('-7,5%')
            ->assertSeeHtml('<polyline');
    }
}
