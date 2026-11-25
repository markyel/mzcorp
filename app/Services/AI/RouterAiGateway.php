<?php

namespace App\Services\AI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RouterAI (routerai.ru) — основной канал моделей (16.11.2026): OpenAI-совместимый API с оплатой в рублях,
 * под одним ключом Qwen, Gemini, GPT и др. Модель — см. modelFor(). Не ответил, ответил ошибкой или пустым
 * ответом — OpenAIChatService шлёт тот же запрос напрямую в OpenAI, как раньше.
 *
 * Предохранитель: RouterAI лежит (нет соединения / таймаут — сразу, 5xx/429 — два раза подряд) — следующие
 * ROUTERAI_DOWN_MINUTES минут запросы идут сразу в OpenAI, без ожидания; раз в час — ошибка в лог.
 * Ошибки отдельного запроса (400, пустой ответ модели) предохранитель не трогают.
 *
 * Включается ROUTERAI_ENABLED и ключом ROUTERAI_API_KEY.
 */
class RouterAiGateway
{
    private const DOWN_KEY = 'routerai:down';

    private const FAILURES_KEY = 'routerai:failures';

    public function enabled(): bool
    {
        return (bool) config('services.routerai.enabled') && trim((string) config('services.routerai.api_key')) !== '';
    }

    /** Предохранитель сработал — RouterAI пропускаем до истечения паузы. */
    public static function isDown(): bool
    {
        try {
            return Cache::has(self::DOWN_KEY);
        } catch (Throwable) {
            return false;
        }
    }

    public static function recordSuccess(): void
    {
        try {
            Cache::forget(self::FAILURES_KEY);
        } catch (Throwable) {
        }
    }

    /**
     * Сбой RouterAI: нет ответа (соединение, таймаут) — пауза сразу; 5xx/429 — со второго подряд.
     * Прочие ошибки (400 «модель не принимает параметр», пустой ответ) — проблема запроса, не канала.
     */
    public static function recordFailure(?Response $response, string $why): void
    {
        try {
            if ($response !== null && ! ($response->serverError() || $response->status() === 429)) {
                return;
            }
            if ($response !== null) {
                $failures = (int) Cache::get(self::FAILURES_KEY, 0) + 1;
                Cache::put(self::FAILURES_KEY, $failures, now()->addMinutes(10));
                if ($failures < 2) {
                    return;
                }
            }
            $minutes = max(1, (int) config('services.routerai.down_minutes', 5));
            Cache::put(self::DOWN_KEY, now()->toIso8601String(), now()->addMinutes($minutes));
            Cache::forget(self::FAILURES_KEY);
            if (Cache::add('routerai:down:noticed', now()->toIso8601String(), now()->addHour())) {
                Log::error('RouterAI unavailable — requests go straight to OpenAI', ['minutes' => $minutes, 'why' => mb_substr($why, 0, 300)]);
            }
        } catch (Throwable) {
        }
    }

    /**
     * Название модели у RouterAI: замена по задаче (класс, вызвавший модель — «RequestItemParsingService»),
     * затем по нашей модели, затем модель по умолчанию; не задана — та же модель OpenAI (gpt-4o → openai/gpt-4o).
     * По задаче — потому что одна gpt-4o разбирает и позиции заявки, и цены поставщика.
     * Запрос с картинкой — модель чтения фото (services.routerai.vision_model), если задана: на 40 фото позиций DeepSeek
     * V4.1 Flash читала маркировку лучше Qwen 3.6 и втрое дешевле (25.11.2026, MZ2026-02962: KLE-MAU-02B прочитано как KLE-HAU-028).
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public static function modelFor(string $model, ?string $caller = null, array $messages = []): string
    {
        // ROUTERAI_BY_CALLER поверх замен по умолчанию (caller_defaults: посты медиаплана — своя модель).
        $byCaller = self::map('services.routerai.by_caller') + array_filter((array) config('services.routerai.caller_defaults', []));
        $caller ??= AiUsageMeter::caller();
        if (isset($byCaller[$caller])) {
            return (string) $byCaller[$caller];
        }
        $vision = trim((string) config('services.routerai.vision_model', ''));
        if ($vision !== '' && self::hasImage($messages)) {
            return $vision;
        }
        $map = self::map('services.routerai.models');
        if (isset($map[$model])) {
            return (string) $map[$model];
        }
        $default = trim((string) config('services.routerai.default_model'));

        return $default !== '' ? $default : (str_contains($model, '/') ? $model : 'openai/'.$model);
    }

    /**
     * Модель запроса у RouterAI: заданная вызывающим (options.routerai_model — сверка фото двумя моделями,
     * RequestItemParsingService::visionChat), иначе по правилам modelFor.
     *
     * @param  array<string, mixed>  $options  опции запроса (или всё тело с messages)
     */
    public static function routedModel(string $model, array $options, array $messages = []): string
    {
        $explicit = trim((string) ($options['routerai_model'] ?? ''));

        return $explicit !== '' ? $explicit : self::modelFor($model, null, $messages ?: (array) ($options['messages'] ?? []));
    }

    /** @param  list<array<string, mixed>>  $messages */
    public static function hasImage(array $messages): bool
    {
        foreach ($messages as $m) {
            foreach ((array) ($m['content'] ?? []) as $part) {
                if (is_array($part) && (($part['type'] ?? null) === 'image_url' || isset($part['image_url']))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private static function map(string $key): array
    {
        $map = config($key);

        return is_array($map) ? $map : (json_decode((string) $map, true) ?: []);
    }

    /**
     * @param  array<string, mixed>  $payload  тело chat/completions с НАШИМ названием модели
     *
     * @throws Throwable нет соединения / таймаут
     */
    public function post(array $payload): Response
    {
        return self::client()
            ->retry(2, fn (int $attempt, $e) => OpenAIRetry::backoffMs($attempt, $e, 1500, 6000), function ($e) {
                return $e instanceof \Illuminate\Http\Client\RequestException && in_array($e->response?->status(), [429, 500, 502, 503], true);
            }, throw: false)
            ->post(self::url('/chat/completions'), self::prepare($payload));
    }

    /**
     * Ключ и таймауты RouterAI на запросе; $request — запрос из Http::pool (параллельные пачки).
     * Соединение — быстро: лежащий RouterAI не должен держать запрос; ответ модели — долго (фото — 15 с+).
     */
    public static function client(?PendingRequest $request = null): PendingRequest
    {
        return ($request ?? Http::createPendingRequest())
            ->withToken((string) config('services.routerai.api_key'))
            ->acceptJson()
            ->connectTimeout(max(1, (int) config('services.routerai.connect_timeout', 5)))
            ->timeout(max(10, (int) config('services.routerai.timeout', 120)));
    }

    public static function url(string $path): string
    {
        return rtrim((string) config('services.routerai.base_url'), '/').$path;
    }

    /**
     * Тело chat/completions для RouterAI: наша модель → модель RouterAI, рассуждения выключены, нижний предел max_tokens.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function prepare(array $payload): array
    {
        $payload['model'] = self::routedModel((string) ($payload['model'] ?? ''), $payload);
        unset($payload['routerai_model']);
        // Рассуждения выключены (ROUTERAI_REASONING): Qwen с ними разбирал письмо 20–270 с вместо 2 с,
        // на разборе позиций они не нужны.
        // GPT (openai/…) параметр не принимает: RouterAI шлёт его как reasoning_effort, OpenAI отвечает 400,
        // а RouterAI — «200» с ошибкой в теле, и запрос уходил в откат на прямой OpenAI.
        if (! config('services.routerai.reasoning') && ! isset($payload['reasoning']) && ! str_starts_with($payload['model'], 'openai/')) {
            $payload['reasoning'] = ['enabled' => false];
        }
        // Рассуждающие модели (Qwen) при лимите в 20–200 токенов тратят его на рассуждение и отвечают пусто.
        $min = (int) config('services.routerai.min_max_tokens', 1500);
        if (isset($payload['max_tokens'])) {
            $payload['max_tokens'] = max((int) $payload['max_tokens'], $min);
        }
        if (isset($payload['max_completion_tokens'])) {
            $payload['max_completion_tokens'] = max((int) $payload['max_completion_tokens'], $min);
        }

        return $payload;
    }

    /**
     * Остаток на счёте RouterAI, ₽ (GET /credits). Null — не ответил.
     */
    public function credits(): ?float
    {
        try {
            $response = self::client()->timeout(20)->get(self::url('/credits'));
            $credits = $response->successful() ? $response->json('data.credits') : null;

            return is_numeric($credits) ? (float) $credits : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Переключаться ли на OpenAI: нет ответа, любая ошибка (400 у RouterAI бывает «модель не поддерживает
     * параметр» — OpenAI тот же запрос выполнит) или пустой ответ модели.
     */
    public static function shouldFallback(?Response $response): bool
    {
        return $response === null || ! $response->successful()
            || trim((string) ($response->json('choices.0.message.content') ?? '')) === '';
    }
}
