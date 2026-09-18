<?php

namespace Tests\Unit\Services\Catalog;

use App\Services\Catalog\YandexDirectFeedService;
use Tests\TestCase;

/**
 * Сборка оффера для товарной кампании: ссылка на карточку с метками, имя
 * оффера и коды производителя. Без БД — проверяем чистые преобразования.
 */
class YandexDirectFeedServiceTest extends TestCase
{
    public function test_product_url_carries_sku_and_utm(): void
    {
        $url = YandexDirectFeedService::productUrl('M14224');

        $this->assertStringContainsString('code=M14224', $url);
        $this->assertStringContainsString('utm_source=yandex', $url);
        $this->assertStringContainsString('utm_medium=cpc', $url);
        $this->assertStringContainsString('utm_content=M14224', $url);
        // У шаблона уже есть query — метки должны прицепиться через &, не через ?
        $this->assertSame(1, substr_count($url, '?'));
    }

    public function test_product_url_escapes_the_sku(): void
    {
        $url = YandexDirectFeedService::productUrl('M 14/224');

        $this->assertStringNotContainsString(' ', $url);
        $this->assertStringContainsString('M%2014%2F224', $url);
    }

    public function test_offer_name_falls_back_to_type_brand_article(): void
    {
        $named = (object) ['name' => 'Ролик каретки D=56мм', 'sku' => 'M14224'];
        $this->assertSame('Ролик каретки D=56мм', YandexDirectFeedService::offerName($named));

        $unnamed = (object) ['name' => '', 'part_type' => 'Ролик подвеса ДК/ДШ',
            'brand' => 'Selcom', 'brand_article' => '1023902A01', 'sku' => 'M14224'];
        $this->assertSame('Ролик подвеса ДК/ДШ Selcom 1023902A01', YandexDirectFeedService::offerName($unnamed));

        $empty = (object) ['name' => '', 'sku' => 'M14224'];
        $this->assertSame('M14224', YandexDirectFeedService::offerName($empty));
    }

    public function test_offer_name_is_cut_to_255(): void
    {
        $long = (object) ['name' => str_repeat('я', 400), 'sku' => 'M1'];

        $this->assertSame(255, mb_strlen(YandexDirectFeedService::offerName($long)));
    }

    public function test_codes_merge_articles_and_brand_article_without_duplicates(): void
    {
        $item = (object) [
            'articles' => ['456769', '1023902A01'],
            'brand_article' => '1023902a01', // тот же код в другом регистре
        ];

        $codes = YandexDirectFeedService::codes($item);

        $this->assertSame(['456769', '1023902A01'], $codes);
    }

    public function test_codes_accept_json_string_from_jsonb(): void
    {
        $item = (object) ['articles' => '["ZAA177CAB1","GAA453BM1"]', 'brand_article' => ''];

        $this->assertSame(['ZAA177CAB1', 'GAA453BM1'], YandexDirectFeedService::codes($item));
    }

    public function test_codes_survive_empty_input(): void
    {
        $this->assertSame([], YandexDirectFeedService::codes((object) []));
        $this->assertSame([], YandexDirectFeedService::codes((object) ['articles' => null, 'brand_article' => null]));
    }
}
