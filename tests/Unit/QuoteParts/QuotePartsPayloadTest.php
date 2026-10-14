<?php

namespace Tests\Unit\QuoteParts;

use App\Models\CatalogItem;
use App\Models\CatalogSupplierPrice;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsPoolService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuotePartsPayloadTest extends TestCase
{
    private function service(): QuotePartsPoolService
    {
        return new QuotePartsPoolService(new QuotePartsClient);
    }

    private function item(array $overrides = []): CatalogItem
    {
        $item = new CatalogItem(array_merge([
            'sku' => 'M04990',
            'name' => ' Ролик двери кабины ',
            'name_en' => '',
            'brand' => 'Otis',
            'brand_article' => 'ABA-123',
            'brands' => ['Otis', 'Kone', ''],
            'articles' => ['ABA-123', 'K-9', 'M04990'],
            'part_type' => 'Ролики',
            'unit_name' => 'Двери кабины',
            'size_a' => 40,
            'size_b' => 0,
            'weight' => 0.35,
            'price' => 1500,
            'purchase_price' => 800,
            'stock_available' => 12,
            'photo_url' => 'https://mylift.ru/photo.php?id=abc',
        ], $overrides));
        $item->id = 4990;

        return $item;
    }

    public function test_only_the_supplier_facing_part_of_the_card_is_sent(): void
    {
        $payload = $this->service()->payloadFor($this->item(), collect());

        $this->assertSame('M04990', $payload['code']);
        $this->assertSame('4990', $payload['external_id']);
        $this->assertSame('Ролик двери кабины', $payload['name']);
        $this->assertNull($payload['name_en']);
        $this->assertSame('ABA-123', $payload['mpn']);
        $this->assertSame('Ролики', $payload['category']);
        $this->assertSame(['Размер A, мм' => 40.0], $payload['specs']);
        $this->assertSame(0.35, $payload['weight_kg']);

        // Selling price, stock, cost and the lift node never leave mzcorp.
        foreach (['price', 'purchase_price', 'stock_available', 'unit', 'unit_name'] as $secret) {
            $this->assertArrayNotHasKey($secret, $payload);
        }
    }

    public function test_internal_m_code_is_not_sent_as_a_part_number(): void
    {
        $payload = $this->service()->payloadFor($this->item(['brand_article' => 'M04990']), collect());

        $this->assertNull($payload['mpn']);
        $this->assertSame([
            ['brand' => 'Otis', 'number' => 'ABA-123'],
            ['brand' => 'Kone', 'number' => 'K-9'],
        ], $payload['part_numbers']);
    }

    public function test_first_and_last_purchase_from_1c_go_along(): void
    {
        $last = new CatalogSupplierPrice([
            'kind' => 'last', 'supplier_name_1c' => 'ООО Лифт', 'price' => '812.5000', 'currency' => 'RUB',
            'priced_at' => Carbon::parse('2026-08-01'),
        ]);

        $payload = $this->service()->payloadFor($this->item(), collect([$last]));

        $this->assertSame([[
            'kind' => 'last',
            'supplier_name' => 'ООО Лифт',
            'price' => 812.5,
            'currency' => 'RUB',
            'purchased_at' => '2026-08-01',
        ]], $payload['purchase_prices']);
    }
}
