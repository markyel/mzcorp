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

        $baseUrl = config('services.openai.base_url');
        $apiKey = config('services.openai.api_key');
        $proxyKey = config('services.openai.proxy_key');
        $model = $model ?: config('services.openai.embedding_model', 'text-embedding-3-small');

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

        $url = rtrim((string) $baseUrl, '/') . '/v1/embeddings';

        $response = Http::withHeaders($headers)
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

                    return in_array($status, [429, 500, 502, 503], true);
                }

                return false;
            }, throw: false)
            ->post($url, $payload);

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
