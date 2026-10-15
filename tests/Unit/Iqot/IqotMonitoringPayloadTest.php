<?php

namespace Tests\Unit\Iqot;

use App\Models\CatalogItem;
use App\Services\Iqot\IqotApiService;
use App\Services\Iqot\IqotMonitoringService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Мониторинг цен IQOT: что уходит по позиции (без базы).
 */
class IqotMonitoringPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Настройки — только значения по умолчанию (без обращения к базе).
        $this->mock(SettingsService::class, fn ($m) => $m->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $key === 'iqot.root_category' ? 'Лифты' : $default));
    }

    private function item(array $attrs): CatalogItem
    {
        $c = new CatalogItem(array_merge(['sku' => 'M17913', 'name' => 'Выключатель тормоза OTIS 506NCE', 'brand' => 'OTIS',
            'brand_article' => 'XO-508', 'articles' => ['XO-508', 'M17913'], 'part_type' => 'Выключатель, концевой выключатель'], $attrs));
        $c->id = 17913;

        return $c;
    }

    public function test_the_oem_goes_as_the_article_and_our_code_only_as_the_key(): void
    {
        $item = app(IqotMonitoringService::class)->buildItem($this->item([]));

        $this->assertSame([
            'client_ref' => 'M17913',
            'name' => 'Выключатель тормоза OTIS 506NCE',
            'article' => 'XO-508',
            'unit' => 'шт',
            'brand' => 'OTIS',
            'client_category' => ['code' => 'Выключатель, концевой выключатель', 'path' => ['Лифты', 'Выключатель, концевой выключатель']],
        ], $item);
    }

    public function test_our_replacement_mark_is_not_sent_and_an_m_code_is_not_an_oem(): void
    {
        $item = app(IqotMonitoringService::class)->buildItem($this->item(['name' => '(ЗАМЕНЕНО на M03809) Плата управления', 'brand_article' => 'M17913', 'articles' => ['M17913', '901160G01']]));
        $this->assertSame('Плата управления', $item['name']);
        $this->assertSame('901160G01', $item['article']);

        $this->assertNull($this->item(['brand_article' => null, 'articles' => [null, 'M17913']])->oemForExternal());
    }

    public function test_only_confirmed_prices_one_per_supplier(): void
    {
        $src = fn (string $domain, ?float $price, array $extra = []) => array_merge([
            'type' => 'site', 'domain' => $domain, 'url' => 'https://'.$domain.'/p/'.(int) $price, 'price' => $price, 'currency' => 'RUB',
            'price_kind' => $price === null ? 'on_request' : 'retail', 'status' => 'active', 'flags' => ['web_price' => $price !== null], 'kp' => null,
        ], $extra);
        $clean = IqotMonitoringService::cleanSources([
            $src('eurasialift.ru', 200, ['status' => 'suspicious']),         // скачок цены — перепроверяется
            $src('sematic.su', 4785),
            $src('eurasialift.ru', 4785),
            $src('dvc.ru', 6000),
            $src('dvc.ru', 6000),
            $src('sematic.su', 10000, ['flags' => ['email_confirmed' => true]]),
            $src('eurasialift.ru', 10000),
            $src('lift-house.ru', null),                                    // ни цены, ни КП
            $src('lzpro.ru', null, ['kp' => ['price' => 2950, 'currency' => 'RUB']]),   // «по запросу», цена из КП
            $src('opt.ru', 3000, ['price_kind' => 'wholesale_tier']),
        ]);

        $this->assertSame(['lzpro.ru', 'sematic.su', 'eurasialift.ru', 'dvc.ru'], array_column($clean, 'domain'));
        $this->assertSame([2950.0, 4785.0, 4785.0, 6000.0], array_column($clean, 'value'));
        $this->assertSame(2, $clean[1]['pages']);
        $this->assertTrue($clean[1]['flags']['email_confirmed']);   // подтверждение с другой страницы того же поставщика
        $this->assertSame(2, $clean[2]['pages']);   // страница «на перепроверке» не считается
    }

    public function test_monitoring_api_calls(): void
    {
        Http::fake([
            'iqot.test/api/v1/monitoring/programs' => Http::response(['program_id' => 'mon_1', 'status' => 'draft', 'price_mode' => 'hybrid'], 201),
            'iqot.test/api/v1/monitoring/programs/mon_1/items' => Http::response(['import_id' => 'imp_1', 'status' => 'queued'], 202),
            'iqot.test/api/v1/monitoring/programs/mon_1/prices*' => Http::response(['items' => [], 'next_cursor' => null, 'server_time' => '2026-10-15T10:00:00+00:00']),
        ]);
        $api = new IqotApiService('iqot_live_test', 'https://iqot.test/api/v1');

        $this->assertSame('mon_1', $api->createMonitoringProgram(['name' => 'x', 'price_mode' => 'hybrid'])['program_id']);
        $this->assertSame('imp_1', $api->upsertMonitoringItems('mon_1', [['client_ref' => 'M17913', 'name' => 'Выключатель', 'article' => 'XO-508']], 'mzcorp-mon-abc')['import_id']);
        $this->assertSame('2026-10-15T10:00:00+00:00', $api->monitoringPrices('mon_1', '2026-10-14T10:00:00+00:00')['server_time']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://iqot.test/api/v1/monitoring/programs/mon_1/items'
            && $r->header('Idempotency-Key') === ['mzcorp-mon-abc'] && $r['items'][0]['article'] === 'XO-508'
            && $r->header('Authorization') === ['Bearer iqot_live_test']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/prices?updated_since=2026-10-14T10%3A00%3A00%2B00%3A00'));
    }
}
