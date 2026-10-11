<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\MailFallbackSettings;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * Резервный канал отправки настраивается в «Настройках» (app_settings,
 * mail.fallback.*), ключ API — зашифрованным. Пока там не сохраняли —
 * действует .env. Канал считается включённым, только если есть всё:
 * флаг, сервер, логин и расшифровываемый ключ. БД не нужна — SettingsService мок.
 */
class MailFallbackSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @param array<string, mixed> $stored поле → значение (без префикса mail.fallback.) */
    private function settings(array $stored): MailFallbackSettings
    {
        $all = [];
        foreach ($stored as $field => $value) {
            $all[MailFallbackSettings::PREFIX.$field] = ['value' => (string) $value, 'type' => 'string', 'description' => null];
        }
        $svc = Mockery::mock(SettingsService::class);
        $svc->shouldReceive('all')->andReturn($all);
        $svc->shouldReceive('get')->andReturnUsing(
            fn ($key, $default = null) => array_key_exists($key, $all)
                ? (array_key_exists(substr($key, strlen(MailFallbackSettings::PREFIX)), $stored) ? $stored[substr($key, strlen(MailFallbackSettings::PREFIX))] : $default)
                : $default,
        );

        return new MailFallbackSettings($svc);
    }

    private function full(array $override = []): array
    {
        return $override + [
            'enabled' => true,
            'name' => 'Unisender Go',
            'host' => 'smtp.go1.unisender.ru',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '6120000',
            'password' => Crypt::encryptString('secret-key'),
            'after_attempts' => 3,
            'disable_tracking' => true,
        ];
    }

    public function test_env_is_used_until_saved_in_ui(): void
    {
        config([
            'services.mail_outbound.fallback.dsn' => 'smtp://u:p@smtp.go1.unisender.ru:587',
            'services.mail_outbound.fallback.name' => 'Из env',
        ]);
        $s = $this->settings([]);

        $this->assertFalse($s->managedInUi());
        $this->assertTrue($s->enabled());
        $this->assertSame('Из env', $s->name());
    }

    public function test_ui_settings_override_env(): void
    {
        config(['services.mail_outbound.fallback.dsn' => 'smtp://u:p@other.example:587']);
        $s = $this->settings($this->full(['enabled' => false]));

        // В «Настройках» канал выключен — .env больше не включает его.
        $this->assertTrue($s->managedInUi());
        $this->assertFalse($s->enabled());
    }

    public function test_enabled_only_with_server_login_and_key(): void
    {
        $this->assertTrue($this->settings($this->full())->enabled());
        $this->assertFalse($this->settings($this->full(['host' => '']))->enabled());
        $this->assertFalse($this->settings($this->full(['username' => '']))->enabled());
        $this->assertFalse($this->settings($this->full(['password' => '']))->enabled());
        // Ключ не расшифровать (сменили APP_KEY) — канал не считается настроенным.
        $this->assertFalse($this->settings($this->full(['password' => 'not-encrypted']))->enabled());
    }

    public function test_transport_built_from_ui_fields(): void
    {
        $transport = $this->settings($this->full())->transport();

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('6120000', $transport->getUsername());
        $this->assertSame('secret-key', $transport->getPassword());
    }

    public function test_no_tracking_header_only_when_asked(): void
    {
        $this->assertArrayHasKey('X-UNISENDER-GO', $this->settings($this->full())->headers());
        $this->assertSame([], $this->settings($this->full(['disable_tracking' => false]))->headers());
    }
}
