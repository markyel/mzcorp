<?php

namespace App\Models;

use App\Enums\MailboxType;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * Почтовый ящик (общий или личный менеджера).
 *
 * Foundation §1: подключаем общие (sales@..., info@...) и личные ящики.
 * Креды хранятся в encrypted_credentials как зашифрованный JSON
 * { password, oauth_token? }. Доступ через credentials() / setPassword().
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
     * @return array{password?: string, oauth_token?: string}
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

    /**
     * Сохранить пароль (app-password) в encrypted_credentials.
     * Сохраняет существующие поля кредов (oauth_token и т.п.).
     */
    public function setPassword(string $password): void
    {
        $creds = $this->credentials();
        $creds['password'] = $password;
        $this->encrypted_credentials = Crypt::encryptString(json_encode($creds, JSON_UNESCAPED_UNICODE));
    }

    public function password(): ?string
    {
        return $this->credentials()['password'] ?? null;
    }
}
