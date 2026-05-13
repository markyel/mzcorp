<?php

namespace App\Models;

use App\Enums\MailboxAuthType;
use App\Enums\MailboxType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Почтовый ящик (общий или личный менеджера).
 *
 * Foundation §1: подключаем общие (sales@..., info@...) и личные ящики.
 * Креды хранятся в encrypted_credentials как зашифрованный JSON.
 *
 * При auth_type=password:
 *   { "password": "..." }
 * При auth_type=oauth:
 *   { "access_token": "...", "refresh_token": "...",
 *     "expires_at": "ISO-8601", "scope": "...", "token_type": "bearer" }
 */
class Mailbox extends Model
{
    protected $fillable = [
        'name',
        'email',
        'type',
        'owner_user_id',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'encrypted_credentials',
        'auth_type',
        'is_active',
        'last_synced_at',
        'last_error_at',
        'last_error_message',
    ];

    protected $hidden = [
        'encrypted_credentials',
    ];

    protected function casts(): array
    {
        return [
            'type' => MailboxType::class,
            'auth_type' => MailboxAuthType::class,
            'is_active' => 'bool',
            'last_synced_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function folderStates(): HasMany
    {
        return $this->hasMany(MailboxFolderState::class);
    }

    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * Расшифрованный credentials как массив.
     *
     * @return array<string, mixed>
     */
    public function credentials(): array
    {
        if (! $this->encrypted_credentials) {
            return [];
        }

        try {
            $decrypted = Crypt::decryptString($this->encrypted_credentials);
            $parsed = json_decode($decrypted, true);

            return is_array($parsed) ? $parsed : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Перезаписать весь набор кредов целиком. */
    public function writeCredentials(array $creds): void
    {
        $this->encrypted_credentials = Crypt::encryptString(
            json_encode($creds, JSON_UNESCAPED_UNICODE)
        );
    }

    /* ----------------------- App-password ----------------------- */

    public function setPassword(string $password): void
    {
        $this->writeCredentials(['password' => $password]);
    }

    public function password(): ?string
    {
        return $this->credentials()['password'] ?? null;
    }

    /* ----------------------- OAuth tokens ----------------------- */

    public function setOAuthTokens(
        string $accessToken,
        ?string $refreshToken,
        ?Carbon $expiresAt,
        ?string $scope = null,
        string $tokenType = 'bearer',
    ): void {
        $creds = [
            'access_token' => $accessToken,
            'token_type' => $tokenType,
        ];
        if ($refreshToken !== null) {
            $creds['refresh_token'] = $refreshToken;
        }
        if ($expiresAt !== null) {
            $creds['expires_at'] = $expiresAt->toIso8601String();
        }
        if ($scope !== null) {
            $creds['scope'] = $scope;
        }

        $this->writeCredentials($creds);
    }

    public function accessToken(): ?string
    {
        return $this->credentials()['access_token'] ?? null;
    }

    public function refreshToken(): ?string
    {
        return $this->credentials()['refresh_token'] ?? null;
    }

    public function oauthExpiresAt(): ?Carbon
    {
        $iso = $this->credentials()['expires_at'] ?? null;

        return $iso ? Carbon::parse($iso) : null;
    }

    /**
     * Считаем токен «протухшим» с запасом 5 минут до фактического истечения,
     * чтобы успеть рефрешнуться.
     */
    public function isOAuthTokenExpired(): bool
    {
        $exp = $this->oauthExpiresAt();
        if ($exp === null) {
            return true; // нет даты — считаем устаревшим, чтобы рефрешнуть
        }

        return $exp->subMinutes(5)->isPast();
    }

    public function isOAuth(): bool
    {
        return $this->auth_type === MailboxAuthType::OAuth;
    }

    /**
     * Может ли ящик отправлять исходящие письма (Phase 1.9).
     *
     * is_active + либо app-password, либо OAuth с действующим refresh_token
     * (access можно обновить через YandexOAuthService::ensureFreshToken).
     */
    public function canSendOutbound(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isOAuth()) {
            return $this->refreshToken() !== null || ! $this->isOAuthTokenExpired();
        }

        return $this->password() !== null;
    }
}
