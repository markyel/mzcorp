<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\AiUsageMeter;
use Tests\TestCase;

/** Стоимость вызова по ценам из конфига; версия модели — по самому длинному известному названию. */
class AiUsageCostTest extends TestCase
{
    public function test_cost(): void
    {
        config(['services.openai.prices' => ['gpt-4o' => [2.5, 10, 1.25], 'gpt-4o-mini' => [0.15, 0.6, 0.075]]]);

        $this->assertEqualsWithDelta(0.0025 + 0.001, AiUsageMeter::cost('gpt-4o-2024-08-06', 1000, 100), 1e-9);
        $this->assertEqualsWithDelta((1_000_000 * 0.15 + 1_000_000 * 0.6) / 1_000_000, AiUsageMeter::cost('gpt-4o-mini-2024-07-18', 1_000_000, 1_000_000), 1e-9);
        $this->assertEqualsWithDelta(0.075 / 2 + 0.15 / 2, AiUsageMeter::cost('gpt-4o-mini', 1_000_000, 0, 500_000), 1e-9);
        $this->assertNull(AiUsageMeter::cost('unknown-model', 10, 10));
    }
}
