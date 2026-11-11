<?php

namespace Tests\Unit\QuoteParts;

use App\Livewire\Concerns\ComposesSupplierPriceRequest;
use App\Livewire\Procurement\Index as ProcurementIndex;
use App\Livewire\QuoteParts\PriceRequest;
use App\Models\CatalogItem;
use App\Services\QuoteParts\QuotePartsRfqService;
use Tests\TestCase;

/**
 * Запрос цен поставщикам — одна панель для «Снабжения» и раздела «QuoteParts» (11.11.2026);
 * правленый артикул системному поставщику — строкой «Part No.: …» под позицией.
 */
class QuotePartsPriceRequestTest extends TestCase
{
    private static function item(int $id, ?string $brandArticle): CatalogItem
    {
        $ci = new CatalogItem(['brand_article' => $brandArticle]);
        $ci->id = $id;

        return $ci;
    }

    public function test_an_article_other_than_the_cards_goes_to_system_suppliers_as_a_note(): void
    {
        $catalog = collect([self::item(10, 'XAA380N2 (11 мм)'), self::item(11, null)]);

        // Карточка как есть, без пояснения в скобках, или не трогали — примечания нет.
        $this->assertSame([], QuotePartsRfqService::articleNotes($catalog, [10 => 'XAA380N2 (11 мм)']));
        $this->assertSame([], QuotePartsRfqService::articleNotes($catalog, [10 => 'xaa380n2']));
        $this->assertSame([], QuotePartsRfqService::articleNotes($catalog, [10 => ' ']));
        $this->assertSame([], QuotePartsRfqService::articleNotes($catalog, []));
        $this->assertSame(['c:10' => 'Part No.: XAA380N2, XAA380AA1'], QuotePartsRfqService::articleNotes($catalog, [10 => 'XAA380N2, XAA380AA1']));
        $this->assertSame(['c:11' => 'Part No.: KM51173460R02'], QuotePartsRfqService::articleNotes($catalog, [11 => 'KM51173460R02']));
    }

    public function test_both_sections_use_the_same_panel(): void
    {
        $this->assertContains(ComposesSupplierPriceRequest::class, class_uses(ProcurementIndex::class));
        $this->assertContains(ComposesSupplierPriceRequest::class, class_uses(PriceRequest::class));
        $this->assertTrue(PriceRequest::startsWithSku('M00386 Контакт двери', 'M00386'));
    }
}
