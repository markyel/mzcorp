<?php

namespace Tests\Unit\Marketing;

use App\Models\MediaChannel;
use App\Models\MediaPublication;
use App\Services\Marketing\MediaPublisherService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MAX — дубль Telegram (12.10.2026): пост вышел в Telegram — тот же текст и
 * та же обложка сразу уходят в MAX. Площадки — Http::fake, медиаплан —
 * минимальные таблицы в SQLite.
 */
class MediaDuplicateTest extends TestCase
{
    private MediaChannel $tg;

    private MediaChannel $max;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.dup_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'dup_test',
            'services.max.api_base' => 'https://max.test',
            'services.max.extra_ca' => '',
        ]);
        Schema::create('media_channels', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('kind');
            $t->string('url')->nullable();
            $t->string('handle')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->integer('posts_per_week')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->boolean('auto_publish')->default(false);
            $t->timestamp('last_posted_at')->nullable();
            $t->text('last_error')->nullable();
            $t->unsignedBigInteger('mirror_of_channel_id')->nullable();
            $t->text('encrypted_secrets')->nullable();
            $t->timestamps();
        });
        Schema::create('media_publications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('media_topic_id')->nullable();
            $t->unsignedBigInteger('media_channel_id')->nullable();
            $t->string('title')->nullable();
            $t->text('body')->nullable();
            $t->string('status')->default('draft');
            $t->date('planned_for')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->string('url')->nullable();
            $t->string('model')->nullable();
            $t->string('subject_key')->nullable();
            $t->string('external_id')->nullable();
            $t->unsignedBigInteger('media_profile_review_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->json('image_urls')->nullable();
            $t->json('article')->nullable();
            $t->timestamps();
        });

        $this->tg = MediaChannel::create(['name' => 'Telegram', 'kind' => 'telegram', 'url' => 'https://t.me/myzipru', 'handle' => '@myzipru', 'is_active' => true]);
        $this->tg->writeSecrets(['bot_token' => '123:abc', 'chat_id' => '@myzipru']);
        $this->tg->save();
        $this->max = MediaChannel::create(['name' => 'MAX', 'kind' => 'max', 'is_active' => true, 'mirror_of_channel_id' => $this->tg->id]);
        $this->max->writeSecrets(['bot_token' => 'max-tok', 'chat_id' => '-7001']);
        $this->max->save();
    }

    private function publication(): MediaPublication
    {
        return MediaPublication::create([
            'media_channel_id' => $this->tg->id,
            'title' => 'Новые позиции каталога',
            'body' => 'Новые позиции каталога. Плата управления',
            'status' => 'draft',
            'image_urls' => ['https://mzcorp.test/media/cover/c.jpg'],
        ]);
    }

    public function test_post_to_telegram_is_duplicated_to_max_with_the_same_cover(): void
    {
        Http::fake([
            'https://mzcorp.test/*' => Http::response('JPEG', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '4']),
            'https://api.telegram.org/*sendMediaGroup' => Http::response(['ok' => true, 'result' => [['message_id' => 69]]]),
            'https://max.test/uploads*' => Http::response(['url' => 'https://upload.max.test/1']),
            'https://upload.max.test/*' => Http::response(['token' => 'img-tok']),
            'https://max.test/messages*' => Http::response(['message' => ['body' => ['mid' => 'mid.dup'], 'url' => 'https://max.ru/channel_myzip/X']]),
        ]);

        $res = app(MediaPublisherService::class)->publish($this->publication());

        $this->assertTrue($res['ok'], $res['message']);
        $copy = MediaPublication::where('media_channel_id', $this->max->id)->first();
        $this->assertNotNull($copy);
        $this->assertSame('published', $copy->status);
        $this->assertSame('https://max.ru/channel_myzip/X', $copy->url);
        $this->assertSame(['https://mzcorp.test/media/cover/c.jpg'], $copy->images());
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://max.test/messages')
            && str_contains((string) $r['text'], 'Плата управления')
            && ($r['attachments'][0]['payload']['token'] ?? null) === 'img-tok');
    }

    public function test_failed_duplicate_leaves_a_draft_and_keeps_telegram_ok(): void
    {
        Http::fake([
            'https://mzcorp.test/*' => Http::response('JPEG', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '4']),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => [['message_id' => 69]]]),
            'https://max.test/uploads*' => Http::response(['url' => 'https://upload.max.test/1']),
            'https://upload.max.test/*' => Http::response(['token' => 'img-tok']),
            'https://max.test/messages*' => Http::response(['code' => 'chat.denied', 'message' => 'Bot is not admin'], 403),
        ]);

        $res = app(MediaPublisherService::class)->publish($this->publication());

        $this->assertTrue($res['ok']);
        $copy = MediaPublication::where('media_channel_id', $this->max->id)->first();
        $this->assertSame('draft', $copy->status);
        $this->assertStringContainsString('Bot is not admin', (string) $this->max->fresh()->last_error);
    }
}
