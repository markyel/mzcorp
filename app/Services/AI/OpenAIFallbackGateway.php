<?php

namespace App\Services\AI;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Резервный шлюз к моделям (Beget AI Gateway, OpenAI-совместимый): когда основной канал
 * OpenAI недоступен — кончились деньги (21.10.2026: insufficient_quota с полуночи, весь ИИ
 * встал), ключ отозван, сервис перегружен или не отвечает, — тот же запрос уходит через
 * шлюз. Ошибка в самом запросе (400) не переключает: шлюз ответил бы так же.
 *
 * Включается ключом OPENAI_FALLBACK_API_KEY; названия моделей у шлюза — через
 * OPENAI_FALLBACK_MODEL_PREFIX / OPENAI_FALLBACK_MODELS (JSON «наша → у шлюза»).
 */
class OpenAIFallbackGateway
{
    /** Статусы основного канала, при которых идём в резерв. */
    public const FALLBACK_STATUSES = [401, 402, 403, 404, 408, 409, 429, 500, 502, 503, 504];

    /** Шлюз Beget отклоняет запрос без max_tokens, если оценка стоимости больше баланса. */
    private const DEFAULT_MAX_TOKENS = 4096;

    public function enabled(): bool
    {
        return trim((string) config('services.openai.fallback.api_key')) !== '';
    }

    /** Идти ли в резерв: основной канал не ответил или ответил ошибкой доступности. */
    public static function shouldFallback(?Response $response, ?Throwable $error = null): bool
    {
        if ($response === null) {
            return $error !== null; // нет соединения, таймаут
        }

        return in_array($response->status(), self::FALLBACK_STATUSES, true);
    }

    /** Название модели у шлюза: явная замена, иначе префикс провайдера. */
    public static function modelFor(string $model): string
    {
        $map = config('services.openai.fallback.models');
        $map = is_array($map) ? $map : (json_decode((string) $map, true) ?: []);
        if (isset($map[$model])) {
            return (string) $map[$model];
        }

        return self::byDefault($model);
    }

    /** Без явной замены: префикс провайдера, иначе модель по умолчанию (шлюз не знает наших старых названий). */
    private static function byDefault(string $model): string
    {
        $prefix = (string) config('services.openai.fallback.model_prefix', '');
        if ($prefix !== '') {
            return $prefix.$model;
        }

        return (string) (config('services.openai.fallback.default_model') ?: $model);
    }

    /**
     * Тот же запрос через шлюз (path — «/v1/chat/completions», «/v1/embeddings»).
     *
     * @param  array<string, mixed>  $payload
     */
    public function post(string $path, array $payload, string $why, int $timeout = 120): Response
    {
        $payload['model'] = self::modelFor((string) ($payload['model'] ?? ''));
        if ($path === '/v1/chat/completions' && ! isset($payload['max_tokens']) && ! isset($payload['max_completion_tokens'])) {
            $payload['max_tokens'] = self::DEFAULT_MAX_TOKENS;
        }

        // Раз в час — в лог как ошибка: основной канал лежит, работаем на резерве (за деньги Beget).
        if (Cache::add('openai:fallback:noticed', now()->toIso8601String(), now()->addHour())) {
            Log::error('OpenAI primary unavailable — requests go through the fallback gateway', ['why' => mb_substr($why, 0, 300)]);
        }

        return Http::withHeaders([
            'Authorization' => 'Bearer '.config('services.openai.fallback.api_key'),
            'Content-Type' => 'application/json',
        ])->timeout($timeout)
            ->retry(2, fn (int $attempt) => 2000 * $attempt, fn ($e) => $e instanceof \Illuminate\Http\Client\RequestException
                && in_array($e->response?->status(), [429, 500, 502, 503], true), throw: false)
            ->post(rtrim((string) config('services.openai.fallback.base_url'), '/').$path, $payload);
    }
}
