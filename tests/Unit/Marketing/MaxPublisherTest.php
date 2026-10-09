<?php

namespace Tests\Unit\Marketing;

use App\Models\MediaChannel;
use App\Services\Marketing\MediaPublisherService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Канал MAX медиаплана: публикация ботом через Bot API (POST /messages),
 * картинки — загрузкой файла (POST /uploads → адрес → токен). БД не нужна:
 * канал в памяти, площадка — Http::fake.
 */
class MaxPublisherTest extends TestCase
{
    private function channel(): MediaChannel
    {
        $ch = new MediaChannel();
        $ch->kind = 'max';
        $ch->writeSecrets(['bot_token' => 'tok-123', 'chat_id' => '-7001']);

        return $ch;
    }

    private function publishToMax(MediaChannel $ch, string $text, array $images = [], bool $html = false): array
    {
        $m = new ReflectionMethod(MediaPublisherService::class, 'postToMax');

        return $m->invoke(new MediaPublisherService(), $ch, $text, $images, $html);
    }

    public function test_channel_is_postable_and_connected_with_token_and_chat(): void
    {
        $ch = $this->channel();
        $this->assertTrue($ch->isPostable());
        $this->assertTrue($ch->isConnected());
        $this->assertSame('MAX', MediaChannel::KINDS['max']);
    }

    public function test_publishes_with_uploaded_image_and_returns_post_link(): void
    {
        config(['services.max.api_base' => 'https://max.test']);
        Http::fake([
            'https://cdn.test/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']),
            'https://max.test/uploads*' => Http::response(['url' => 'https://upload.max.test/slot1']),
            'https://upload.max.test/*' => Http::response(['photos' => ['555' => ['token' => 'img-tok']]]),
            'https://max.test/messages*' => Http::response(['message' => [
                'body' => ['mid' => 'mid.abc123'],
                'url' => 'https://max.ru/mzcorp/AZabc',
            ]]),
        ]);

        $res = $this->publishToMax($this->channel(), 'Новинки склада', ['https://cdn.test/a.jpg']);

        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame('https://max.ru/mzcorp/AZabc', $res['url']);
        $this->assertSame('mid.abc123', $res['external_id']);
        Http::assertSent(function (Request $r) {
            return str_starts_with($r->url(), 'https://max.test/messages')
                && str_contains($r->url(), 'chat_id=-7001')
                && $r->hasHeader('Authorization', 'tok-123')
                && $r['text'] === 'Новинки склада'
                && $r['attachments'][0]['payload']['token'] === 'img-tok';
        });
    }

    public function test_falls_back_to_text_when_image_post_is_rejected(): void
    {
        config(['services.max.api_base' => 'https://max.test']);
        Http::fake([
            'https://cdn.test/*' => Http::response('JPEGDATA', 200),
            'https://max.test/uploads*' => Http::response(['url' => 'https://upload.max.test/slot1']),
            'https://upload.max.test/*' => Http::response(['token' => 'img-tok']),
            'https://max.test/messages*' => Http::sequence()
                ->push(['code' => 'bad.request', 'message' => 'Invalid attachment'], 400)
                ->push(['message' => ['body' => ['mid' => 'mid.text'], 'url' => null]]),
        ]);

        $res = $this->publishToMax($this->channel(), 'Текст', ['https://cdn.test/a.jpg']);

        $this->assertTrue($res['ok']);
        $this->assertSame('mid.text', $res['external_id']);
        $this->assertNull($res['url']);
    }

    public function test_reports_platform_error(): void
    {
        config(['services.max.api_base' => 'https://max.test']);
        Http::fake(['https://max.test/messages*' => Http::response(['code' => 'chat.denied', 'message' => 'Bot is not admin'], 403)]);

        $res = $this->publishToMax($this->channel(), 'Текст');

        $this->assertFalse($res['ok']);
        $this->assertSame('MAX: Bot is not admin', $res['message']);
    }

    public function test_long_text_is_split_by_lines_within_limit(): void
    {
        $line = str_repeat('а', 30)."\n";
        $parts = MediaPublisherService::splitForMax(str_repeat($line, 10), 100);

        $this->assertCount(4, $parts);
        foreach ($parts as $p) {
            $this->assertLessThanOrEqual(100, mb_strlen($p));
            $this->assertStringNotContainsString("\n\n", $p);
        }
        $this->assertSame(['коротко'], MediaPublisherService::splitForMax('коротко', 100));
    }

    public function test_upload_token_from_photos_map_or_plain_token(): void
    {
        $this->assertSame('t1', MediaPublisherService::maxUploadToken(['photos' => ['9' => ['token' => 't1']]]));
        $this->assertSame('t2', MediaPublisherService::maxUploadToken(['token' => 't2']));
        $this->assertSame('t3', MediaPublisherService::maxUploadToken([], ['token' => 't3']));
        $this->assertNull(MediaPublisherService::maxUploadToken([]));
    }
}
