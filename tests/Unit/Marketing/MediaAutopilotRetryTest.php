<?php

namespace Tests\Unit\Marketing;

use App\Models\MediaChannel;
use App\Models\MediaPublication;
use App\Services\Marketing\MediaAutopilotService;
use App\Services\Marketing\MediaCoverService;
use App\Services\Marketing\MediaPublisherService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 27.10.2026 Telegram с сервера не отвечал (SSL connection timeout): утренняя
 * автопубликация «Снижение цен» осталась черновиком, и днём её никто не повторил.
 * Теперь такой материал повторяется каждый час — но только если на площадку
 * ничего не ушло: обрыв после отправки мог уже опубликовать пост.
 */
class MediaAutopilotRetryTest extends TestCase
{
    private MediaChannel $tg;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config([
            'database.connections.retry_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'retry_test',
            // Обложка при повторе — свои тесты ниже, с подменённым MediaCoverService.
            'services.openai.media_cover.enabled' => false,
        ]);
        Schema::create('media_channels', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('kind');
            $t->string('url')->nullable();
            $t->string('handle')->nullable();
            $t->boolean('is_active')->default(true);
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

        $this->tg = MediaChannel::create(['name' => 'Telegram · MyZip', 'kind' => 'telegram', 'is_active' => true, 'auto_publish' => true]);
        $this->tg->writeSecrets(['bot_token' => '123:abc', 'chat_id' => '@myzipru']);
        $this->tg->save();
    }

    private function publication(array $attrs = []): MediaPublication
    {
        return MediaPublication::create($attrs + [
            'media_channel_id' => $this->tg->id,
            'title' => 'Снижение цен на шесть позиций',
            'body' => 'Снижение цен на шесть позиций. Плата управления',
            'status' => 'draft',
            'planned_for' => now()->toDateString(),
        ]);
    }

    private function autopilot(): MediaAutopilotService
    {
        return app(MediaAutopilotService::class);
    }

    private function queue(MediaPublication $publication): void
    {
        (new ReflectionMethod(MediaAutopilotService::class, 'queueRetry'))->invoke($this->autopilot(), $publication);
    }

    public function test_a_post_that_did_not_reach_telegram_goes_out_on_a_later_retry(): void
    {
        $down = true;
        Http::fake(function (Request $r) use (&$down) {
            if ($down) {
                throw new ConnectionException('cURL error 28: SSL connection timeout for https://api.telegram.org/bot***/sendMessage');
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 70]]);
        });

        $pub = $this->publication();
        $first = app(MediaPublisherService::class)->publish($pub);
        $this->assertFalse($first['ok']);
        $this->assertTrue($first['retry']);
        $this->queue($pub);

        // Связи всё ещё нет — материал ждёт следующего часа.
        $this->assertSame(['published' => 0, 'waiting' => 1, 'dropped' => 0], $this->autopilot()->retryFailed());
        $this->assertSame('draft', $pub->fresh()->status);

        $down = false;
        $this->assertSame(['published' => 1, 'waiting' => 0, 'dropped' => 0], $this->autopilot()->retryFailed());
        $this->assertSame('published', $pub->fresh()->status);
        $this->assertSame('https://t.me/myzipru/70', $pub->fresh()->url);
        // Очередь пуста — следующий час ничего не шлёт.
        $this->assertSame(['published' => 0, 'waiting' => 0, 'dropped' => 0], $this->autopilot()->retryFailed());
    }

    public function test_a_timeout_after_sending_is_not_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received'));

        $res = app(MediaPublisherService::class)->publish($this->publication());

        $this->assertFalse($res['ok']);
        $this->assertFalse($res['retry']);
    }

    public function test_a_sent_album_is_not_sent_again_when_the_text_after_it_fails(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'mzcorp.test')) {
                return Http::response('JPEG', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '4']);
            }
            if (str_ends_with($r->url(), 'sendMediaGroup')) {
                return Http::response(['ok' => true, 'result' => [['message_id' => 71]]]);
            }
            throw new ConnectionException('cURL error 7: Failed to connect to api.telegram.org');
        });

        // Текст длиннее подписи альбома (1024) — уходит отдельным сообщением после альбома.
        $res = app(MediaPublisherService::class)->publish($this->publication([
            'body' => str_repeat('Плата управления лифтом. ', 60),
            'image_urls' => ['https://mzcorp.test/media/cover/c.jpg'],
        ]));

        $this->assertFalse($res['ok']);
        $this->assertFalse($res['retry']);
    }

    /** 27.10.2026: черновик 112 написан, когда обложки не генерировались, — повтор выпустил его без картинки. */
    public function test_a_retry_makes_the_missing_cover_before_publishing(): void
    {
        config(['services.openai.media_cover.enabled' => true]);
        $covers = Mockery::mock(MediaCoverService::class);
        $covers->shouldReceive('make')->once()
            ->with('Снижение цен на шесть позиций', Mockery::type('string'), null, null)
            ->andReturn(['ok' => true, 'url' => 'https://mzcorp.test/media/cover/new.jpg', 'message' => '']);
        $this->app->instance(MediaCoverService::class, $covers);
        Http::fake(function (Request $r) {
            return str_contains($r->url(), 'mzcorp.test')
                ? Http::response('JPEG', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '4'])
                : Http::response(['ok' => true, 'result' => [['message_id' => 72]]]);
        });
        $pub = $this->publication();
        $this->queue($pub);

        $this->assertSame(1, $this->autopilot()->retryFailed()['published']);

        $this->assertSame(['https://mzcorp.test/media/cover/new.jpg'], $pub->fresh()->images());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'sendMediaGroup'));
    }

    public function test_without_a_cover_the_retry_still_publishes_and_a_cover_is_not_redone(): void
    {
        config(['services.openai.media_cover.enabled' => true]);
        $covers = Mockery::mock(MediaCoverService::class);
        $covers->shouldReceive('make')->once()->andReturn(['ok' => false, 'url' => null, 'message' => "генерация картинки: The model 'gpt-image-1' does not exist."]);
        $this->app->instance(MediaCoverService::class, $covers);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 73]]));
        $bare = $this->publication();
        $withCover = $this->publication(['image_urls' => ['https://mzcorp.test/media/cover/old.jpg']]);
        $this->queue($bare);
        $this->queue($withCover);

        // make() — только для черновика без обложки; оба выходят.
        $this->assertSame(2, $this->autopilot()->retryFailed()['published']);
        $this->assertSame('published', $bare->fresh()->status);
        $this->assertSame(['https://mzcorp.test/media/cover/old.jpg'], $withCover->fresh()->images());
    }

    public function test_a_post_released_or_rejected_by_hand_is_left_alone(): void
    {
        Http::fake();
        $released = $this->publication();
        $rejected = $this->publication();
        $this->queue($released);
        $this->queue($rejected);
        $released->update(['status' => 'published', 'published_at' => now()]);
        $rejected->update(['status' => 'rejected']);

        $this->assertSame(['published' => 0, 'waiting' => 0, 'dropped' => 2], $this->autopilot()->retryFailed());
        Http::assertNothingSent();
    }
}
