<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Обёртка над OpenAI Chat Completions API.
 *
 * Source: LazyLift @ master, app/Services/OpenAIChatService.php (drop-in copy
 * с переносом в namespace App\Services\AI). Поддерживает прокси через
 * OPENAI_BASE_URL + X-Proxy-Key (актуально для России: api.openai.com
 * заблокирован, нужен reverse-proxy).
 *
 * Возвращает массив { content, usage, raw }.
 */
class OpenAIChatService
{
    /**
     * Отправка запроса к Chat Completions API.
     *
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @return array{content: string, usage: array, raw: array}
     *
     * @throws RuntimeException
     */
    public function chat(array $messages, string $model = 'gpt-4o', array $options = []): array
    {
        $baseUrl = config('services.openai.base_url');
        $apiKey = config('services.openai.api_key');
        $proxyKey = config('services.openai.proxy_key');

        if (empty($apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY not configured');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ];

        if (! empty($proxyKey)) {
            $headers['X-Proxy-Key'] = $proxyKey;
        }

        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
        ], $options);

        try {
            $response = Http::withHeaders($headers)
                ->timeout(120)
                ->retry(3, function (int $attempt, $exception) {
                    // Уважаем Retry-After / x-ratelimit-reset (кап 10с), иначе
                    // экспоненциальный бэкофф от 2с. Раньше фикс 2с игнорировал
                    // подсказку сервера и жёг попытки на 429.
                    return OpenAIRetry::backoffMs($attempt, $exception, 2000, 10000);
                }, function ($exception) {
                    if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                        $status = $exception->response?->status();

                        return in_array($status, [429, 500, 502, 503], true);
                    }

                    return false;
                }, throw: false)
                ->post(rtrim((string) $baseUrl, '/') . '/v1/chat/completions', $payload);

            if (! $response->successful()) {
                throw new RuntimeException(
                    'OpenAI API error: ' . $response->status() . ' - ' . $response->body()
                );
            }

            $data = $response->json();

            return [
                'content' => $data['choices'][0]['message']['content'] ?? '',
                'usage' => $data['usage'] ?? [],
                'raw' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('OpenAI chat completion failed', [
                'error' => $e->getMessage(),
                'model' => $model,
                'messages_count' => count($messages),
            ]);
            throw new RuntimeException('Failed to complete chat: ' . $e->getMessage());
        }
    }

    /**
     * Vision-вызов с одним изображением (Source: LazyLift @ 1ea8147d).
     *
     * @param  string  $imageBase64  data:image/png;base64,XXXX или URL
     * @return array{content: string, usage: array, raw: array}
     */
    public function analyzeImage(string $prompt, string $imageBase64, string $model = 'gpt-4o', array $options = []): array
    {
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageBase64]],
                ],
            ],
        ];

        return $this->chat($messages, $model, $options);
    }

    /**
     * Vision-вызов с несколькими изображениями (Source: LazyLift @ 1ea8147d).
     *
     * @param  array<int, string>  $imagesBase64
     * @return array{content: string, usage: array, raw: array}
     */
    public function analyzeMultipleImages(string $prompt, array $imagesBase64, string $model = 'gpt-4o', array $options = []): array
    {
        $content = [['type' => 'text', 'text' => $prompt]];

        foreach ($imagesBase64 as $imageBase64) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $imageBase64],
            ];
        }

        return $this->chat([['role' => 'user', 'content' => $content]], $model, $options);
    }
}
