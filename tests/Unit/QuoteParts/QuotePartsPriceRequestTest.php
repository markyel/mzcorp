<?php

namespace Tests\Unit\QuoteParts;

use App\Models\QuotePartsRfq;
use App\Models\User;
use App\Services\QuoteParts\QuotePartsPriceRequestService;
use App\Services\QuoteParts\QuotePartsRfqService;
use App\Services\QuoteParts\QuotePartsRouting;
use App\Services\Supplier\SupplierProcurementDispatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * «Запросить цены» раздела «QuoteParts» (11.11.2026): системным — запрос платформы, внесистемным —
 * письмо с orders@; количество не обязательно. БД — SQLite в памяти.
 */
class QuotePartsPriceRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.qp_price_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'qp_price_test',
        ]);
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_organization_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('language')->nullable();
            $t->timestamps();
        });
        Schema::create('catalog_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku');
            $t->string('name')->nullable();
            $t->string('name_en')->nullable();
            $t->string('brand')->nullable();
            $t->string('brand_article')->nullable();
            $t->text('articles')->nullable();
            $t->timestamps();
        });
        DB::table('suppliers')->insert([['id' => 1, 'name' => 'YuanQi', 'email' => 'a@yq.cn'], ['id' => 2, 'name' => 'OSS', 'email' => 'b@oss.cn']]);
        DB::table('catalog_items')->insert([['id' => 10, 'sku' => 'M06374', 'name' => 'Вкладыш'], ['id' => 11, 'sku' => 'M26773', 'name' => 'Вал']]);
    }

    private function service(array $route, QuotePartsRfqService $rfqs, ?SupplierProcurementDispatchService $letters = null): QuotePartsPriceRequestService
    {
        $routing = Mockery::mock(QuotePartsRouting::class);
        $routing->shouldReceive('split')->andReturn($route);

        return new QuotePartsPriceRequestService($rfqs, $routing, $letters ?? Mockery::mock(SupplierProcurementDispatchService::class));
    }

    private static function rfq(string $tag): QuotePartsRfq
    {
        $r = new QuotePartsRfq();
        $r->tag = $tag;

        return $r;
    }

    public function test_system_suppliers_get_the_platform_request_and_off_system_ones_our_letter_without_quantity(): void
    {
        $rfqs = Mockery::mock(QuotePartsRfqService::class);
        $rfqs->shouldReceive('send')->once()
            ->withArgs(fn ($req, $q, $ids, $by, $note, $user, $drafts, $routed, $files, $notes, $letters) => $q === [10 => null, 11 => 3.0] && $ids === [1] && $letters === [])
            ->andReturn(self::rfq('QP-AAA'));
        $rfqs->shouldReceive('send')->once()
            ->withArgs(fn ($req, $q, $ids, $by, $note, $user, $drafts, $routed, $files, $notes, $letters) => $ids === [2] && array_keys($letters) === [2])
            ->andReturn(self::rfq('QP-BBB'));
        $letters = Mockery::mock(SupplierProcurementDispatchService::class);
        $letters->shouldReceive('composeLetter')->once()
            ->withArgs(fn ($items, $s, $note, $edits) => $s->id === 2 && $edits['qty'] === [10 => '', 11 => '3 шт.'] && $edits['qty_en'][11] === '3 pcs')
            ->andReturn(['lang' => 'ru', 'subject' => 'Запрос расценки', 'html' => '<p>x</p>', 'plain' => 'x']);

        $out = $this->service(['quoteparts' => [1], 'offsystem' => [2], 'mail' => []], $rfqs, $letters)
            ->send([10 => null, 11 => 3.0], [1, 2], null, null, new User());

        $this->assertSame(['QP-AAA', 'QP-BBB'], $out['tags']);
        $this->assertSame([], $out['errors']);
        $this->assertSame(2, $out['sent']);
    }

    public function test_an_article_other_than_the_cards_goes_to_system_suppliers_as_a_note(): void
    {
        DB::table('catalog_items')->where('id', 10)->update(['brand_article' => 'XAA380N2 (11 мм)']);
        $catalog = \App\Models\CatalogItem::query()->whereIn('id', [10, 11])->get();

        // Карточка как есть, без пояснения в скобках, или не трогали — примечания нет.
        $this->assertSame([], QuotePartsPriceRequestService::articleNotes($catalog, [10 => 'XAA380N2 (11 мм)']));
        $this->assertSame([], QuotePartsPriceRequestService::articleNotes($catalog, [10 => 'xaa380n2']));
        $this->assertSame([], QuotePartsPriceRequestService::articleNotes($catalog, [10 => ' ']));
        $this->assertSame(['c:10' => 'Part No.: XAA380N2, XAA380AA1'], QuotePartsPriceRequestService::articleNotes($catalog, [10 => 'XAA380N2, XAA380AA1']));
        $this->assertSame(['c:11' => 'Part No.: KM51173460R02'], QuotePartsPriceRequestService::articleNotes($catalog, [11 => 'KM51173460R02']));
    }

    public function test_articles_and_files_reach_both_routes(): void
    {
        $files = [['path' => 'mail/procurement-staging/9/a_foto.jpg', 'name' => 'foto.jpg', 'mime' => 'image/jpeg', 'size' => 10]];
        $rfqs = Mockery::mock(QuotePartsRfqService::class);
        $rfqs->shouldReceive('send')->once()
            ->withArgs(fn ($req, $q, $ids, $by, $note, $user, $drafts, $routed, $f, $notes) => $ids === [1] && $f === $files && $notes === ['c:11' => 'Part No.: KM51173460R02'])
            ->andReturn(self::rfq('QP-DDD'));
        $rfqs->shouldReceive('send')->once()
            ->withArgs(fn ($req, $q, $ids, $by, $note, $user, $drafts, $routed, $f) => $ids === [2] && $f === $files)
            ->andReturn(self::rfq('QP-EEE'));
        $letters = Mockery::mock(SupplierProcurementDispatchService::class);
        $letters->shouldReceive('composeLetter')->once()
            ->withArgs(fn ($items, $s, $note, $edits) => $edits['oem'] === [11 => 'KM51173460R02'])
            ->andReturn(['lang' => 'en', 'subject' => 's', 'html' => 'h', 'plain' => 'p']);

        $out = $this->service(['quoteparts' => [1], 'offsystem' => [2], 'mail' => []], $rfqs, $letters)
            ->send([11 => null], [1, 2], null, 'Please quote', new User(), [11 => 'KM51173460R02'], $files);

        $this->assertSame(['QP-DDD', 'QP-EEE'], $out['tags']);
    }

    public function test_nothing_goes_from_a_managers_mailbox_when_routing_is_off(): void
    {
        $rfqs = Mockery::mock(QuotePartsRfqService::class);
        $rfqs->shouldNotReceive('send');

        $out = $this->service(['quoteparts' => [], 'offsystem' => [], 'mail' => [1, 2]], $rfqs)->send([10 => null], [1, 2], null, null, new User());

        $this->assertSame([], $out['tags']);
        $this->assertStringContainsString('выключена', $out['errors'][0]);
    }

    public function test_a_refusal_for_one_route_still_sends_the_other(): void
    {
        $rfqs = Mockery::mock(QuotePartsRfqService::class);
        $rfqs->shouldReceive('send')->once()->andThrow(new \RuntimeException('Позиции не в работе в QuoteParts: M06374.'));
        $rfqs->shouldReceive('send')->once()->andReturn(self::rfq('QP-CCC'));
        $letters = Mockery::mock(SupplierProcurementDispatchService::class);
        $letters->shouldReceive('composeLetter')->andReturn(['lang' => 'ru', 'subject' => 's', 'html' => 'h', 'plain' => 'p']);

        $out = $this->service(['quoteparts' => [1], 'offsystem' => [2], 'mail' => []], $rfqs, $letters)->send([10 => null], [1, 2], null, null, new User());

        $this->assertSame(['QP-CCC'], $out['tags']);
        $this->assertStringContainsString('Системным поставщикам не отправлено', $out['errors'][0]);
    }
}
