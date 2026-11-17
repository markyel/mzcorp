<?php

namespace Tests\Unit\QuoteParts;

use App\Livewire\QuoteParts\PriceOffers;
use App\Models\QuotePartsOffer;
use App\Services\Procurement\PriceExportService;
use Tests\TestCase;

/**
 * Разбор «Актуализации цен» 17.11.2026: цена за кусок (канат Драко M24638), базис EXW без доплат (OTI),
 * цены с прошедшим сроком в выгрузке (АТ-Электро, YuanQi).
 */
class PriceRefreshTermsTest extends TestCase
{
    public function test_a_price_for_a_piece_says_what_it_covers_and_the_unit_price(): void
    {
        $o = new QuotePartsOffer(['kind' => 'original', 'price' => 32355, 'currency' => 'RUB', 'price_for_qty' => 135, 'price_for_unit' => 'm',
            'tiers' => [['min_qty' => 1, 'price' => 32355]], 'note' => 'ожидается на складе к 19.11.2026']);

        $this->assertSame('цена за 135 m (≈ 239,67 RUB за m)', $o->priceForNote());
        $this->assertSame('цена за 135 m (≈ 239,67 RUB за m); ожидается на складе к 19.11.2026', PriceExportService::offerComment($o));
        $this->assertNull((new QuotePartsOffer(['price' => 5, 'price_for_qty' => 1]))->priceForNote());
    }

    public function test_delivery_terms_go_to_the_comment(): void
    {
        $o = new QuotePartsOffer(['kind' => 'original', 'price' => 1066.2, 'currency' => 'USD', 'incoterm' => 'EXW', 'incoterm_place' => 'Europe',
            'tiers' => [['min_qty' => 1, 'price' => 1066.2]], 'note' => '+ доставка и пошлина Европа→Китай ≈ 300 USD']);

        $this->assertSame('условия EXW Europe; + доставка и пошлина Европа→Китай ≈ 300 USD', PriceExportService::offerComment($o));
    }

    public function test_a_price_far_from_the_last_purchase_asks_to_check_the_unit(): void
    {
        $this->assertSame('в 113 раз дороже последней закупки', PriceOffers::unitCheck(32355, 286, null));
        $this->assertNull(PriceOffers::unitCheck(32355, 286, 135), 'за метр — 240 ₽, как закупка');
        $this->assertSame('в 9 раз дешевле последней закупки', PriceOffers::unitCheck(2888, 25963, null));
        $this->assertNull(PriceOffers::unitCheck(500, 300, null));
        $this->assertNull(PriceOffers::unitCheck(500, null, null));
    }

    public function test_a_price_past_its_validity_is_not_usable(): void
    {
        $base = ['superseded' => false, 'status' => 'active'];

        $this->assertFalse((new QuotePartsOffer($base + ['valid_until' => now()->subDay()->toDateString()]))->isUsable());
        $this->assertTrue((new QuotePartsOffer($base + ['valid_until' => now()->toDateString()]))->isUsable());
        $this->assertTrue((new QuotePartsOffer($base))->isUsable());
    }
}
