<?php

namespace Tests\Unit\Services\Quotes;

use App\Models\Request as RequestModel;
use App\Services\AI\OpenAIChatService;
use App\Services\Quotes\OutboundQuoteParsingService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Модель взяла «Цену» (до скидки) вместо «Цены со скидкой». КП 370340
 * (13.10.2026, M-2026-19276): строки «9 980,00 79,26 2 070,02 20 700,20» —
 * unit_price 9980, сторож закупка+15% разослал ложную тревогу. По тексту PDF
 * берём цену со скидкой; сумма строк сходится с итогом 98 607,00.
 */
class OutboundQuoteDiscountColumnTest extends TestCase
{
    private const TEXT = <<<'TXT'
 1 Ролик направляющий башмака Doppler, тип 85х30                M05333   10,00 шт        10,00 шт    9 980,00   79,26      2 070,02    20 700,20     3 732,82
                                                                         2,00 шт    В наличии
 2 Ролик направляющий башмака Doppler, тип 125х30               M26889                  10,00 шт   12 397,34   37,16      7 790,68    77 906,80    14 048,77
                                                                         8,00 шт
                                                    Итого:              223 773,40
                                            Скидка %55,93:            - 125 166,40
                                         Итого со скидкой:               98 607,00
TXT;

    /** @return array<int, array<string, mixed>> */
    private function apply(array $items, ?string $text): array
    {
        $m = new ReflectionMethod(OutboundQuoteParsingService::class, 'applyDiscountColumn');
        $r = new RequestModel();
        $r->id = 19276;

        return $m->invoke(new OutboundQuoteParsingService($this->createMock(OpenAIChatService::class)), $items, $text, $r);
    }

    public function test_price_before_discount_is_replaced_by_the_discounted_price(): void
    {
        $items = $this->apply([
            ['article' => 'M05333', 'quantity' => 10, 'unit_price' => 9980.00, 'price' => 9980.00, 'total' => 20700.20],
            ['article' => 'M26889', 'quantity' => 2, 'unit_price' => 12397.34, 'price' => 12397.34, 'total' => 24794.68],
            ['article' => 'M26889', 'quantity' => 8, 'unit_price' => 12397.34, 'price' => 12397.34, 'total' => 99112.72],
        ], self::TEXT);

        $this->assertSame([2070.02, 7790.68, 7790.68], array_column($items, 'unit_price'));
        $this->assertEqualsWithDelta(98607.00, array_sum(array_column($items, 'total')), 0.01);
        $this->assertSame(79.26, $items[0]['discount_percent']);
        $this->assertSame(9980.00, $items[0]['base_unit_price']);
    }

    public function test_correct_discounted_price_is_left_alone(): void
    {
        $items = [['article' => 'M05333', 'quantity' => 10, 'unit_price' => 2070.02, 'price' => 2070.02, 'total' => 20700.20]];

        $this->assertSame($items, $this->apply($items, self::TEXT));
    }

    public function test_without_text_layer_nothing_changes(): void
    {
        $items = [['quantity' => 10, 'unit_price' => 9980.00, 'total' => 20700.20]];

        $this->assertSame($items, $this->apply($items, null));
    }

    public function test_measured_lines_are_not_touched(): void
    {
        $items = [['quantity' => 2, 'unit_quantity' => 5, 'unit_price' => 9980.00, 'total' => 99800.00]];

        $this->assertSame($items, $this->apply($items, self::TEXT));
    }
}
