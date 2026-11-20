<?php

namespace Tests\Unit\QuoteParts;

use App\Models\QuotePartsOffer;
use App\Services\Procurement\PriceExportService;
use PHPUnit\Framework\TestCase;

/** Цена для 1С вручную (UniSystem M16131, 20.11.2026: разбор взял 901,47 EUR — итог с доставкой и НДС 19%). */
class ManualPriceTest extends TestCase
{
    public function test_the_manual_price_goes_to_1c_and_the_supplier_figure_to_the_comment(): void
    {
        $o = new QuotePartsOffer;
        $o->forceFill(['price' => 901.47, 'currency' => 'EUR', 'unit_from_qty' => 1, 'unit_to_qty' => 10]);
        $this->assertSame(9014.7, $o->exportPrice(), 'без ручной — цена поставщика с пересчётом единицы');

        $o->manual_price = 746.64;
        $this->assertSame(746.64, $o->exportPrice());
        $this->assertSame('цена поставщика 901,47 EUR, в 1С — 746,64 (указана вручную)', $o->manualPriceNote());
        $this->assertStringStartsWith('цена поставщика 901,47 EUR, в 1С — 746,64', (string) PriceExportService::offerComment($o));
    }
}
