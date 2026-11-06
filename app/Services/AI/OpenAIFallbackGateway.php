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

    private const MIN_MAX_TOKENS = 1000;

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
     * Запрос прямо в шлюз (не резерв): модели, которых нет у основного канала, — посты медиаплана
     * пишет gpt-6.1-sol (решение 2026-10-22: связнее gpt-4o, ~2 ₽ за пост). Ответ — как у
     * OpenAIChatService::chat; расход — в ai_usage_daily (канал «gateway»).
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options  response_format и т.п.; temperature моделям шлюза не передаём
     * @return array{content: string, usage: array<string, mixed>, raw: array<string, mixed>}
     */
    public function chat(array $messages, string $model, array $options = [], int $timeout = 180): array
    {
        unset($options['temperature']);
        $payload = ['model' => $model, 'messages' => $messages] + $options;
        // Рассуждающей модели нужен запас: при малом лимите ответ пустой; платим только за потраченное.
        if (! isset($payload['max_tokens']) && ! isset($payload['max_completion_tokens'])) {
            $payload['max_completion_tokens'] = 8000;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.config('services.openai.fallback.api_key'),
            'Content-Type' => 'application/json',
        ])->timeout($timeout)->post(rtrim((string) config('services.openai.fallback.base_url'), '/').'/v1/chat/completions', $payload);
        if ($response->failed()) {
            throw new \RuntimeException('AI gateway: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300));
        }
        $data = (array) $response->json();
        AiUsageMeter::record((string) ($data['model'] ?? $model), (array) ($data['usage'] ?? []), 'gateway');

        return ['content' => (string) data_get($data, 'choices.0.message.content', ''), 'usage' => (array) ($data['usage'] ?? []), 'raw' => $data];
    }

    /**
     * Тот же запрос через шлюз (path — «/v1/chat/completions», «/v1/embeddings»).
     *
     * @param  array<string, mixed>  $payload
     */
    public function post(string $path, array $payload, string $why, int $timeout = 120): Response
    {
        // Чатовые модели — по замене / модели по умолчанию; эмбеддинги — под своим названием
        // (у шлюза та же text-embedding-3-small; чатовая модель вместо неё — 400).
        $payload['model'] = $path === '/v1/chat/completions'
            ? self::modelFor((string) ($payload['model'] ?? ''))
            : (string) ($payload['model'] ?? '');
        // Лимит ответа — не меньше MIN_MAX_TOKENS: дешёвые модели шлюза (gpt-6-luna) сначала рассуждают
        // и при лимите в 20 токенов возвращали пустой ответ; платим только за потраченное.
        if ($path === '/v1/chat/completions' && ! isset($payload['max_completion_tokens'])) {
            // Минимум — из настроек: рассуждающим моделям Reg.cloud (deepseek-v4.1-flash) нужно не меньше 1500.
            $min = max(self::MIN_MAX_TOKENS, (int) config('services.openai.fallback.min_max_tokens', self::MIN_MAX_TOKENS));
            $payload['max_tokens'] = isset($payload['max_tokens']) ? max((int) $payload['max_tokens'], $min) : max(self::DEFAULT_MAX_TOKENS, $min);
        }

        // Раз в час — в лог как ошибка: основной канал лежит, работаем на резерве (за деньги Beget).
        if (Cache::add('openai:fallback:noticed', now()->toIso8601String(), now()->addHour())) {
            Log::error('OpenAI primary unavailable — requests go through the fallback gateway', ['why' => mb_substr($why, 0, 300)]);
        }

        // Резерв принимает не больше N картинок в запросе (Reg.cloud — одну: «Too many images: at most
        // 1 image(s) per request», скан PDF на 10 позиций не разбирался вовсе). Шлём по картинке с тем же
        // текстом и складываем ответы (mergeJsonAnswers).
        $maxImages = max(1, (int) config('services.openai.fallback.max_images', 1));
        if ($path === '/v1/chat/completions' && self::imageCount($payload) > $maxImages) {
            return $this->postImagesOneByOne($payload, $timeout);
        }

        return $this->send($path, $payload, $timeout);
    }

    /** @param  array<string, mixed>  $payload */
    private function send(string $path, array $payload, int $timeout): Response
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.config('services.openai.fallback.api_key'),
            'Content-Type' => 'application/json',
        ])->timeout($timeout)
            ->retry(2, fn (int $attempt) => 2000 * $attempt, fn ($e) => $e instanceof \Illuminate\Http\Client\RequestException
                && in_array($e->response?->status(), [429, 500, 502, 503], true), throw: false)
            ->post(rtrim((string) config('services.openai.fallback.base_url'), '/').$path, $payload);
    }

    /** Больше картинок за один разбор не шлём: лимит площадки — 20 запросов в минуту на аккаунт. */
    private const MAX_IMAGE_REQUESTS = 10;

    /** @param  array<string, mixed>  $payload */
    public static function imageCount(array $payload): int
    {
        $n = 0;
        foreach ((array) ($payload['messages'] ?? []) as $message) {
            foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $part) {
                $n += (($part['type'] ?? null) === 'image_url') ? 1 : 0;
            }
        }

        return $n;
    }

    /**
     * Запрос с несколькими картинками — несколькими запросами по одной (текст тот же), ответы
     * складываются. Ответ — как от шлюза: первый успешный, с объединённым содержимым и расходом.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postImagesOneByOne(array $payload, int $timeout): Response
    {
        $total = self::imageCount($payload);
        $answers = [];
        $first = null;
        $usage = [];
        for ($keep = 0; $keep < min($total, self::MAX_IMAGE_REQUESTS); $keep++) {
            $one = $payload;
            $seen = 0;
            foreach ($one['messages'] as $m => $message) {
                if (! is_array($message['content'] ?? null)) {
                    continue;
                }
                $one['messages'][$m]['content'] = array_values(array_filter($message['content'], function ($part) use (&$seen, $keep) {
                    if (($part['type'] ?? null) !== 'image_url') {
                        return true;
                    }

                    return $seen++ === $keep;
                }));
            }
            $response = $this->send('/v1/chat/completions', $one, $timeout);
            if (! $response->successful()) {
                Log::warning('OpenAI fallback: image request failed', ['image' => $keep + 1, 'of' => $total, 'status' => $response->status()]);

                continue;
            }
            $data = (array) $response->json();
            $first ??= $data;
            $answers[] = (string) data_get($data, 'choices.0.message.content', '');
            foreach ((array) ($data['usage'] ?? []) as $k => $v) {
                $usage[$k] = is_numeric($v) ? ($usage[$k] ?? 0) + $v : $v;
            }
        }
        if ($total > self::MAX_IMAGE_REQUESTS) {
            Log::warning('OpenAI fallback: too many images, the rest skipped', ['images' => $total, 'sent' => self::MAX_IMAGE_REQUESTS]);
        }
        if ($first === null) {
            return $this->send('/v1/chat/completions', $payload, $timeout); // ни одной удачной — вернём честную ошибку площадки
        }
        data_set($first, 'choices.0.message.content', self::mergeJsonAnswers($answers));
        $first['usage'] = $usage;

        return new Response(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($first, JSON_UNESCAPED_UNICODE)));
    }

    /**
     * Ответы по отдельным картинкам — в один: если все JSON-объекты, списки под одинаковыми ключами
     * объединяются («items» с каждой картинки), прочие поля — из первого ответа, где они есть;
     * иначе тексты через пустую строку.
     *
     * @param  list<string>  $answers
     */
    public static function mergeJsonAnswers(array $answers): string
    {
        $decoded = array_map(fn ($a) => json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $a)), true), $answers);
        if ($decoded === [] || in_array(null, array_map(fn ($d) => is_array($d) && ! array_is_list($d) ? $d : null, $decoded), true)) {
            return implode("\n\n", array_filter(array_map('trim', $answers)));
        }
        $merged = [];
        foreach ($decoded as $d) {
            foreach ($d as $key => $value) {
                if (! array_key_exists($key, $merged)) {
                    $merged[$key] = $value;
                } elseif (is_array($merged[$key]) && array_is_list($merged[$key]) && is_array($value) && array_is_list($value)) {
                    $merged[$key] = array_merge($merged[$key], $value);
                }
            }
        }

        return json_encode($merged, JSON_UNESCAPED_UNICODE);
    }
}
