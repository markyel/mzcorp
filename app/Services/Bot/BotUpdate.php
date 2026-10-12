<?php

namespace App\Services\Bot;

use App\Enums\MessengerChannel;

/**
 * Событие бота заявок в общем виде — из Telegram или MAX. Боту всё равно,
 * откуда пришло: текст, нажатая кнопка, «Начать» или переданный телефон.
 */
final class BotUpdate
{
    public function __construct(
        public readonly MessengerChannel $channel,
        public readonly string $updateId,
        public readonly string $userId,
        public readonly string $chatId,
        public readonly ?string $username = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly string $text = '',
        public readonly ?string $callbackId = null,
        public readonly ?string $callbackData = null,
        public readonly bool $isStart = false,
        public readonly ?string $contactPhone = null,
    ) {}

    public function isCallback(): bool
    {
        return $this->callbackId !== null;
    }

    /** Telegram Bot API: message / callback_query, только личные чаты. */
    public static function fromTelegram(array $u): ?self
    {
        $updateId = (string) ($u['update_id'] ?? '');

        if (isset($u['callback_query'])) {
            $cb = (array) $u['callback_query'];
            $from = (array) ($cb['from'] ?? []);
            $chat = (array) ($cb['message']['chat'] ?? []);
            if (empty($from['id']) || empty($chat['id']) || ($chat['type'] ?? 'private') !== 'private') {
                return null;
            }

            return new self(MessengerChannel::Telegram, $updateId, (string) $from['id'], (string) $chat['id'],
                $from['username'] ?? null, $from['first_name'] ?? null, $from['last_name'] ?? null,
                callbackId: (string) $cb['id'], callbackData: (string) ($cb['data'] ?? ''));
        }

        $m = (array) ($u['message'] ?? []);
        $from = (array) ($m['from'] ?? []);
        $chat = (array) ($m['chat'] ?? []);
        if (empty($from['id']) || empty($chat['id']) || ($from['is_bot'] ?? false) || ($chat['type'] ?? '') !== 'private') {
            return null;
        }
        $text = trim((string) ($m['text'] ?? ''));
        // Телефон принимаем только свой: «поделиться контактом» другого человека — не он.
        $contact = (array) ($m['contact'] ?? []);
        $phone = ! empty($contact['phone_number']) && (string) ($contact['user_id'] ?? '') === (string) $from['id']
            ? (string) $contact['phone_number'] : null;

        return new self(MessengerChannel::Telegram, $updateId, (string) $from['id'], (string) $chat['id'],
            $from['username'] ?? null, $from['first_name'] ?? null, $from['last_name'] ?? null,
            text: $text, isStart: str_starts_with($text, '/start'), contactPhone: $phone);
    }

    /** MAX Bot API: message_created / message_callback / bot_started, только диалоги. */
    public static function fromMax(array $u): ?self
    {
        $type = (string) ($u['update_type'] ?? '');
        $ts = (string) ($u['timestamp'] ?? '');

        if ($type === 'message_callback') {
            $cb = (array) ($u['callback'] ?? []);
            $user = (array) ($cb['user'] ?? $u['user'] ?? []);
            $chatId = $u['message']['recipient']['chat_id'] ?? $u['chat_id'] ?? null;
            if (empty($user['user_id']) || $chatId === null || empty($cb['callback_id'])) {
                return null;
            }

            return new self(MessengerChannel::Max, 'cb'.$cb['callback_id'], (string) $user['user_id'], (string) $chatId,
                $user['username'] ?? null, self::maxFirstName($user), $user['last_name'] ?? null,
                callbackId: (string) $cb['callback_id'], callbackData: (string) ($cb['payload'] ?? ''));
        }

        if ($type === 'bot_started') {
            $user = (array) ($u['user'] ?? []);
            $chatId = $u['chat_id'] ?? null;
            if (empty($user['user_id']) || $chatId === null) {
                return null;
            }

            return new self(MessengerChannel::Max, 'start'.$user['user_id'].$ts, (string) $user['user_id'], (string) $chatId,
                $user['username'] ?? null, self::maxFirstName($user), $user['last_name'] ?? null,
                text: '/start', isStart: true);
        }

        if ($type !== 'message_created') {
            return null;
        }

        $msg = (array) ($u['message'] ?? []);
        $body = (array) ($msg['body'] ?? []);
        $user = (array) ($msg['sender'] ?? []);
        $recipient = (array) ($msg['recipient'] ?? []);
        $chatId = $recipient['chat_id'] ?? null;
        if (empty($user['user_id']) || $chatId === null || ($user['is_bot'] ?? false)
            || (string) ($recipient['chat_type'] ?? 'dialog') !== 'dialog') {
            return null;
        }

        $phone = null;
        foreach ((array) ($body['attachments'] ?? []) as $a) {
            if (($a['type'] ?? '') !== 'contact') {
                continue;
            }
            $p = (array) ($a['payload'] ?? []);
            $owner = (string) ($p['max_info']['user_id'] ?? '');
            if (preg_match('/TEL[^:]*:([+\d\s\-()]+)/i', (string) ($p['vcf_info'] ?? ''), $mm) && ($owner === '' || $owner === (string) $user['user_id'])) {
                $phone = trim($mm[1]);
            }
        }
        $text = trim((string) ($body['text'] ?? ''));

        return new self(MessengerChannel::Max, 'msg'.($body['mid'] ?? $ts), (string) $user['user_id'], (string) $chatId,
            $user['username'] ?? null, self::maxFirstName($user), $user['last_name'] ?? null,
            text: $text, isStart: str_starts_with($text, '/start'), contactPhone: $phone);
    }

    private static function maxFirstName(array $user): ?string
    {
        $first = trim((string) ($user['first_name'] ?? ''));

        return $first !== '' ? $first : (isset($user['name']) ? (string) $user['name'] : null);
    }
}
