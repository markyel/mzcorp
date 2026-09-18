<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Доступ к внешнему маркетинговому сервису (Яндекс.Директ, сервис рассылок,
 * аналитика и т.п.) в разделе «Маркетинг». Только для админа.
 *
 * Секреты (пароль, API-ключ, произвольные пары) лежат ТОЛЬКО в
 * encrypted_secrets под Crypt::encryptString — как у Mailbox::credentials().
 * Колонка в $hidden, чтобы секрет не утёк в toArray()/лог/JSON-ответ.
 */
class MarketingService extends Model
{
    public const CATEGORIES = [
        'ads' => 'Реклама',
        'email' => 'Рассылки',
        'analytics' => 'Аналитика',
        'social' => 'Соцсети и площадки',
        'site' => 'Сайт и хостинг',
        'other' => 'Прочее',
    ];

    /** Ключи секретов, которые правятся формой. Всё остальное — в extra. */
    public const SECRET_KEYS = ['password', 'api_key', 'extra'];

    protected $fillable = [
        'name',
        'category',
        'url',
        'login',
        'account_owner',
        'notes',
        'is_active',
        'last_verified_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $hidden = [
        'encrypted_secrets',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'last_verified_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? self::CATEGORIES['other'];
    }

    /**
     * Расшифрованные секреты. Повреждённый шифротекст не роняет страницу —
     * отдаём пустой набор, как Mailbox::credentials().
     *
     * @return array<string, string>
     */
    public function secrets(): array
    {
        if (! $this->encrypted_secrets) {
            return [];
        }

        try {
            $parsed = json_decode(Crypt::decryptString($this->encrypted_secrets), true);

            return is_array($parsed) ? $parsed : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Перезаписать набор секретов целиком; пустые значения не храним. */
    public function writeSecrets(array $secrets): void
    {
        $clean = [];
        foreach ($secrets as $k => $v) {
            $v = is_string($v) ? trim($v) : $v;
            if ($v !== null && $v !== '') {
                $clean[$k] = $v;
            }
        }
        $this->encrypted_secrets = $clean === []
            ? null
            : Crypt::encryptString(json_encode($clean, JSON_UNESCAPED_UNICODE));
    }

    public function secret(string $key): ?string
    {
        $v = $this->secrets()[$key] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Есть ли что показывать в «показать пароль» — без раскрытия значения. */
    public function hasSecrets(): bool
    {
        return $this->secrets() !== [];
    }
}
