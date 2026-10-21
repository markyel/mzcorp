<?php

namespace Tests\Unit\QuoteParts;

use App\Livewire\QuoteParts\PriceOffers;
use App\Models\CatalogItem;
use App\Models\QuotePartsOffer;
use Tests\TestCase;

/** «Актуализация цен»: строка предложения рядом с ценами каталога. */
class PriceOffersRowTest extends TestCase
{
    private static function row(QuotePartsOffer $o): array
    {
        $m = new \ReflectionMethod(PriceOffers::class, 'row');

        return $m->invoke(null, $o);
    }

    public function test_offer_against_catalog_purchase_price(): void
    {
        $o = new QuotePartsOffer(['sku' => 'M17800', 'price' => 1200, 'currency' => 'RUB', 'status' => 'active', 'superseded' => false, 'request_id' => 20019, 'rfq_tag' => '[QP-YFHA3K]']);
        $o->setRelation('catalogItem', new CatalogItem(['sku' => 'M17800', 'name' => 'Плата', 'purchase_price' => 1000, 'price' => 2500, 'is_price_actual' => false]));
        $o->setRelation('request', null);

        $r = self::row($o);

        $this->assertSame(1200.0, $r['rub']);
        $this->assertSame(1000.0, $r['purchase']);
        $this->assertArrayNotHasKey('delta_pct', $r, 'с закупкой (логистика, таможня) не сравниваем');
        $this->assertSame(20019, $r['request_id']);
        $this->assertFalse($r['actual']);
        $this->assertFalse($r['stale']);
    }

    public function test_superseded_offer_is_stale(): void
    {
        $o = new QuotePartsOffer(['sku' => 'M1', 'price' => 10, 'currency' => 'RUB', 'status' => 'active', 'superseded' => true]);
        $o->setRelation('catalogItem', null);
        $o->setRelation('request', null);

        $r = self::row($o);

        $this->assertTrue($r['stale']);
        $this->assertNull($r['purchase']);
        $this->assertSame('M1', $r['sku']);
    }

    public function test_new_product_without_catalog_card_is_named_from_the_request(): void
    {
        $o = new QuotePartsOffer(['sku' => 'T-VZQ4BU', 'price' => 21, 'currency' => 'USD', 'status' => 'active', 'superseded' => false, 'product_name' => 'Door contact']);
        $o->setRelation('catalogItem', null);
        $o->setRelation('request', null);
        $m = new \ReflectionMethod(PriceOffers::class, 'row');

        $this->assertSame('Контакт двери AT120', $m->invoke(null, $o, 'Контакт двери AT120')['name']);
        $this->assertSame('Door contact', $m->invoke(null, $o, null)['name'], 'no request name — as the supplier named it');
    }
}
