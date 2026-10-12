<?php

namespace App\Services\Bot;

use App\Mail\BotVerificationCodeMail;
use App\Models\MessengerChat;
use App\Services\Mail\SystemNotificationMailer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Подтверждение e-mail клиента бота кодом из письма. Код — 6 цифр, хранится
 * хэшем в state чата, живёт 15 минут, 5 попыток ввода, не больше 4 писем за
 * 30 минут (как в боте LazyLift). Письмо — с общего ящика через
 * SystemNotificationMailer (на проде MAIL_MAILER=log, обычный Mail не уйдёт).
 */
class BotEmailVerifier
{
    public const TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const MAX_SENDS_PER_30MIN = 4;

    /** Имя отправителя писем клиентам бота. */
    public const FROM_NAME = 'MyZip';

    public function __construct(private readonly SystemNotificationMailer $mailer)
    {
    }

    /** @return array{ok: bool, reason?: 'throttled'|'send_failed'} */
    public function send(MessengerChat $chat, string $email): array
    {
        $sends = array_values(array_filter((array) $chat->stateGet('code_sends', []), fn ($t) => (int) $t > time() - 1800));
        if (count($sends) >= self::MAX_SENDS_PER_30MIN) {
            return ['ok' => false, 'reason' => 'throttled'];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        try {
            $sent = $this->mailer->sendMailable($email, new BotVerificationCodeMail($code, $chat->channel->label()), self::FROM_NAME);
        } catch (\Throwable $e) {
            Log::warning('BotEmailVerifier: code email failed', ['chat_id' => $chat->id, 'error' => $e->getMessage()]);
            $sent = false;
        }
        if (! $sent) {
            return ['ok' => false, 'reason' => 'send_failed'];
        }

        $sends[] = time();
        $chat->stateSet('pending_email', mb_strtolower($email));
        $chat->stateSet('code_hash', Hash::make($code));
        $chat->stateSet('code_expires_at', time() + self::TTL_MINUTES * 60);
        $chat->stateSet('code_attempts', 0);
        $chat->stateSet('code_sends', $sends);
        $chat->save();

        return ['ok' => true];
    }

    /** @return array{ok: bool, reason?: 'no_code'|'expired'|'too_many_attempts'|'mismatch'} */
    public function verify(MessengerChat $chat, string $code): array
    {
        $hash = (string) $chat->stateGet('code_hash', '');
        $email = (string) $chat->stateGet('pending_email', '');
        if ($hash === '' || $email === '') {
            return ['ok' => false, 'reason' => 'no_code'];
        }
        if ((int) $chat->stateGet('code_expires_at', 0) < time()) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        $attempts = (int) $chat->stateGet('code_attempts', 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'reason' => 'too_many_attempts'];
        }
        if (! Hash::check(preg_replace('/\D+/', '', $code) ?? '', $hash)) {
            $chat->stateSet('code_attempts', $attempts + 1);
            $chat->save();

            return ['ok' => false, 'reason' => 'mismatch'];
        }

        // Смена адреса — организацию и её цены прежнего адреса не переносим.
        if (mb_strtolower((string) $chat->email) !== $email) {
            $chat->organization_id = null;
        }
        $chat->email = $email;
        $chat->email_verified_at = now();
        $chat->stateForget('code_hash', 'code_expires_at', 'code_attempts', 'pending_email');
        $chat->save();

        return ['ok' => true];
    }
}
