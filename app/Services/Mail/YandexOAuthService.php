<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * OAuth 2.0 для Yandex 360 (XOAUTH2 для IMAP/SMTP).
 *
 * Документация:
 *   https://yandex.ru/dev/id/doc/dg/oauth/concepts/about.html
 *   https://yandex.ru/dev/api360/doc/concepts/access.html (специфика 360)
 *
 * Endpoints:
 *   Authorize:  https://oauth.yandex.ru/authorize
 *   Token:      https://oauth.yandex.ru/token
 *
 * Поток:
 *   1. /oauth/yandex/authorize?mailbox=N → редирект на authorize URL Yandex
 *      с client_id, scope, state (содержит mailbox_id), force_confirm=yes.
 *   2. Пользователь подтверждает в Yandex, возвращается на redirect_uri
 *      с ?code=...&state=...
 *   3. exchangeCode($code) → POST на token endpoint → {access_token,
 *      refresh_token, expires_in, token_type, scope}
 *   4. Сохраняем в Mailbox.encrypted_credentials (см. Mailbox::setOAuthTokens).
 *   5. ensureFreshToken() перед каждым использованием — рефрешит, если
 *      access_token близок к истечению.
 */
class YandexOAuthService
{
    private const AUTHORIZE_URL = 'https://oauth.yandex.ru/authorize';
    private const TOKEN_URL = 'https://oauth.yandex.ru/token';

    public function clientId(): string
    {
        $value = (string) Config::get('services.yandex.client_id');
        if ($value === '') {
            throw new RuntimeException('YANDEX_OAUTH_CLIENT_ID is not set in .env.');
        }

        return $value;
    }

    public function clientSecret(): string
    {
        $value = (string) Config::get('services.yandex.client_secret');
        if ($value === '') {
            throw new RuntimeException('YANDEX_OAUTH_CLIENT_SECRET is not set in .env.');
        }

        return $value;
    }

    public function redirectUri(): string
    {
        return (string) Config::get('services.yandex.redirect_uri');
    }

    public function scope(): string
    {
        return (string) Config::get('services.yandex.scope', 'mail:imap_full mail:smtp');
    }

    /**
     * Построить URL авторизации для редиректа пользователя.
     *
     * state — произвольная строка, возвращается Yandex'ом в callback.
     * Туда кладём подписанный mailbox_id, чтобы понять, для какого ящика токены.
     */
    public function authorizationUrl(string $state, ?string $loginHint = null): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'scope' => $this->scope(),
            'state' => $state,
            // force_confirm=yes — заставляет показывать форму подтверждения,
            // даже если пользователь уже давал доступ. Полезно при смене аккаунта.
            'force_confirm' => 'yes',
        ];

        if ($loginHint) {
            // login_hint подсказывает Yandex, под каким аккаунтом авторизоваться.
            $params['login_hint'] = $loginHint;
        }

        return self::AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /**
     * Обменять authorization code на пару (access, refresh) токенов.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_at: Carbon, scope: ?string, token_type: string}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
        ]);

        return $this->parseTokenResponse($response->json(), $response->status());
    }

    /**
     * Обновить access_token по refresh_token.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_at: Carbon, scope: ?string, token_type: string}
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);

        return $this->parseTokenResponse($response->json(), $response->status());
    }

    /**
     * Гарантировать, что access_token у Mailbox актуален.
     * Если протухает — рефрешим и сохраняем.
     */
    public function ensureFreshToken(Mailbox $mailbox): void
    {
        if (! $mailbox->isOAuth()) {
            return;
        }
        if (! $mailbox->isOAuthTokenExpired()) {
            return;
        }

        $refresh = $mailbox->refreshToken();
        if (! $refresh) {
            throw new RuntimeException(
                "OAuth access-token expired and no refresh-token available for mailbox {$mailbox->email}."
            );
        }

        $tokens = $this->refreshAccessToken($refresh);
        $mailbox->setOAuthTokens(
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'] ?? $refresh, // Yandex может не прислать новый — оставляем старый
            expiresAt: $tokens['expires_at'],
            scope: $tokens['scope'],
            tokenType: $tokens['token_type'],
        );
        $mailbox->save();

        Log::info('Yandex OAuth token refreshed', ['mailbox_id' => $mailbox->id]);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array{access_token: string, refresh_token: ?string, expires_at: Carbon, scope: ?string, token_type: string}
     */
    private function parseTokenResponse(?array $body, int $status): array
    {
        if (! is_array($body) || $status >= 400 || ! isset($body['access_token'])) {
            $error = is_array($body) ? json_encode($body) : 'no body';
            throw new RuntimeException("Yandex OAuth token endpoint returned {$status}: {$error}");
        }

        $expiresIn = (int) ($body['expires_in'] ?? 3600);

        return [
            'access_token' => (string) $body['access_token'],
            'refresh_token' => isset($body['refresh_token']) ? (string) $body['refresh_token'] : null,
            'expires_at' => Carbon::now()->addSeconds($expiresIn),
            'scope' => isset($body['scope']) ? (string) $body['scope'] : null,
            'token_type' => (string) ($body['token_type'] ?? 'bearer'),
        ];
    }
}
