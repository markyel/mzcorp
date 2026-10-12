<?php

namespace App\Services\Bot;

use App\Enums\MessengerChannel;
use App\Models\AppSetting;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ключи ботов заявок (Telegram, MAX) и секреты вебхуков. Хранятся в
 * app_settings под bot.{channel}.*; ключ бота — зашифрованным (Crypt),
 * в форму не возвращается. Задаются в «Настройках» → «Боты заявок».
 */
class BotSettings
{
    public const PREFIX = 'bot.';

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function token(MessengerChannel $channel): ?string
    {
        $enc = (string) $this->settings->get($this->key($channel, 'token'), '');
        if ($enc === '') {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable $e) {
            Log::warning('BotSettings: cannot decrypt token', ['channel' => $channel->value, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function hasToken(MessengerChannel $channel): bool
    {
        return $this->token($channel) !== null;
    }

    public function saveToken(MessengerChannel $channel, string $token, ?int $userId): void
    {
        $this->settings->set($this->key($channel, 'token'), Crypt::encryptString(trim($token)), AppSetting::TYPE_STRING, $userId,
            'Бот заявок '.$channel->label().': ключ (зашифрован)');
    }

    public function forgetToken(MessengerChannel $channel): void
    {
        $this->settings->unset($this->key($channel, 'token'));
        $this->settings->unset($this->key($channel, 'username'));
    }

    /** Секрет вебхука: площадка присылает его в заголовке, им отсекаем чужие запросы. */
    public function webhookSecret(MessengerChannel $channel): string
    {
        $secret = (string) $this->settings->get($this->key($channel, 'webhook_secret'), '');
        if ($secret === '') {
            $secret = Str::random(48);
            $this->settings->set($this->key($channel, 'webhook_secret'), $secret, AppSetting::TYPE_STRING, null,
                'Бот заявок '.$channel->label().': секрет вебхука');
        }

        return $secret;
    }

    /** Имя бота (@…), запоминаем после проверки ключа — для ссылки в настройках. */
    public function username(MessengerChannel $channel): ?string
    {
        $u = (string) $this->settings->get($this->key($channel, 'username'), '');

        return $u !== '' ? $u : null;
    }

    public function saveUsername(MessengerChannel $channel, ?string $username): void
    {
        $this->settings->set($this->key($channel, 'username'), (string) $username, AppSetting::TYPE_STRING, null,
            'Бот заявок '.$channel->label().': имя');
    }

    private function key(MessengerChannel $channel, string $field): string
    {
        return self::PREFIX.$channel->value.'.'.$field;
    }
}
