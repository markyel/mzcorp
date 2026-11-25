<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Обёртка над OpenAI Embeddings API. Симметричная `OpenAIChatService` —
 * та же логика прокси через `services.openai.base_url` + `X-Proxy-Key`,
 * те же ошибки, тот же стиль логирования.
 *
 * Возвращает float-векторы (нормализованные API-провайдером — для
 * text-embedding-3-* всё уже L2-нормализовано).
 *
 * Используется CatalogEmbeddingService для bulk-индексации catalog_items
 * и запросов на матчинг по name.
 */
class OpenAIEmbeddingService
{
    /**
     * Эмбед одной строки. Возвращает массив float-чисел и usage.
     *
     * @return array{embedding: list<float>, usage: array, raw: array}
     */
    public function embed(string $input, ?string $model = null): array
    {
        $results = $this->embedBatch([$input], $model);

        return [
            'embedding' => $results['embeddings'][0] ?? [],
            'usage' => $results['usage'] ?? [],
            'raw' => $results['raw'] ?? [],
        ];
    }

    /**
     * Эмбед батча строк. Один HTTP-запрос на весь батч (OpenAI поддерживает).
     * Лимит OpenAI — 2048 inputs за запрос, размер тела — 300 KB на input.
     * Здесь не делим автоматически — caller (CatalogEmbeddingService) сам
     * чанкует.
     *
     * @param  list<string>  $inputs
     * @return array{embeddings: list<list<float>>, usage: array, raw: array}
     */
    public function embedBatch(array $inputs, ?string $model = null): array
    {
        if (empty($inputs)) {
            return ['embeddings' => [], 'usage' => [], 'raw' => []];
        }

        $apiKey = config('services.openai.api_key');
        $proxyKey = config('services.openai.proxy_key');
        $model = $model ?: config('services.openai.embedding_model', 'text-embedding-3-small');

        // Основной канал — RouterAI (та же модель OpenAI, векторы совпадают); не ответил — OpenAI, как раньше.
        $viaRouter = $this->viaRouterAi($inputs, (string) $model);
        if ($viaRouter !== null) {
            return $viaRouter;
        }

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

        $payload = [
            'model' => $model,
            'input' => $inputs,
        ];

        // Резервный шлюз — только если там та же модель эмбеддингов (векторы другой модели
        // несовместимы с уже сохранёнными в pgvector): включается OPENAI_FALLBACK_EMBEDDINGS.
        $fallback = app(OpenAIFallbackGateway::class);
        $hasReserve = $fallback->enabled() && config('services.openai.fallback.embeddings');

        // Маршруты по очереди журнала: не ответил сам маршрут — следующий (OpenAIProxyPool).
        [$response, $connectError] = app(OpenAIProxyPool::class)->request(
            fn (OpenAIRoute $route) => $route->http($headers)
                ->timeout(60)
                ->connectTimeout(15)
                ->retry(3, function (int $attempt, $exception) {
                    // Уважаем Retry-After / x-ratelimit-reset (кап 8с), иначе
                    // экспоненциальный бэкофф от 1с. Раньше фикс 500мс игнорировал
                    // подсказку сервера → оба ретрая падали на том же 429.
                    return OpenAIRetry::backoffMs($attempt, $exception, 1000, 8000);
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
                ->post($route->url . '/v1/embeddings', $payload),
        );

        if (($response === null || ! $response->successful()) && $hasReserve
            && OpenAIFallbackGateway::shouldFallback($response, $connectError)) {
            $why = $response !== null ? $response->status().' '.mb_substr($response->body(), 0, 200) : (string) $connectError?->getMessage();
            $response = $fallback->post('/v1/embeddings', $payload, $why, 60);
            $viaFallback = true;
        }
        if ($response === null) {
            throw new RuntimeException('OpenAI embeddings API failed: '.$connectError?->getMessage());
        }

        if (! $response->successful()) {
            Log::error('OpenAIEmbeddingService: HTTP error', [
                'status' => $response->status(),
                'body_excerpt' => mb_substr((string) $response->body(), 0, 500),
                'model' => $model,
                'batch_size' => count($inputs),
            ]);
            throw new RuntimeException(
                'OpenAI embeddings API failed: HTTP ' . $response->status()
            );
        }

        $data = $response->json();
        AiUsageMeter::record((string) ($data['model'] ?? $model), (array) ($data['usage'] ?? []), isset($viaFallback) ? 'reserve' : 'primary');

        return self::parse($data);
    }

    /**
     * Эмбеддинги через RouterAI. Null — выключено, предохранитель RouterAI сработал, ошибка
     * или ответ не на все строки: тогда запрос идёт в OpenAI.
     *
     * @param  list<string>  $inputs
     * @return array{embeddings: list<list<float>>, usage: array, raw: array}|null
     */
    private function viaRouterAi(array $inputs, string $model): ?array
    {
        $router = app(RouterAiGateway::class);
        if (! config('services.routerai.embeddings') || ! $router->enabled() || RouterAiGateway::isDown()) {
            return null;
        }
        $routed = str_contains($model, '/') ? $model : 'openai/'.$model;
        $response = null;
        try {
            $response = RouterAiGateway::client()
                ->timeout(60)
                ->retry(2, fn (int $attempt, $e) => OpenAIRetry::backoffMs($attempt, $e, 1000, 6000), function ($e) {
                    return $e instanceof \Illuminate\Http\Client\RequestException && in_array($e->response?->status(), [429, 500, 502, 503], true);
                }, throw: false)
                ->post(RouterAiGateway::url('/embeddings'), ['model' => $routed, 'input' => $inputs]);
            $data = $response->successful() ? (array) $response->json() : [];
            if (count((array) ($data['data'] ?? [])) === count($inputs)) {
                RouterAiGateway::recordSuccess();
                AiUsageMeter::record($routed, (array) ($data['usage'] ?? []), 'routerai');

                return self::parse($data);
            }
            $why = $response->status().' '.mb_substr($response->body(), 0, 300);
        } catch (\Throwable $e) {
            $why = $e->getMessage();
        }
        RouterAiGateway::recordFailure($response, $why);
        Log::warning('RouterAI embeddings failed, falling back to OpenAI', ['model' => $routed, 'batch_size' => count($inputs), 'error' => mb_substr($why, 0, 300)]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{embeddings: list<list<float>>, usage: array, raw: array}
     */
    private static function parse(array $data): array
    {
        $items = $data['data'] ?? [];
        // API не гарантирует порядок — сортируем по index.
        usort($items, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        $embeddings = array_map(
            fn ($it) => array_map('floatval', $it['embedding'] ?? []),
            $items,
        );

        return [
            'embeddings' => $embeddings,
            'usage' => $data['usage'] ?? [],
            'raw' => $data,
        ];
    }
}
