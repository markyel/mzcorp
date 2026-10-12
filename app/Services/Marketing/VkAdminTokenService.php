<?php

namespace App\Services\Marketing;

use App\Models\MediaChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ключ администратора сообщества ВК (VK ID, OAuth 2.1 + PKCE) — для фото.
 *
 * Ключом сообщества ВК не даёт грузить фото на стену и в альбом (код 27
 * «method is unavailable with group auth»), а обход через сервер сообщений
 * с 08.10.2026 отвечает пустым photo — посты уходили без картинок. Штатный
 * путь — ключ пользователя-администратора: им грузим фото на стену
 * сообщества, сам пост по-прежнему публикуется ключом сообщества.
 *
 * Ключ VK ID живёт около часа, продлеваем refresh-токеном (он одноразовый:
 * в ответе приходит новый) — под блокировкой, чтобы воркер и кнопка в
 * разделе не сожгли его вдвоём. Всё хранится в шифрованных секретах канала.
 */
class VkAdminTokenService
{
    /** Продлеваем заранее: загрузка фото с постом занимает до минуты. */
    private const REFRESH_MARGIN = 300;

    public static function configured(): bool
    {
        return (string) config('services.vk_id.client_id') !== '';
    }

    public static function connected(MediaChannel $channel): bool
    {
        return $channel->secret('admin_refresh_token') !== null;
    }

    /** Срок текущего ключа (unix), null — ключа нет. */
    public static function expiresAt(MediaChannel $channel): ?int
    {
        $v = $channel->secret('admin_expires_at');

        return $v !== null ? (int) $v : null;
    }

    /**
     * Адрес входа и то, что нужно запомнить до возврата (state, verifier).
     *
     * @return array{url: string, state: string, verifier: string}
     */
    public function authorizeUrl(string $redirectUri): array
    {
        $verifier = Str::random(64);
        $state = Str::random(40);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $url = $this->base().'/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => (string) config('services.vk_id.client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'scope' => (string) config('services.vk_id.scope', 'wall photos groups'),
        ], '', '&', PHP_QUERY_RFC3986);

        return ['url' => $url, 'state' => $state, 'verifier' => $verifier];
    }

    /**
     * Обмен кода на ключ после входа администратора.
     *
     * @return array{ok: bool, message: string}
     */
    public function exchange(MediaChannel $channel, string $code, string $deviceId, string $verifier, string $state, string $redirectUri): array
    {
        try {
            $r = Http::timeout(20)->asForm()->post($this->base().'/oauth2/auth', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'code_verifier' => $verifier,
                'client_id' => (string) config('services.vk_id.client_id'),
                'device_id' => $deviceId,
                'redirect_uri' => $redirectUri,
                'state' => $state,
            ])->json() ?? [];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'VK ID не ответил: '.$e->getMessage()];
        }

        if (empty($r['access_token']) || empty($r['refresh_token'])) {
            return ['ok' => false, 'message' => 'VK ID: '.($r['error_description'] ?? $r['error'] ?? 'ключ не выдан')];
        }

        $this->store($channel, $r, $deviceId);

        return ['ok' => true, 'message' => 'Вход выполнен, права: '.((string) ($r['scope'] ?? '—')).'.'];
    }

    /** Действующий ключ администратора; продлевает при необходимости. null — ключа нет или продлить не вышло. */
    public function accessToken(MediaChannel $channel): ?string
    {
        if (! self::connected($channel)) {
            return null;
        }
        if ((self::expiresAt($channel) ?? 0) - self::REFRESH_MARGIN > time()) {
            return $channel->secret('admin_access_token');
        }

        return Cache::lock('vkid:refresh:'.$channel->id, 30)->block(25, function () use ($channel) {
            $channel->refresh();
            if ((self::expiresAt($channel) ?? 0) - self::REFRESH_MARGIN > time()) {
                return $channel->secret('admin_access_token');
            }

            try {
                $r = Http::timeout(20)->asForm()->post($this->base().'/oauth2/auth', [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => (string) $channel->secret('admin_refresh_token'),
                    'client_id' => (string) config('services.vk_id.client_id'),
                    'device_id' => (string) $channel->secret('admin_device_id'),
                    'state' => Str::random(40),
                ])->json() ?? [];
            } catch (\Throwable $e) {
                Log::warning('VkAdminTokenService: refresh failed', ['channel_id' => $channel->id, 'error' => $e->getMessage()]);

                return null;
            }

            if (empty($r['access_token'])) {
                $why = (string) ($r['error_description'] ?? $r['error'] ?? 'нет ответа');
                Log::warning('VkAdminTokenService: refresh rejected', ['channel_id' => $channel->id, 'error' => $why]);
                $channel->forceFill(['last_error' => 'Ключ администратора ВК не продлился ('.$why.') — войдите администратором заново, иначе посты пойдут без фото.'])->save();

                return null;
            }

            $this->store($channel, $r, (string) $channel->secret('admin_device_id'));

            return (string) $r['access_token'];
        });
    }

    public function forget(MediaChannel $channel): void
    {
        $channel->writeSecrets(array_diff_key($channel->secrets(), array_flip([
            'admin_access_token', 'admin_refresh_token', 'admin_expires_at', 'admin_device_id', 'admin_user_id', 'admin_scope',
        ])));
        $channel->save();
    }

    /** @param  array<string, mixed>  $r */
    private function store(MediaChannel $channel, array $r, string $deviceId): void
    {
        $channel->writeSecrets(array_merge($channel->secrets(), [
            'admin_access_token' => (string) $r['access_token'],
            // Refresh-токен одноразовый: в ответе продления приходит новый.
            'admin_refresh_token' => (string) ($r['refresh_token'] ?? $channel->secret('admin_refresh_token')),
            'admin_expires_at' => (string) (time() + max(60, (int) ($r['expires_in'] ?? 3600))),
            'admin_device_id' => $deviceId,
            'admin_user_id' => (string) ($r['user_id'] ?? $channel->secret('admin_user_id') ?? ''),
            'admin_scope' => (string) ($r['scope'] ?? $channel->secret('admin_scope') ?? ''),
        ]));
        $channel->save();
    }

    private function base(): string
    {
        return rtrim((string) config('services.vk_id.base', 'https://id.vk.ru'), '/');
    }
}
