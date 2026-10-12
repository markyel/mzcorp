<?php

namespace App\Services\Bot;

use App\Services\Marketing\MediaPublisherService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Bot API для бота заявок. Связь с Telegram с сервера временами
 * пропадает — сбой соединения ДО отправки повторяем (как в медиаплане).
 */
class TelegramBotMessenger implements BotMessenger
{
    private const BASE = 'https://api.telegram.org/bot';

    private bool $blocked = false;

    public function __construct(private readonly string $token)
    {
    }

    public function lastSendBlocked(): bool
    {
        return $this->blocked;
    }

    /** 403 «bot was blocked by the user» / «user is deactivated» — клиент недоступен для бота. */
    private function track(array $r): array
    {
        $this->blocked = ! ($r['ok'] ?? false) && (int) ($r['error_code'] ?? 0) === 403;

        return $r;
    }

    public function sendText(string $chatId, string $html, array $buttons = []): bool
    {
        $payload = ['chat_id' => $chatId, 'text' => mb_substr($html, 0, 4096), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        $markup = $this->markup($buttons);
        if ($markup !== null) {
            $payload['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
        }

        $r = $this->call('sendMessage', $payload);
        if (! ($r['ok'] ?? false) && str_contains((string) ($r['description'] ?? ''), 'parse')) {
            // Разметка не разобралась — шлём простым текстом, чем молчать.
            $payload['text'] = html_entity_decode(strip_tags($payload['text']));
            unset($payload['parse_mode']);
            $r = $this->call('sendMessage', $payload);
        }
        $this->track($r);

        return (bool) ($r['ok'] ?? false);
    }

    public function sendDocument(string $chatId, string $bytes, string $filename, ?string $captionHtml = null): bool
    {
        try {
            $r = Http::timeout(60)
                ->retry(3, 2000, fn ($e) => MediaPublisherService::isConnectFailure($e), throw: false)
                ->attach('document', $bytes, $filename)
                ->post(self::BASE.$this->token.'/sendDocument', array_filter([
                    'chat_id' => $chatId,
                    'caption' => $captionHtml !== null ? mb_substr($captionHtml, 0, 1024) : null,
                    'parse_mode' => $captionHtml !== null ? 'HTML' : null,
                ]))->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning('TelegramBotMessenger: document failed', ['error' => MediaPublisherService::redactSecrets($e->getMessage())]);

            return false;
        }
        $this->track($r);

        return (bool) ($r['ok'] ?? false);
    }

    /** getFile → путь → скачивание. Bot API отдаёт файлы до 20 МБ. */
    public function downloadFile(array $file): ?array
    {
        $info = $this->call('getFile', ['file_id' => $file['ref']]);
        $path = (string) ($info['result']['file_path'] ?? '');
        if ($path === '') {
            return null;
        }
        try {
            $r = $this->http()->timeout(60)->get('https://api.telegram.org/file/bot'.$this->token.'/'.$path);
        } catch (\Throwable $e) {
            Log::warning('TelegramBotMessenger: download failed', ['error' => MediaPublisherService::redactSecrets($e->getMessage())]);

            return null;
        }
        if (! $r->successful() || $r->body() === '') {
            return null;
        }

        return [
            'bytes' => $r->body(),
            'name' => (string) ($file['name'] ?: basename($path)),
            'mime' => $file['mime'] ?: ($r->header('Content-Type') ?: null),
        ];
    }

    public function answerCallback(string $callbackId, ?string $notice = null): void
    {
        $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $callbackId, 'text' => $notice]));
    }

    public function me(): array
    {
        $r = $this->call('getMe', []);

        return ($r['ok'] ?? false)
            ? ['ok' => true, 'username' => $r['result']['username'] ?? null, 'message' => 'Telegram отвечает: бот @'.($r['result']['username'] ?? '?').'.']
            : ['ok' => false, 'username' => null, 'message' => 'Telegram: '.($r['description'] ?? 'ключ не принят')];
    }

    public function connect(string $url, string $secret): array
    {
        $r = $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => json_encode(['message', 'callback_query']),
            'drop_pending_updates' => 'true',
        ]);
        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'Telegram: '.($r['description'] ?? 'вебхук не принят')];
        }
        $this->call('setMyCommands', ['commands' => json_encode([
            ['command' => 'start', 'description' => 'Начать'],
            ['command' => 'price', 'description' => 'Узнать цену по артикулу'],
            ['command' => 'request', 'description' => 'Оформить заявку'],
            ['command' => 'profile', 'description' => 'Мои данные'],
        ], JSON_UNESCAPED_UNICODE)]);

        return ['ok' => true, 'message' => 'Бот Telegram подключён: сообщения приходят в mzCorp.'];
    }

    public function disconnect(string $url): array
    {
        $r = $this->call('deleteWebhook', []);

        return ($r['ok'] ?? false) ? ['ok' => true, 'message' => 'Бот Telegram отключён.'] : ['ok' => false, 'message' => 'Telegram: '.($r['description'] ?? 'ошибка')];
    }

    /** @param  list<list<array<string, mixed>>>  $buttons */
    private function markup(array $buttons): ?array
    {
        if ($buttons === []) {
            // Убираем клавиатуру «Отправить номер», если клиент написал номер руками.
            return ['remove_keyboard' => true];
        }
        // «Поделиться телефоном» в Telegram бывает только в обычной клавиатуре.
        $hasContact = collect($buttons)->flatten(1)->contains(fn ($b) => ! empty($b['contact']));
        if ($hasContact) {
            return [
                'keyboard' => array_map(fn ($row) => array_map(
                    fn ($b) => ! empty($b['contact']) ? ['text' => $b['text'], 'request_contact' => true] : ['text' => $b['text']],
                    $row,
                ), $buttons),
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ];
        }

        return ['inline_keyboard' => array_map(fn ($row) => array_map(
            fn ($b) => isset($b['url']) ? ['text' => $b['text'], 'url' => $b['url']] : ['text' => $b['text'], 'callback_data' => mb_substr((string) ($b['data'] ?? ''), 0, 64)],
            $row,
        ), $buttons)];
    }

    /** @return array<string, mixed> */
    private function call(string $method, array $payload): array
    {
        try {
            return $this->http()->asForm()->post(self::BASE.$this->token.'/'.$method, $payload)->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning('TelegramBotMessenger: request failed', ['method' => $method, 'error' => MediaPublisherService::redactSecrets($e->getMessage())]);

            return ['ok' => false, 'description' => 'Telegram не ответил'];
        }
    }

    private function http(): PendingRequest
    {
        return Http::timeout(20)->retry(3, 2000, fn ($e) => MediaPublisherService::isConnectFailure($e), throw: false);
    }
}
