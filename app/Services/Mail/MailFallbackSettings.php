<?php

namespace App\Services\Mail;

use App\Models\AppSetting;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Резервный канал отправки (Unisender Go, потом Postbox) — когда SMTP
 * Яндекса не отвечает. Настраивается в «Настройках» (блок «Резервный канал
 * отправки»), хранится в app_settings под mail.fallback.*; ключ API —
 * зашифрованным (Crypt), в форму не возвращается.
 *
 * Пока в «Настройках» канал не сохраняли — действует .env
 * (MAIL_OUTBOUND_FALLBACK_DSN и соседи, config services.mail_outbound.fallback).
 */
class MailFallbackSettings
{
    public const PREFIX = 'mail.fallback.';

    /** Пресеты провайдеров: подставляются в форму, всё можно поправить. */
    public const PRESETS = [
        'unisender' => ['name' => 'Unisender Go', 'host' => 'smtp.go1.unisender.ru', 'port' => 587, 'encryption' => 'tls'],
        'postbox' => ['name' => 'Yandex Cloud Postbox', 'host' => 'postbox.cloud.yandex.net', 'port' => 587, 'encryption' => 'tls'],
    ];

    /** Unisender Go: не переписывать ссылки и не вставлять пиксель в письма менеджеров. */
    private const UNISENDER_NO_TRACKING = ['X-UNISENDER-GO' => '{"track_links":0,"track_read":0}'];

    public function __construct(private readonly SettingsService $settings)
    {
    }

    /** Канал сохраняли в «Настройках» — .env больше не смотрим. */
    public function managedInUi(): bool
    {
        return array_key_exists(self::PREFIX.'enabled', $this->settings->all());
    }

    public function enabled(): bool
    {
        if (! $this->managedInUi()) {
            return trim((string) config('services.mail_outbound.fallback.dsn')) !== '';
        }

        return (bool) $this->get('enabled', false)
            && $this->get('host', '') !== ''
            && $this->get('username', '') !== ''
            && $this->hasPassword();
    }

    public function name(): string
    {
        $name = $this->managedInUi() ? (string) $this->get('name', '') : (string) config('services.mail_outbound.fallback.name');

        return $name !== '' ? $name : 'резервный канал';
    }

    public function afterAttempts(): int
    {
        $n = $this->managedInUi()
            ? (int) $this->get('after_attempts', 3)
            : (int) config('services.mail_outbound.fallback.after_attempts', 3);

        return max(1, $n);
    }

    /** @return array<string, string> служебные заголовки провайдеру */
    public function headers(): array
    {
        if (! $this->managedInUi()) {
            return array_map(
                fn ($v) => is_string($v) ? $v : (string) json_encode($v),
                (array) config('services.mail_outbound.fallback.headers', []),
            );
        }

        return $this->get('disable_tracking', true) ? self::UNISENDER_NO_TRACKING : [];
    }

    public function transport(): TransportInterface
    {
        if (! $this->managedInUi()) {
            $dsn = trim((string) config('services.mail_outbound.fallback.dsn'));
            if ($dsn === '') {
                throw new \RuntimeException('Резервный канал отправки не настроен.');
            }

            return Transport::fromDsn($dsn);
        }

        $host = (string) $this->get('host', '');
        $password = $this->password();
        if ($host === '' || $password === null) {
            throw new \RuntimeException('Резервный канал отправки не настроен: нет сервера или ключа.');
        }

        return (new EsmtpTransportFactory())->create(new Dsn(
            scheme: $this->get('encryption', 'tls') === 'ssl' ? 'smtps' : 'smtp',
            host: $host,
            user: (string) $this->get('username', ''),
            password: $password,
            port: (int) $this->get('port', 587) ?: null,
        ));
    }

    /**
     * Соединиться и авторизоваться, ничего не отправляя. Бросает исключение
     * с текстом ошибки сервера — его показываем в «Настройках».
     */
    public function testConnection(): void
    {
        $transport = $this->transport();
        if (! $transport instanceof SmtpTransport) {
            return;
        }
        $transport->start();
        $transport->stop();
    }

    /** @return array{enabled: bool, name: string, host: string, port: int, encryption: string, username: string, after_attempts: int, disable_tracking: bool} */
    public function formValues(): array
    {
        $preset = self::PRESETS['unisender'];

        return [
            'enabled' => (bool) $this->get('enabled', false),
            'name' => (string) $this->get('name', $preset['name']),
            'host' => (string) $this->get('host', $preset['host']),
            'port' => (int) $this->get('port', $preset['port']),
            'encryption' => (string) $this->get('encryption', $preset['encryption']),
            'username' => (string) $this->get('username', ''),
            'after_attempts' => (int) $this->get('after_attempts', (int) config('services.mail_outbound.fallback.after_attempts', 3)),
            'disable_tracking' => (bool) $this->get('disable_tracking', true),
        ];
    }

    public function hasPassword(): bool
    {
        return $this->password() !== null;
    }

    /**
     * @param  array{enabled: bool, name: string, host: string, port: int, encryption: string, username: string, after_attempts: int, disable_tracking: bool}  $values
     * @param  string|null  $newPassword  null/'' — оставить сохранённый ключ
     */
    public function save(array $values, ?string $newPassword, ?int $userId): void
    {
        $types = [
            'enabled' => AppSetting::TYPE_BOOL,
            'name' => AppSetting::TYPE_STRING,
            'host' => AppSetting::TYPE_STRING,
            'port' => AppSetting::TYPE_INT,
            'encryption' => AppSetting::TYPE_STRING,
            'username' => AppSetting::TYPE_STRING,
            'after_attempts' => AppSetting::TYPE_INT,
            'disable_tracking' => AppSetting::TYPE_BOOL,
        ];
        foreach ($types as $field => $type) {
            $this->settings->set(self::PREFIX.$field, $values[$field], $type, $userId, 'Резервный канал отправки: '.$field);
        }

        if ($newPassword !== null && trim($newPassword) !== '') {
            $this->settings->set(
                self::PREFIX.'password',
                Crypt::encryptString(trim($newPassword)),
                AppSetting::TYPE_STRING,
                $userId,
                'Резервный канал отправки: ключ (зашифрован)',
            );
        }
    }

    public function forgetPassword(): void
    {
        $this->settings->unset(self::PREFIX.'password');
    }

    private function password(): ?string
    {
        $enc = (string) $this->get('password', '');
        if ($enc === '') {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable $e) {
            // APP_KEY сменили — ключ не расшифровать, нужно ввести заново.
            Log::warning('MailFallbackSettings: cannot decrypt stored key', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function get(string $field, mixed $default): mixed
    {
        return $this->settings->get(self::PREFIX.$field, $default);
    }
}
