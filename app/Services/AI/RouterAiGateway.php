<?php

namespace App\Services\AI;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * RouterAI (routerai.ru) — основной канал моделей (16.11.2026): OpenAI-совместимый API с оплатой в рублях,
 * под одним ключом Qwen, Gemini, GPT и др. Модель — см. modelFor(). Не ответил, ответил ошибкой или пустым ответом — OpenAIChatService шлёт тот же
 * запрос напрямую в OpenAI, как раньше.
 *
 * Включается ROUTERAI_ENABLED и ключом ROUTERAI_API_KEY.
 */
class RouterAiGateway
{
    public function enabled(): bool
    {
        return (bool) config('services.routerai.enabled') && trim((string) config('services.routerai.api_key')) !== '';
    }

    /**
     * Название модели у RouterAI: замена по задаче (класс, вызвавший модель — «RequestItemParsingService»),
     * затем по нашей модели, затем модель по умолчанию; не задана — та же модель OpenAI (gpt-4o → openai/gpt-4o).
     * По задаче — потому что одна gpt-4o разбирает и позиции заявки, и цены поставщика.
     */
    public static function modelFor(string $model, ?string $caller = null): string
    {
        $byCaller = self::map('services.routerai.by_caller');
        $caller ??= AiUsageMeter::caller();
        if (isset($byCaller[$caller])) {
            return (string) $byCaller[$caller];
        }
        $map = self::map('services.routerai.models');
        if (isset($map[$model])) {
            return (string) $map[$model];
        }
        $default = trim((string) config('services.routerai.default_model'));

        return $default !== '' ? $default : (str_contains($model, '/') ? $model : 'openai/'.$model);
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
        $payload['model'] = self::modelFor((string) ($payload['model'] ?? ''));
        // Рассуждения выключены (ROUTERAI_REASONING): Qwen с ними разбирал письмо 20–270 с вместо 2 с,
        // на разборе позиций они не нужны.
        if (! config('services.routerai.reasoning') && ! isset($payload['reasoning'])) {
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

        return Http::withToken((string) config('services.routerai.api_key'))
            ->acceptJson()
            ->timeout(max(10, (int) config('services.routerai.timeout', 120)))
            ->retry(2, fn (int $attempt, $e) => OpenAIRetry::backoffMs($attempt, $e, 1500, 6000), function ($e) {
                return $e instanceof \Illuminate\Http\Client\RequestException && in_array($e->response?->status(), [429, 500, 502, 503], true);
            }, throw: false)
            ->post(rtrim((string) config('services.routerai.base_url'), '/').'/chat/completions', $payload);
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
