<?php

namespace Tests\Unit\QuoteParts;

use App\Services\QuoteParts\QuotePartsRfqService;
use Tests\TestCase;

/**
 * Позиции запроса QuoteParts — в порядке нашего письма и с тем, как их спросили (12.11.2026, MZ2026-00125:
 * OTI ответил на «1. Contactor C&S TC1D25008, 2. LADN22C», а QuoteParts знал позиции в другом порядке и по карточкам).
 */
class QuotePartsAskedOrderTest extends TestCase
{
    public function test_items_go_in_the_letters_order_with_the_wording_as_asked(): void
    {
        $items = [
            'c:23106' => ['code' => 'M01016', 'quantity' => 1.0, 'note' => null],
            'c:6118' => ['code' => 'M07071', 'quantity' => 1.0, 'note' => null],
            'd:900' => ['code' => 'T-AAA', 'quantity' => 2.0, 'note' => null],
        ];
        $asked = [
            'c:6118' => ['n' => 1, 'name' => ' Contactor C&S TC1D25008 25A 230VAC ', 'name_ru' => 'Пускатель C&S TC1D25008', 'part_number' => 'TC1D25008', 'brand' => 'C&S', 'catalog_item_id' => 6118],
            'c:23106' => ['n' => 2, 'name' => 'Auxilary contactor Schneider Electric LADN22C', 'part_number' => 'LADN22С, LADN22', 'brand' => null],
            'c:404' => ['n' => 3, 'name' => 'not in the request'],
        ];

        $out = QuotePartsRfqService::askedOrder($items, $asked);

        $this->assertSame(['c:6118', 'c:23106', 'd:900'], array_keys($out));
        $this->assertSame(['n' => 1, 'name' => 'Contactor C&S TC1D25008 25A 230VAC', 'name_ru' => 'Пускатель C&S TC1D25008', 'part_number' => 'TC1D25008', 'brand' => 'C&S'], $out['c:6118']['asked']);
        $this->assertSame(['n' => 2, 'name' => 'Auxilary contactor Schneider Electric LADN22C', 'part_number' => 'LADN22С, LADN22'], $out['c:23106']['asked']);
        $this->assertArrayNotHasKey('asked', $out['d:900'], 'спрошено как в карточке — без «как спросили»');
        $this->assertSame('M07071', $out['c:6118']['code']);
    }

    public function test_without_asked_the_order_stays(): void
    {
        $items = ['c:1' => ['code' => 'M1'], 'c:2' => ['code' => 'M2']];

        $this->assertSame($items, QuotePartsRfqService::askedOrder($items, []));
    }
}
