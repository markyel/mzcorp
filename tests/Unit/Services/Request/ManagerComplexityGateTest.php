<?php

namespace Tests\Unit\Services\Request;

use App\Enums\ComplexityLevel;
use App\Models\Request;
use App\Models\User;
use App\Services\Request\ManagerComplexityGate;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Потолок сложности заявок у менеджера. Без БД: подменяем подсчёт позиций
 * (единственное обращение к базе) наследником гейта.
 */
class ManagerComplexityGateTest extends TestCase
{
    private function gate(int $total, int $internalSku): ManagerComplexityGate
    {
        return new class($total, $internalSku) extends ManagerComplexityGate
        {
            public function __construct(private readonly int $total, private readonly int $sku)
            {
            }

            /** @return array{total: int, internal_sku: int} */
            protected function itemStats(Request $request): array
            {
                return ['total' => $this->total, 'internal_sku' => $this->sku];
            }
        };
    }

    private function manager(?string $max, bool $onlySku = false): User
    {
        $u = new User(['name' => 'М', 'email' => 'm@myzip.ru']);
        $u->id = 1;
        $u->max_complexity_level = $max;
        $u->only_internal_sku_requests = $onlySku;

        return $u;
    }

    private function request(ComplexityLevel $level): Request
    {
        $r = new Request;
        $r->id = 100;
        $r->complexity_level = $level;

        return $r;
    }

    public function test_manager_without_limits_takes_anything(): void
    {
        $gate = $this->gate(3, 0);

        $this->assertTrue($gate->canTake($this->manager(null), $this->request(ComplexityLevel::VeryHard)));
    }

    public function test_easy_only_manager_takes_easy_and_skips_normal(): void
    {
        $gate = $this->gate(2, 2);
        $manager = $this->manager(ComplexityLevel::Easy->value);

        $this->assertTrue($gate->canTake($manager, $this->request(ComplexityLevel::Easy)));
        $this->assertFalse($gate->canTake($manager, $this->request(ComplexityLevel::Normal)));
    }

    public function test_ceiling_includes_everything_below_it(): void
    {
        $gate = $this->gate(2, 0);
        $manager = $this->manager(ComplexityLevel::Normal->value);

        $this->assertTrue($gate->canTake($manager, $this->request(ComplexityLevel::Easy)));
        $this->assertTrue($gate->canTake($manager, $this->request(ComplexityLevel::Normal)));
        $this->assertFalse($gate->canTake($manager, $this->request(ComplexityLevel::Hard)));
    }

    public function test_m_articles_only_requires_every_item_to_be_internal_sku(): void
    {
        $manager = $this->manager(null, onlySku: true);

        $this->assertTrue($this->gate(3, 3)->canTake($manager, $this->request(ComplexityLevel::Easy)));
        $this->assertFalse($this->gate(3, 2)->canTake($manager, $this->request(ComplexityLevel::Easy)));
    }

    public function test_request_without_items_is_not_given_to_a_limited_manager(): void
    {
        $gate = $this->gate(0, 0);

        $this->assertFalse($gate->canTake($this->manager(ComplexityLevel::Easy->value), $this->request(ComplexityLevel::Easy)));
        $this->assertTrue($gate->canTake($this->manager(null), $this->request(ComplexityLevel::Easy)));
    }

    public function test_filter_keeps_eligible_managers(): void
    {
        $free = $this->manager(null);
        $limited = $this->manager(ComplexityLevel::Easy->value);
        $limited->id = 2;

        $result = $this->gate(2, 0)->filter(new Collection([$free, $limited]), $this->request(ComplexityLevel::Hard));

        $this->assertSame([1], $result['managers']->pluck('id')->all());
        $this->assertSame([2], $result['excluded']);
        $this->assertFalse($result['relaxed']);
    }

    public function test_filter_relaxes_when_nobody_can_take_the_request(): void
    {
        $a = $this->manager(ComplexityLevel::Easy->value);
        $b = $this->manager(ComplexityLevel::Easy->value);
        $b->id = 2;

        $result = $this->gate(2, 0)->filter(new Collection([$a, $b]), $this->request(ComplexityLevel::VeryHard));

        $this->assertCount(2, $result['managers']);
        $this->assertTrue($result['relaxed']);
    }
}
