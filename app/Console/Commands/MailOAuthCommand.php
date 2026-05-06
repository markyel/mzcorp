<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\Mail\YandexOAuthService;
use Illuminate\Console\Command;

/**
 * OAuth-helper для Yandex 360 — обходной путь, когда HTTP callback недоступен.
 *
 * Сценарий «verification_code flow» (Yandex показывает code на странице):
 *
 *   1. php artisan mail:oauth url 1
 *      → команда выводит ссылку на oauth.yandex.ru/authorize.
 *      Открываете её в браузере, логинитесь под нужным Yandex-аккаунтом,
 *      даёте разрешения. На странице Yandex покажет 7-значный код.
 *
 *   2. php artisan mail:oauth code 1
 *      → команда запросит код через secret prompt, обменяет на токены
 *      (access + refresh), сохранит в Mailbox.encrypted_credentials.
 *
 * Этот путь работает даже когда в OAuth-приложении прописан служебный
 * Redirect URI https://oauth.yandex.ru/verification_code.
 *
 * Для штатного HTTP-callback flow используйте контроллер
 * /oauth/yandex/authorize → /oauth/yandex/callback.
 */
class MailOAuthCommand extends Command
{
    protected $signature = 'mail:oauth
        {action : url | code | refresh | show}
        {mailbox : Mailbox id}';

    protected $description = 'OAuth-операции над ящиком: получить URL / вставить code / обновить токен / показать состояние';

    public function handle(YandexOAuthService $oauth): int
    {
        $mailbox = Mailbox::find($this->argument('mailbox'));
        if (! $mailbox) {
            $this->error('Mailbox not found.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'url' => $this->showUrl($mailbox, $oauth),
            'code' => $this->pasteCode($mailbox, $oauth),
            'refresh' => $this->refresh($mailbox, $oauth),
            'show' => $this->show($mailbox),
            default => $this->invalidAction(),
        };
    }

    private function showUrl(Mailbox $mailbox, YandexOAuthService $oauth): int
    {
        $state = base64_encode(json_encode([
            'mailbox' => $mailbox->id,
            'nonce' => bin2hex(random_bytes(8)),
        ]));

        $url = $oauth->authorizationUrl($state, loginHint: $mailbox->email);

        $this->info('Откройте URL в браузере под нужным Yandex-аккаунтом:');
        $this->line('');
        $this->line('  ' . $url);
        $this->line('');
        $this->line('После «Разрешить» Yandex покажет 7-значный код.');
        $this->line("Затем выполните: php artisan mail:oauth code {$mailbox->id}");

        return self::SUCCESS;
    }

    private function pasteCode(Mailbox $mailbox, YandexOAuthService $oauth): int
    {
        $code = $this->secret('Verification code from Yandex');
        if (! $code) {
            $this->error('Code is empty.');

            return self::INVALID;
        }

        try {
            $tokens = $oauth->exchangeCode(trim($code));
        } catch (\Throwable $e) {
            $this->error('Exchange failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $mailbox->setOAuthTokens(
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'],
            expiresAt: $tokens['expires_at'],
            scope: $tokens['scope'],
            tokenType: $tokens['token_type'],
        );
        $mailbox->save();

        $this->info('Tokens saved.');
        $this->line('Expires at: ' . $tokens['expires_at']->toDateTimeString());
        $this->line('Scope: ' . ($tokens['scope'] ?? '(unknown)'));
        $this->line('Test: php artisan mail:test ' . $mailbox->id);

        return self::SUCCESS;
    }

    private function refresh(Mailbox $mailbox, YandexOAuthService $oauth): int
    {
        if (! $mailbox->refreshToken()) {
            $this->error('No refresh_token stored for this mailbox.');

            return self::FAILURE;
        }

        try {
            $oauth->ensureFreshToken($mailbox);
        } catch (\Throwable $e) {
            $this->error('Refresh failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Token refreshed.');
        $this->line('Expires at: ' . $mailbox->oauthExpiresAt()?->toDateTimeString());

        return self::SUCCESS;
    }

    private function show(Mailbox $mailbox): int
    {
        $this->line('Mailbox id: ' . $mailbox->id);
        $this->line('Email: ' . $mailbox->email);
        $this->line('Auth type: ' . $mailbox->auth_type->value);
        $this->line('Access token len: ' . strlen((string) $mailbox->accessToken()));
        $this->line('Refresh token: ' . ($mailbox->refreshToken() ? 'YES' : 'NO'));
        $this->line('Expires at: ' . ($mailbox->oauthExpiresAt()?->toDateTimeString() ?? 'n/a'));
        $this->line('Expired? ' . ($mailbox->isOAuthTokenExpired() ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Action must be one of: url, code, refresh, show.');

        return self::INVALID;
    }
}
