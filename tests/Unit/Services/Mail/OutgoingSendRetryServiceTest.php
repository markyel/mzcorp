<?php

namespace Tests\Unit\Services\Mail;

use App\Models\EmailMessage;
use App\Services\Mail\MailFallbackSettings;
use App\Services\Mail\OutgoingSendRetryService;
use App\Services\Settings\SettingsService;
use Carbon\Carbon;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\TestCase;

/**
 * Автоповтор отправки — только когда письмо гарантированно не ушло:
 * соединение не установилось или сервер временно отказал (4xx). Обрыв
 * посреди сессии не повторяем — сервер мог принять письмо, повтор = дубль
 * клиенту. Кейс: сбой smtp.yandex.ru 2026-10-11 («Connection timed out»
 * на установке соединения). БД не нужна.
 */
class OutgoingSendRetryServiceTest extends TestCase
{
    private OutgoingSendRetryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        // В «Настройках» канал не сохраняли — действуют значения config/.env.
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('all')->andReturn([]);
        $settings->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);
        $this->svc = new OutgoingSendRetryService(new MailFallbackSettings($settings));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_connection_not_established_is_transient(): void
    {
        $e = new TransportException('Connection could not be established with host "ssl://smtp.yandex.ru:465": stream_socket_client(): Unable to connect to ssl://smtp.yandex.ru:465 (Connection timed out)');

        $this->assertTrue($this->svc->isTransient($e));
    }

    public function test_starttls_failure_is_transient(): void
    {
        $this->assertTrue($this->svc->isTransient(new TransportException('Unable to connect with STARTTLS: ...')));
    }

    public function test_temporary_smtp_reject_is_transient(): void
    {
        foreach ([421, 450, 451, 452, 454, 455, 471] as $code) {
            $e = new UnexpectedResponseException("Expected response code \"250\" but got code \"{$code}\".", $code);
            $this->assertTrue($this->svc->isTransient($e), "code {$code}");
        }
    }

    public function test_permanent_smtp_reject_is_not_transient(): void
    {
        foreach ([535, 550, 552, 554] as $code) {
            $e = new UnexpectedResponseException("Expected response code \"250\" but got code \"{$code}\".", $code);
            $this->assertFalse($this->svc->isTransient($e), "code {$code}");
        }
    }

    public function test_mid_session_drop_is_not_transient(): void
    {
        // Сервер мог уже принять письмо — повтор отправил бы клиенту дубль.
        $this->assertFalse($this->svc->isTransient(new TransportException('Connection to "smtp.yandex.ru:465" timed out.')));
        $this->assertFalse($this->svc->isTransient(new TransportException('Connection to "smtp.yandex.ru:465" has been closed unexpectedly.')));
    }

    public function test_non_transport_error_is_not_transient(): void
    {
        $this->assertFalse($this->svc->isTransient(new \RuntimeException('Connection could not be established')));
    }

    public function test_backoff_follows_config_and_repeats_last_step(): void
    {
        config(['services.mail_outbound.retry_backoff_minutes' => [1, 5, 15, 30, 60]]);

        $this->assertSame(1, $this->svc->backoffMinutes(1));
        $this->assertSame(5, $this->svc->backoffMinutes(2));
        $this->assertSame(60, $this->svc->backoffMinutes(5));
        $this->assertSame(60, $this->svc->backoffMinutes(11));
    }

    public function test_no_fallback_when_channel_not_configured(): void
    {
        config(['services.mail_outbound.fallback.dsn' => null]);

        $this->assertFalse($this->svc->fallbackEnabled());
        $this->assertFalse($this->svc->shouldUseFallback(new EmailMessage(['send_attempts' => 5])));
    }

    public function test_fallback_after_threshold_then_channels_alternate(): void
    {
        config([
            'services.mail_outbound.fallback.dsn' => 'smtp://u:p@smtp.go1.unisender.ru:587',
            'services.mail_outbound.fallback.after_attempts' => 3,
        ]);

        $via = fn (int $attempts) => $this->svc->shouldUseFallback(new EmailMessage(['send_attempts' => $attempts]));

        // До порога — только SMTP ящика.
        $this->assertFalse($via(1));
        $this->assertFalse($via(2));
        // С порога — резерв, ящик, резерв…: поднявшийся первым канал и отправит.
        $this->assertTrue($via(3));
        $this->assertFalse($via(4));
        $this->assertTrue($via(5));
        $this->assertFalse($via(6));
    }

    public function test_queued_message_names_next_attempt_time(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);
        $next = Carbon::parse('2026-10-11 17:40:00', 'Europe/Moscow');

        $this->assertStringContainsString('в 17:40', OutgoingSendRetryService::queuedMessage($next));
    }
}
