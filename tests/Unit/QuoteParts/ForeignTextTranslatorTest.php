<?php

namespace Tests\Unit\QuoteParts;

use App\Services\AI\OpenAIChatService;
use App\Services\QuoteParts\ForeignTextTranslator;
use App\Services\QuoteParts\QuotePartsPriceService;
use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/** Китайские ответы поставщиков QuoteParts — снабжению по-русски (M32654: «确认附件中图片和尺寸», аналог «无 无»). */
class ForeignTextTranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    /** Модель-заглушка: переводит по словарю, считает вызовы. */
    private function fakeModel(array $dictionary, int &$calls): void
    {
        $openai = Mockery::mock(OpenAIChatService::class);
        $openai->shouldReceive('chat')->andReturnUsing(function (array $messages) use ($dictionary, &$calls) {
            $calls++;
            $in = json_decode($messages[1]['content'], true)['texts'];

            return ['content' => json_encode(['texts' => array_map(fn ($t) => $dictionary[$t] ?? $t, $in)], JSON_UNESCAPED_UNICODE)];
        });
        $this->app->instance(OpenAIChatService::class, $openai);
    }

    public function test_only_cjk_needs_translation(): void
    {
        $this->assertTrue(ForeignTextTranslator::needs('确认附件中图片和尺寸'));
        $this->assertTrue(ForeignTextTranslator::needs('aнaлог 无'));
        $this->assertFalse(ForeignTextTranslator::needs('original one,not made in China.'));
        $this->assertFalse(ForeignTextTranslator::needs('срок поставки 3 дн.'));
        $this->assertFalse(ForeignTextTranslator::needs(null));
    }

    public function test_translates_once_and_keeps_failed_text_as_is(): void
    {
        $calls = 0;
        $this->fakeModel(['确认附件中图片和尺寸' => 'Проверьте фото и размеры во вложении', '无' => '', '半成品' => '半成品'], $calls);
        $t = $this->app->make(ForeignTextTranslator::class);

        $ru = $t->toRussian(['确认附件中图片和尺寸', '无', 'English note', '半成品']);
        $this->assertSame(['确认附件中图片和尺寸' => 'Проверьте фото и размеры во вложении', '无' => ''], $ru, 'a reply still in Chinese is not a translation');
        $this->assertSame(1, $calls);

        $t->toRussian(['确认附件中图片和尺寸', '无']);
        $this->assertSame(1, $calls, 'cached');
    }

    public function test_offer_fields_get_the_translation_and_keep_the_original(): void
    {
        $o = ['note' => '确认附件中图片和尺寸', 'product' => ['brand' => '无', 'part_number' => 'AZ05', 'name' => null]];
        [$local, $originals] = QuotePartsPriceService::localized(QuotePartsPriceService::offerTexts($o), ['确认附件中图片和尺寸' => 'Проверьте фото и размеры во вложении', '无' => '']);

        $this->assertSame(['brand' => null, 'part_number' => 'AZ05', 'product_name' => null, 'note' => 'Проверьте фото и размеры во вложении'], $local);
        $this->assertSame(['brand' => '无', 'note' => '确认附件中图片和尺寸'], $originals);
    }

    public function test_supplier_letters_in_chinese_get_text_ru(): void
    {
        $calls = 0;
        $this->fakeModel(['您好，有现货' => 'Здравствуйте, есть в наличии', '无' => '', '进口产品 等国外厂家回复' => 'Импорт, ждём ответа завода'], $calls);
        $snapshot = ['suppliers' => [['external_id' => 'org:1', 'thread' => [
            ['direction' => 'out', 'text' => '您好'],
            ['direction' => 'in', 'text' => '您好，有现货'],
            ['direction' => 'in', 'text' => 'Hello, in stock'],
        ], 'letters' => [['text' => 'MyZip 请您对以下物品报价']], 'lines' => [
            ['code' => 'M32654', 'postpone_note' => '进口产品 等国外厂家回复', 'offers' => [['note' => 'ok', 'product' => ['brand' => '无', 'part_number' => 'AZ05']]]],
        ]]]];

        $out = $this->app->make(QuotePartsRfqService::class)->russified($snapshot);
        $thread = $out['suppliers'][0]['thread'];
        $this->assertArrayNotHasKey('text_ru', $thread[0], 'our own letters are not translated');
        $this->assertSame('Здравствуйте, есть в наличии', $thread[1]['text_ru']);
        $this->assertArrayNotHasKey('text_ru', $thread[2]);
        $this->assertSame('MyZip 请您对以下物品报价', $out['suppliers'][0]['letters'][0]['text'], 'our request letter stays as sent');

        $line = $out['suppliers'][0]['lines'][0];
        $this->assertSame(['Импорт, ждём ответа завода', '进口产品 等国外厂家回复'], [$line['postpone_note'], $line['postpone_note_original']]);
        $this->assertSame(['brand' => null, 'part_number' => 'AZ05', 'brand_original' => '无'], $line['offers'][0]['product']);
        $this->assertSame(1, $calls);
    }
}
