<?php

namespace App\Services\Bot;

use App\Services\Marketing\MediaPublisherService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MAX Bot API для бота заявок: та же база, ключ в заголовке Authorization и
 * сертификат Минцифры только для запросов к MAX, что и в медиаплане.
 */
class MaxBotMessenger implements BotMessenger
{
    public function __construct(private readonly string $token)
    {
    }

    public function sendText(string $chatId, string $html, array $buttons = []): bool
    {
        $body = ['text' => mb_substr($html, 0, 4000), 'format' => 'html'];
        if ($buttons !== []) {
            $body['attachments'] = [[
                'type' => 'inline_keyboard',
                'payload' => ['buttons' => array_map(fn ($row) => array_map(fn ($b) => match (true) {
                    ! empty($b['contact']) => ['type' => 'request_contact', 'text' => $b['text']],
                    isset($b['url']) => ['type' => 'link', 'text' => $b['text'], 'url' => $b['url']],
                    default => ['type' => 'callback', 'text' => $b['text'], 'payload' => (string) ($b['data'] ?? '')],
                }, $row), $buttons)],
            ]];
        }

        try {
            $r = $this->http()->post($this->api().'/messages?'.http_build_query(['chat_id' => $chatId, 'disable_link_preview' => 'true']), $body);
            if (! $r->successful()) {
                Log::warning('MaxBotMessenger: send failed', ['chat_id' => $chatId, 'status' => $r->status(), 'error' => $r->json('message')]);
            }

            return $r->successful();
        } catch (\Throwable $e) {
            Log::warning('MaxBotMessenger: send error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Файл в MAX: слот загрузки (POST /uploads?type=file) → multipart «data» →
     * токен → сообщение с вложением. Файл обрабатывается не мгновенно —
     * «attachment.not.ready» повторяем с паузой, как картинки в медиаплане.
     */
    public function sendDocument(string $chatId, string $bytes, string $filename, ?string $captionHtml = null): bool
    {
        try {
            $slot = $this->http()->post($this->api().'/uploads?type=file');
            $url = (string) ($slot->json('url') ?? '');
            if (! $slot->successful() || $url === '') {
                return false;
            }
            $up = Http::timeout(60)->withOptions(['verify' => MediaPublisherService::maxVerify()])
                ->withHeaders(['Authorization' => $this->token])
                ->attach('data', $bytes, $filename)->post($url);
            $token = MediaPublisherService::maxUploadToken((array) ($up->json() ?? []), (array) ($slot->json() ?? []));
            if (! $up->successful() || $token === null) {
                return false;
            }

            $body = ['text' => $captionHtml !== null ? mb_substr($captionHtml, 0, 4000) : '', 'format' => 'html',
                'attachments' => [['type' => 'file', 'payload' => ['token' => $token]]]];
            foreach ([0, 2, 4, 6] as $pause) {
                if ($pause > 0) {
                    sleep($pause);
                }
                $r = $this->http()->post($this->api().'/messages?'.http_build_query(['chat_id' => $chatId]), $body);
                if ($r->successful()) {
                    return true;
                }
                if ((string) $r->json('code') !== 'attachment.not.ready') {
                    Log::warning('MaxBotMessenger: document send failed', ['chat_id' => $chatId, 'error' => $r->json('message')]);

                    return false;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MaxBotMessenger: document error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
        }

        return false;
    }

    public function answerCallback(string $callbackId, ?string $notice = null): void
    {
        try {
            $this->http()->post($this->api().'/answers?'.http_build_query(['callback_id' => $callbackId]),
                $notice !== null ? ['notification' => $notice] : ['notification' => ' ']);
        } catch (\Throwable) {
            // Кнопка «крутится» чуть дольше — не повод ронять обработку.
        }
    }

    public function me(): array
    {
        try {
            $r = $this->http()->get($this->api().'/me');
        } catch (\Throwable $e) {
            return ['ok' => false, 'username' => null, 'message' => 'MAX не ответил: '.$e->getMessage()];
        }

        return $r->successful()
            ? ['ok' => true, 'username' => $r->json('username'), 'message' => 'MAX отвечает: бот «'.($r->json('name') ?? $r->json('username')).'».']
            : ['ok' => false, 'username' => null, 'message' => 'MAX: '.($r->json('message') ?? 'ключ не принят')];
    }

    public function connect(string $url, string $secret): array
    {
        try {
            $r = $this->http()->post($this->api().'/subscriptions', [
                'url' => $url,
                'update_types' => ['message_created', 'message_callback', 'bot_started'],
                'secret' => $secret,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'MAX не ответил: '.$e->getMessage()];
        }
        if (! $r->successful() || $r->json('success') === false) {
            return ['ok' => false, 'message' => 'MAX: '.($r->json('message') ?? 'подписка не принята')];
        }

        return ['ok' => true, 'message' => 'Бот MAX подключён: сообщения приходят в mzCorp.'];
    }

    public function disconnect(string $url): array
    {
        try {
            $r = $this->http()->delete($this->api().'/subscriptions?'.http_build_query(['url' => $url]));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'MAX не ответил: '.$e->getMessage()];
        }

        return $r->successful() ? ['ok' => true, 'message' => 'Бот MAX отключён.'] : ['ok' => false, 'message' => 'MAX: '.($r->json('message') ?? 'ошибка')];
    }

    private function api(): string
    {
        return rtrim((string) config('services.max.api_base', 'https://platform-api2.max.ru'), '/');
    }

    private function http(): PendingRequest
    {
        return Http::timeout(20)->acceptJson()
            ->retry(3, 2000, fn ($e) => MediaPublisherService::isConnectFailure($e), throw: false)
            ->withOptions(['verify' => MediaPublisherService::maxVerify()])
            ->withHeaders(['Authorization' => $this->token]);
    }
}
