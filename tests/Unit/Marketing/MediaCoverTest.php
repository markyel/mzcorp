<?php

namespace Tests\Unit\Marketing;

use App\Models\MediaTopic;
use App\Services\Marketing\MediaCoverService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Анонсовая обложка ассортиментного поста: детали из текста → обезличенные
 * описания → картинка → заголовок поверх. OpenAI — Http::fake, каталог —
 * SQLite в памяти, диск — Storage::fake.
 */
class MediaCoverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.cover_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'cover_test',
            'services.openai.base_url' => 'https://ai.test',
            'services.openai.api_key' => 'sk-test',
            'services.openai.proxy_key' => 'pk-test',
        ]);
        Schema::create('catalog_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku');
            $t->string('name');
            $t->string('part_type')->nullable();
        });
        DB::table('catalog_items')->insert([
            ['sku' => 'M34596', 'name' => 'Плата MCTC-MCB-C3', 'part_type' => 'Плата'],
            ['sku' => 'M34584', 'name' => 'IGBT модуль CD100PIX120C6SNA', 'part_type' => null],
        ]);
        Storage::fake('public');
    }

    private static function jpeg(int $w = 300, int $h = 200): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 200, 200));
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    public function test_cover_is_drawn_from_items_of_the_text_and_stored(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['parts' => ['green control circuit board', 'black power module']])]]]]),
            'https://ai.test/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode(self::jpeg())]]]),
        ]);

        $res = app(MediaCoverService::class)->make('📢 Новые позиции каталога', "• Плата · арт. M34596\n• IGBT · арт. M34584", 'catalog_new');

        $this->assertTrue($res['ok'], $res['message']);
        $name = basename((string) $res['url']);
        $this->assertTrue(MediaCoverService::validName($name));
        Storage::disk('public')->assertExists('media-covers/'.$name);
        $this->assertSame([300, 200], array_slice(getimagesizefromstring(Storage::disk('public')->get('media-covers/'.$name)), 0, 2));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/chat/completions')
            && str_contains($r['messages'][1]['content'], 'Плата: Плата MCTC-MCB-C3'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/images/generations')
            && $r->hasHeader('X-Proxy-Key', 'pk-test')
            && str_contains($r['prompt'], 'green control circuit board; black power module')
            && str_contains($r['prompt'], 'Strictly no text'));
    }

    public function test_text_without_skus_gives_no_cover_and_no_calls(): void
    {
        Http::fake();

        $res = app(MediaCoverService::class)->make('Обзор', 'Текст без артикулов', 'catalog_new');

        $this->assertFalse($res['ok']);
        Http::assertNothingSent();
    }

    public function test_image_api_error_is_reported(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => '{"parts":["board"]}']]]]),
            'https://ai.test/v1/images/generations' => Http::response(['error' => ['message' => 'Your request was rejected by the safety system']], 400),
        ]);

        $res = app(MediaCoverService::class)->make('Новинки', 'арт. M34596', 'catalog_new');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('safety system', $res['message']);
    }

    public function test_applies_only_to_catalog_topics(): void
    {
        $this->assertTrue(MediaCoverService::appliesTo(new MediaTopic(['source' => 'catalog_new'])));
        $this->assertTrue(MediaCoverService::appliesTo(new MediaTopic(['source' => 'stock_arrivals'])));
        $this->assertFalse(MediaCoverService::appliesTo(new MediaTopic(['source' => 'request_tips'])));
        config(['services.openai.media_cover.enabled' => false]);
        $this->assertFalse(MediaCoverService::appliesTo(new MediaTopic(['source' => 'catalog_new'])));
    }
}
