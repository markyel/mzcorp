<?php

namespace Tests\Unit\QuoteParts;

use App\Models\QuotePartsOffer;
use App\Services\Procurement\PriceExportService;
use App\Services\Procurement\SupplierChoiceService;
use App\Services\QuoteParts\PriceDraftSkuService;
use Tests\TestCase;

/**
 * 13.11.2026: новой позиции (T-Z7C4FH «Блок лифтовой 7.2 HYUNDAI») снабжение узнаёт M-артикул раньше, чем товар
 * появится в каталоге. Цены выгружаются под этим артикулом, выбор «Закупаем здесь» — по коду T-… (без базы).
 */
class PriceDraftSkuTest extends TestCase
{
    public function test_m_article_is_normalized(): void
    {
        $this->assertSame('M27410', PriceDraftSkuService::normalize(' м27410 '), 'кириллическая «М»');
        $this->assertSame('M27410', PriceDraftSkuService::normalize('m27410'));
        $this->assertNull(PriceDraftSkuService::normalize('M12'));
        $this->assertNull(PriceDraftSkuService::normalize('T-Z7C4FH'));
        $this->assertNull(PriceDraftSkuService::normalize('27410'));
    }

    public function test_item_key_is_the_catalog_card_or_the_draft_code(): void
    {
        $this->assertSame(10, SupplierChoiceService::itemKey(10, 'M00647'));
        $this->assertSame('T-Z7C4FH', SupplierChoiceService::itemKey(null, 'T-Z7C4FH'));
        $this->assertNull(SupplierChoiceService::itemKey(null, 'M00647'), 'артикул без карточки — не новая позиция');
    }

    public function test_a_draft_offer_goes_out_under_the_given_m_article(): void
    {
        $offer = (new QuotePartsOffer)->forceFill([
            'id' => 7, 'catalog_item_id' => null, 'sku' => 'T-Z7C4FH', 'supplier_key' => 'org:5', 'supplier_name' => 'ЛифтСвязьКомплект',
            'price' => 18410, 'currency' => 'RUB', 'kind' => 'original', 'status' => 'active', 'superseded' => false,
            'product_name' => 'Блок лифтовой 7.2 для лифта HYUNDAI', 'export_comment' => 'цена без НДС',
        ]);
        $offer->setRelation('catalogItem', null);
        $drafts = ['T-Z7C4FH' => 'M27410'];

        $price = (fn () => self::quotePartsPrice($offer, $drafts))->call(new PriceExportService);
        $this->assertSame('T-Z7C4FH', $price['cid'], 'ключ выбора — код новой позиции');
        $this->assertSame('M27410', $price['sku'], 'в файл 1С — под M-артикулом');

        $rows = PriceExportService::companionRows(collect([$offer]), collect(['T-Z7C4FH' => (object) ['source' => 'quoteparts', 'offer_ref' => '7']]),
            ['org:5' => 'ЛифтСвязьКомплект ООО'], null, $drafts);
        $this->assertSame([['sku' => 'M27410', 'name' => 'Блок лифтовой 7.2 для лифта HYUNDAI', 'price' => 18410.0, 'currency' => 'RUB',
            'supplier' => 'ЛифтСвязьКомплект ООО', 'comment' => 'цена без НДС']], $rows);

        // Без M-артикула новая позиция в файлы не идёт.
        $this->assertSame([], PriceExportService::companionRows(collect([$offer]),
            collect(['T-Z7C4FH' => (object) ['source' => 'quoteparts', 'offer_ref' => '7']]), [], null, []));
    }
}
