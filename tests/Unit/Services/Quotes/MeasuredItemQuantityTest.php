<?php

namespace Tests\Unit\Services\Quotes;

use App\Models\RequestItem;
use PHPUnit\Framework\TestCase;

/**
 * Мерные позиции: «73 метра — 3 бухты».
 *
 * Кейс M-2026-16956: цена каталога у тягового ремня — за метр, клиент просил
 * три бухты по 73 м, а авто-КП посчитало 3 × 995,55 = 2 986 ₽ вместо
 * 3 × 73 × 995,55 ≈ 218 000 ₽ и пометило заявку готовой к отправке. Разница в
 * девяносто раз, и уходит она клиенту одной кнопкой — поэтому правило теперь
 * такое: пока единица расчёта не выбрана человеком, авто-КП по позиции нет.
 */
class MeasuredItemQuantityTest extends TestCase
{
    private function item(array $fields): RequestItem
    {
        $item = new RequestItem;
        foreach ($fields as $key => $value) {
            $item->{$key} = $value;
        }

        return $item;
    }

    public function test_a_piece_is_a_piece(): void
    {
        $item = $this->item(['parsed_qty' => 3, 'parsed_unit' => 'шт.']);

        $this->assertFalse($item->isMeasured());
        $this->assertSame(3.0, $item->effectiveQty());
    }

    public function test_a_length_alone_does_not_change_the_quantity(): void
    {
        // Единица расчёта не выбрана — считать метрами нельзя: цена может
        // быть и за бухту. Именно поэтому такая позиция не идёт в авто-КП.
        $item = $this->item([
            'parsed_qty' => 3, 'parsed_unit' => 'шт.',
            'parsed_length' => 73, 'parsed_length_unit' => 'м',
        ]);

        $this->assertTrue($item->isMeasured());
        $this->assertSame(3.0, $item->effectiveQty());
    }

    public function test_choosing_metres_multiplies_the_length_in(): void
    {
        $item = $this->item([
            'parsed_qty' => 3, 'parsed_unit' => 'шт.',
            'parsed_length' => 73, 'parsed_length_unit' => 'м',
            'billing_unit' => 'м',
        ]);

        $this->assertSame(219.0, $item->effectiveQty());
        $this->assertSame('м', $item->effectiveUnit());
    }

    public function test_a_measured_item_awaiting_a_decision_is_recognisable(): void
    {
        $waiting = $this->item([
            'parsed_qty' => 3, 'parsed_length' => 73, 'parsed_length_unit' => 'м',
        ]);
        $decided = $this->item([
            'parsed_qty' => 3, 'parsed_length' => 73, 'parsed_length_unit' => 'м', 'billing_unit' => 'м',
        ]);

        $this->assertTrue($waiting->isMeasured() && ! $waiting->billing_unit, 'по такой позиции авто-КП не даём');
        $this->assertTrue($decided->isMeasured() && (bool) $decided->billing_unit);
    }
}
