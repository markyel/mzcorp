<?php

namespace Tests\Unit\Iqot;

use App\Models\CatalogItem;
use App\Models\IqotMonitoringItem;
use App\Models\IqotPosition;
use App\Services\Iqot\IqotMarket;
use App\Services\Iqot\IqotMonitoringService;
use App\Services\Settings\SettingsService;
use Tests\TestCase;

/**
 * Цены рынка: КП по заявкам IQOT и мониторинг одним списком, без НДС (без базы).
 */
class IqotMarketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(SettingsService::class, fn ($m) => $m->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default));
    }

    public function test_quotes_by_request_and_monitoring_in_one_list_without_vat(): void
    {
        $cat = new CatalogItem(['sku' => 'M05531', 'name' => 'Кнопка приказа KLEEMANN', 'price' => 7599.24, 'is_price_actual' => false]);
        $cat->id = 24357;

        $pos = new IqotPosition(['status' => 'completed', 'report' => ['all_offers' => [
            ['supplier' => ['name' => 'ООО Лифткомплект', 'email' => 'sale@lk.ru'], 'price_per_unit' => 5000, 'currency' => 'RUB', 'price_includes_vat' => false, 'delivery_days' => 5],
            ['supplier' => ['name' => 'ИП Запчасть'], 'price_per_unit' => 7320, 'currency' => 'RUB', 'price_includes_vat' => true],
        ]]]);
        $pos->setRelation('catalogItem', $cat);

        $mon = new IqotMonitoringItem(['active' => true, 'sources' => IqotMonitoringService::cleanSources([
            ['domain' => 'sematic.su', 'url' => 'https://sematic.su/p', 'price' => 6100, 'currency' => 'RUB', 'price_kind' => 'retail', 'status' => 'active', 'vat' => true, 'flags' => [], 'kp' => null],
        ], 22)]);

        $m = IqotMarket::for($cat, $pos, $mon);

        // 5000 без НДС (КП) < 5000 (сайт 6100 с НДС) < 6000 (КП 7320 с НДС) < наша 6228,89 (7599,24 с НДС)
        $this->assertSame(['ООО Лифткомплект', 'sematic.su', 'ИП Запчасть'], array_column($m['rows'], 'supplier'));
        $this->assertSame([IqotMarket::KIND_REQUEST, IqotMarket::KIND_SITE, IqotMarket::KIND_REQUEST], array_column($m['rows'], 'kind'));
        $this->assertSame([5000.0, 5000.0, 6000.0], array_column($m['rows'], 'net'));
        $this->assertSame(3, $m['offers']);
        $this->assertSame(2, $m['from_requests']);
        $this->assertSame(1, $m['from_monitoring']);
        $this->assertSame(5000.0, $m['best_net']);
        $this->assertSame(4, $m['our_rank']);
        $this->assertSame(4, $m['total']);
        $this->assertEqualsWithDelta(6228.89, $m['our_net'], 0.01);
        $this->assertSame('sale@lk.ru', trim($m['rows'][0]['contacts'], ' ·'));
        // Ссылки: КП по заявке — сайт по домену почты; сайт — страница товара.
        $this->assertSame(['https://lk.ru', 'https://sematic.su/p', null], array_column($m['rows'], 'url'));
    }

    public function test_supplier_site_and_readable_domains(): void
    {
        $this->assertSame('https://liftlider.ru', IqotMarket::siteFromEmail('info@liftlider.ru'));
        $this->assertNull(IqotMarket::siteFromEmail('2103606@mail.ru'));
        $this->assertNull(IqotMarket::siteFromEmail(null));
        $this->assertSame('лифт-запчасти.рф', IqotMarket::readableDomain('xn----8sba0adep0btegq8b.xn--p1ai'));
        $this->assertSame('sematic.su', IqotMarket::readableDomain('sematic.su'));
    }

    public function test_monitoring_only_uses_the_catalog_price(): void
    {
        $cat = new CatalogItem(['sku' => 'M00217', 'price' => 1220]);
        $cat->id = 217;
        $mon = new IqotMonitoringItem(['active' => true, 'sources' => IqotMonitoringService::cleanSources([
            ['domain' => 'a.ru', 'url' => null, 'price' => 900, 'currency' => 'RUB', 'price_kind' => 'retail', 'status' => 'active', 'vat' => false, 'flags' => [], 'kp' => null],
        ], 22)]);

        $m = IqotMarket::for($cat, null, $mon);
        $this->assertSame(1000.0, $m['our_net']);
        $this->assertSame(2, $m['our_rank']);
        $this->assertSame('Цена каталога', $m['our_label']);
        $this->assertSame(100.0, $m['delta']);

        // Снятая с мониторинга позиция цен не даёт.
        $mon->active = false;
        $this->assertSame(0, IqotMarket::for($cat, null, $mon)['offers']);
    }

    public function test_doubtful_site_is_shown_but_not_counted_and_superseded_site_shows_the_kp_price(): void
    {
        $cat = new CatalogItem(['sku' => 'M00217', 'price' => 1220]);
        $cat->id = 217;
        $src = fn (string $domain, float $price, array $flags, ?array $kp = null) => ['domain' => $domain, 'url' => null, 'price' => $price, 'currency' => 'RUB',
            'price_kind' => 'retail', 'status' => 'active', 'vat' => false, 'flags' => $flags, 'kp' => $kp];
        $mon = new IqotMonitoringItem(['active' => true, 'sources' => IqotMonitoringService::cleanSources([
            $src('cheap.ru', 300, ['doubtful' => true]),
            $src('kp.ru', 700, ['web_superseded' => true, 'kp' => true], ['price' => 1100, 'currency' => 'RUB', 'vat_included' => false]),
            $src('b.ru', 1200, []),
        ], 22)]);

        $m = IqotMarket::for($cat, null, $mon);
        $this->assertSame(['cheap.ru', 'kp.ru', 'b.ru'], array_column($m['rows'], 'supplier'));
        $this->assertSame([true, false, false], array_column($m['rows'], 'doubtful'));
        $this->assertSame([IqotMarket::KIND_SITE, IqotMarket::KIND_MAIL_KP, IqotMarket::KIND_SITE], array_column($m['rows'], 'kind'));
        $this->assertSame([null, 700.0, null], array_column($m['rows'], 'web_old'));
        $this->assertSame(2, $m['offers'], 'the doubtful 300 is not a price');
        $this->assertSame(1100.0, $m['best_net'], 'the site 700 was disproved by the KP 1100');
        $this->assertSame(1, $m['our_rank']);
        $this->assertSame(3, $m['total']);
        $this->assertSame(['our_net' => 1000.0, 'rank' => 1, 'total' => 3, 'delta' => -100.0, 'delta_pct' => -9.1], $mon->comparison(1220));
    }
}
