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
        // Основной канал — RouterAI, если включён; не ответил — тот же запрос напрямую в OpenAI (ниже).
        $router = app(RouterAiGateway::class);
        // Сравнение моделей идёт мимо предохранителя: кандидат не должен ни пропускаться, ни отключать RouterAI для прода.
        $comparing = (bool) config('services.routerai.no_fallback');
        if ($router->enabled() && ($comparing || ! RouterAiGateway::isDown())) {
            $routed = array_merge(['model' => $model, 'messages' => $messages], $options);
            try {
                $response = $router->post($routed);
            } catch (\Throwable $e) {
                $response = null;
                $why = $e->getMessage();
            }
            if (! RouterAiGateway::shouldFallback($response)) {
                $comparing || RouterAiGateway::recordSuccess();
                $data = $response->json();
                // Модель — как запрашивали (google/…): RouterAI отвечает без префикса, а цены — с ним.
                AiUsageMeter::record(RouterAiGateway::modelFor($model), (array) ($data['usage'] ?? []), 'routerai');
                self::tally(RouterAiGateway::modelFor($model), (array) ($data['usage'] ?? []));
                LlmCapture::record(RouterAiGateway::modelFor($model), $messages, $options, (string) ($data['choices'][0]['message']['content'] ?? ''));

                return [
                    'content' => $data['choices'][0]['message']['content'] ?? '',
                    'usage' => $data['usage'] ?? [],
                    'raw' => $data,
                ];
            }
            $error = $why ?? ($response !== null ? $response->status().' '.mb_substr($response->body(), 0, 300) : 'no response');
            // Сравнение моделей (llm:compare-parsing): ответ GPT вместо кандидата смешал бы результаты.
            if ($comparing) {
                self::$errors[] = mb_substr($error, 0, 200);
                throw new RuntimeException('RouterAI: '.$error);
            }
            RouterAiGateway::recordFailure($response, $error);
            Log::warning('RouterAI failed, falling back to OpenAI', [
                'model' => $model,
                'routed_model' => RouterAiGateway::modelFor($model),
                'error' => $error,
            ]);
        }

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
            $fallback = app(OpenAIFallbackGateway::class);
            // Маршруты по очереди журнала: не ответил сам маршрут — следующий (OpenAIProxyPool).
            [$response, $connectError] = app(OpenAIProxyPool::class)->request(
                fn (OpenAIRoute $route) => $route->http($headers)
                    ->timeout(120)
                    ->retry(3, function (int $attempt, $exception) {
                        // Уважаем Retry-After / x-ratelimit-reset (кап 10с), иначе
                        // экспоненциальный бэкофф от 2с. Раньше фикс 2с игнорировал
                        // подсказку сервера и жёг попытки на 429.
                        return OpenAIRetry::backoffMs($attempt, $exception, 2000, 10000);
                    }, function ($exception) {
                        if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                            $status = $exception->response?->status();
                            // Нет денег на счёте — повтором не лечится: сразу резерв (или ошибка).
                            if ($status === 429 && str_contains((string) $exception->response?->body(), 'insufficient_quota')) {
                                return false;
                            }

                            return in_array($status, [429, 500, 502, 503], true);
                        }

                        return false;
                    }, throw: false)
                    ->post($route->url . '/v1/chat/completions', $payload),
            );

            // Основной канал недоступен (нет денег, ключ, перегрузка, нет связи) — резервный шлюз.
            if (($response === null || ! $response->successful()) && $fallback->enabled()
                && OpenAIFallbackGateway::shouldFallback($response, $connectError)) {
                $why = $response !== null ? $response->status().' '.mb_substr($response->body(), 0, 200) : (string) $connectError?->getMessage();
                $response = $fallback->post('/v1/chat/completions', $payload, $why);
                $viaFallback = true;
            }
            if ($response === null) {
                throw $connectError;
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    'OpenAI API error: ' . $response->status() . ' - ' . $response->body()
                );
            }

            $data = $response->json();
            AiUsageMeter::record((string) ($data['model'] ?? $model), (array) ($data['usage'] ?? []), isset($viaFallback) ? 'reserve' : 'primary');
            self::tally((string) ($data['model'] ?? $model), (array) ($data['usage'] ?? []));
            LlmCapture::record((string) ($data['model'] ?? $model), $messages, $options, (string) ($data['choices'][0]['message']['content'] ?? ''));

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
     * Токены за время жизни процесса по моделям — для сравнения моделей (llm:compare-parsing).
     *
     * @var array<string, array{calls:int, prompt:int, completion:int}>
     */
    public static array $tally = [];

    /** @var list<string> ошибки кандидата при сравнении (без переключения на OpenAI) */
    public static array $errors = [];

    private static function tally(string $model, array $usage): void
    {
        $t = self::$tally[$model] ?? ['calls' => 0, 'prompt' => 0, 'completion' => 0];
        $t['calls']++;
        $t['prompt'] += (int) ($usage['prompt_tokens'] ?? 0);
        $t['completion'] += (int) ($usage['completion_tokens'] ?? 0);
        self::$tally[$model] = $t;
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
