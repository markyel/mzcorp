<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\MediaCoverService;
use App\Services\Marketing\MediaDataService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Анонсовая обложка: ассортиментный пост — сцена из наших деталей,
 * перерисованных по каталожным фото (CatalogCoverSceneService); без годных фото
 * и у прочих постов — сцена по описанию. Заголовок — на полупрозрачной плашке,
 * без градиента. OpenAI и фото каталога — Http::fake, каталог — SQLite в
 * памяти, диск — Storage::fake.
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
            $t->string('photo_url')->nullable();
            $t->boolean('is_active')->default(true);
        });
        DB::table('catalog_items')->insert([
            ['sku' => 'M34596', 'name' => 'Плата MCTC-MCB-C3', 'part_type' => 'Плата', 'photo_url' => 'https://shop.test/photo.php?id=1'],
            ['sku' => 'M34584', 'name' => 'IGBT модуль CD100PIX120C6SNA', 'part_type' => null, 'photo_url' => 'https://shop.test/photo.php?id=2'],
            ['sku' => 'M10001', 'name' => 'Ролик без фото', 'part_type' => null, 'photo_url' => null],
        ]);
        Storage::fake('public');
    }

    /** Однотонный фон и, по желанию, тёмный прямоугольник-«деталь» в верхней части. */
    private static function jpeg(int $w = 300, int $h = 200, bool $object = false): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 200, 200));
        if ($object) {
            imagefilledrectangle($img, (int) ($w * 0.2), (int) ($h * 0.2), (int) ($w * 0.8), (int) ($h * 0.5), imagecolorallocate($img, 30, 30, 30));
        }
        ob_start();
        imagejpeg($img, null, 95);

        return (string) ob_get_clean();
    }

    /** @param  list<int>  $suitable  номера фото, которые модель-отборщик признаёт годными */
    private static function fakeCatalogApi(array $suitable): void
    {
        Http::fake([
            'https://shop.test/*' => Http::response(self::jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://ai.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['suitable' => $suitable])]]]]),
            'https://ai.test/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode(self::jpeg(1536, 1024, true))]]]),
            'https://ai.test/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode(self::jpeg(1536, 1024))]]]),
        ]);
    }

    private static function editsSent(): int
    {
        return collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/v1/images/edits'))->count();
    }

    public function test_catalog_cover_is_a_scene_of_our_redrawn_parts(): void
    {
        self::fakeCatalogApi([1, 2]);

        $res = app(MediaCoverService::class)->make('Новые позиции каталога', "• Плата · арт. M34596\n• IGBT · арт. M34584\n• Ролик · арт. M10001", 'catalog_new');

        $this->assertTrue($res['ok'], $res['message']);
        $name = basename((string) $res['url']);
        $this->assertTrue(MediaCoverService::validName($name));
        Storage::disk('public')->assertExists('media-covers/'.$name);
        $this->assertSame([1536, 1024], array_slice(getimagesizefromstring(Storage::disk('public')->get('media-covers/'.$name)), 0, 2));

        // Фото проверила модель; две детали перерисованы по одной, потом общая сцена.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/chat/completions')
            && str_contains(json_encode($r['messages']), 'clean product shot'));
        $this->assertSame(3, self::editsSent());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/images/edits')
            && $r->hasHeader('X-Proxy-Key', 'pk-test')
            && str_contains($r->body(), 'exactly these 2 elevator spare parts')
            && str_contains($r->body(), 'input_fidelity'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/v1/images/generations'));
        $this->assertCount(2, Storage::disk('public')->files('media-covers/parts'));
    }

    public function test_redrawn_parts_are_reused_by_the_next_cover(): void
    {
        self::fakeCatalogApi([1, 2]);
        $covers = app(MediaCoverService::class);

        $covers->make('Новинки', 'арт. M34596, арт. M34584', 'catalog_new');
        $this->assertSame(3, self::editsSent());

        // Второй раз — только сцена: детали взяты из хранилища.
        $covers->make('Снижение цен', 'арт. M34584 и арт. M34596', 'catalog_price');
        $this->assertSame(4, self::editsSent());
    }

    public function test_catalog_post_without_suitable_photos_gets_a_backdrop_without_parts(): void
    {
        self::fakeCatalogApi([]);

        $res = app(MediaCoverService::class)->make('Поступления', 'арт. M34596, арт. M34584', 'stock_arrivals');

        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame(0, self::editsSent());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/images/generations')
            && str_contains($r['prompt'], 'warehouse')
            && str_contains($r['prompt'], 'Strictly no text'));
    }

    public function test_catalog_post_without_known_skus_gets_a_backdrop(): void
    {
        self::fakeCatalogApi([1]);

        $res = app(MediaCoverService::class)->make('Новинки', 'арт. M99999', 'catalog_new');

        $this->assertTrue($res['ok'], $res['message']);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/v1/chat/completions'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/images/generations')
            && str_contains($r['prompt'], 'atrium')
            && str_contains($r['prompt'], 'No close-ups of machinery'));
    }

    public function test_tip_cover_is_one_redrawn_part_of_the_category(): void
    {
        self::fakeCatalogApi([1]);
        app()->instance(MediaDataService::class, new class extends MediaDataService
        {
            public function tipPhotoUrl(?string $subjectKey): ?string
            {
                return $subjectKey === 'kb:rolik' ? 'https://shop.test/photo.php?id=1' : null;
            }
        });

        $res = app(MediaCoverService::class)->make('Как оформить заявку на ролик', 'Пришлите диаметр и ширину.', 'request_tips', 'kb:rolik');

        $this->assertTrue($res['ok'], $res['message']);
        // Одна деталь перерисована и поставлена в сцену; ничего не выдумано.
        $this->assertSame(2, self::editsSent());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/images/edits')
            && str_contains($r->body(), 'exactly this elevator spare part'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/v1/images/generations'));
        $this->assertCount(1, Storage::disk('public')->files('media-covers/parts'));
        $this->assertStringStartsWith('media-covers/parts/M34596-', Storage::disk('public')->files('media-covers/parts')[0]);
    }

    public function test_tip_without_a_suitable_photo_gets_an_architecture_scene(): void
    {
        Http::fake([
            'https://shop.test/*' => Http::response(self::jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode(['suitable' => []])]]]])
                ->push(['choices' => [['message' => ['content' => json_encode(['scene' => 'A bright lobby with closed elevator doors far away.'])]]]]),
            'https://ai.test/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode(self::jpeg(1536, 1024))]]]),
        ]);
        app()->instance(MediaDataService::class, new class extends MediaDataService
        {
            public function tipPhotoUrl(?string $subjectKey): ?string
            {
                return 'https://shop.test/photo.php?id=1';
            }
        });

        $res = app(MediaCoverService::class)->make('Как оформить заявку на ролик', 'Пришлите диаметр и ширину.', 'request_tips', 'kb:rolik');

        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame(0, self::editsSent());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/chat/completions')
            && str_contains(json_encode($r['messages']), 'Use architecture only'));
    }

    public function test_post_without_skus_gets_a_scene_by_its_meaning(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['scene' => 'A row of modern glass elevators in a bright atrium.'])]]]]),
            'https://ai.test/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode(self::jpeg())]]]),
        ]);

        $res = app(MediaCoverService::class)->make('Лифтовая отрасль за неделю', 'В Белгороде заменили 40 лифтов.', 'industry_digest');

        $this->assertTrue($res['ok'], $res['message']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/chat/completions')
            && str_contains($r['messages'][1]['content'], 'digest of elevator industry news')
            && str_contains($r['messages'][1]['content'], 'В Белгороде заменили 40 лифтов.'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/images/generations')
            && str_contains($r['prompt'], 'Scene: A row of modern glass elevators in a bright atrium.')
            && str_contains($r['prompt'], 'no identifiable faces')
            && str_contains($r['prompt'], 'Strictly no text'));
    }

    public function test_image_api_error_is_reported(): void
    {
        Http::fake([
            'https://ai.test/v1/images/generations' => Http::response(['error' => ['message' => 'Your request was rejected by the safety system']], 400),
        ]);

        $res = app(MediaCoverService::class)->make('Новинки', 'арт. M99999', 'catalog_new');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('safety system', $res['message']);
    }

    public function test_title_sits_on_a_plate_without_a_gradient_above_it(): void
    {
        $img = imagecreatefromstring(app(MediaCoverService::class)->compose(self::jpeg(1536, 1024), 'Снижение цен на ключевые позиции'));

        // Над плашкой картинка не затемнена (раньше градиент начинался с 55% высоты).
        $this->assertEqualsWithDelta(200, (imagecolorat($img, 700, 620) >> 16) & 255, 6);
        // Плашка внизу темнее фона.
        $this->assertLessThan(150, (imagecolorat($img, 1400, 1000) >> 16) & 255);
    }

    public function test_short_preposition_does_not_hang_at_line_end(): void
    {
        $wrap = new \ReflectionMethod(MediaCoverService::class, 'wrap');
        $font = resource_path('fonts/PTSans-Bold.ttf');
        $box = imagettfbbox(40, 0, $font, 'Как оформить заявку на');
        $width = ($box[2] - $box[0]) + 2;

        $lines = $wrap->invoke(app(MediaCoverService::class), 'Как оформить заявку на ролик', $font, 40.0, $width);

        $this->assertSame(['Как оформить заявку', 'на ролик'], $lines);
    }

    public function test_covers_can_be_switched_off(): void
    {
        $this->assertTrue(MediaCoverService::enabled());
        config(['services.openai.media_cover.enabled' => false]);
        $this->assertFalse(MediaCoverService::enabled());
    }
}
