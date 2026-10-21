<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Счётчики расхода ИИ по дням (ai_usage_daily): модель, кто вызвал, канал, вызовы и токены.
 * Цены — config('services.openai.prices'), $ за 1 млн токенов; итог считает команда ai:usage.
 * Сбой учёта не мешает самому запросу.
 */
class AiUsageMeter
{
    /** @param  array<string, mixed>  $usage  блок usage ответа API */
    public static function record(string $model, array $usage, string $channel = 'primary'): void
    {
        // Тесты с подменой ответа OpenAI не должны попадать в расход (локальная база — боевая).
        if (app()->runningUnitTests()) {
            return;
        }
        try {
            $prompt = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
            $completion = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
            $cached = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
            $now = now();

            DB::statement(
                'INSERT INTO ai_usage_daily (day, model, source, channel, calls, prompt_tokens, completion_tokens, cached_tokens, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)
                 ON CONFLICT (day, model, source, channel) DO UPDATE SET
                    calls = ai_usage_daily.calls + 1,
                    prompt_tokens = ai_usage_daily.prompt_tokens + EXCLUDED.prompt_tokens,
                    completion_tokens = ai_usage_daily.completion_tokens + EXCLUDED.completion_tokens,
                    cached_tokens = ai_usage_daily.cached_tokens + EXCLUDED.cached_tokens,
                    updated_at = EXCLUDED.updated_at',
                [$now->toDateString(), mb_substr($model, 0, 64), self::caller(), $channel, $prompt, $completion, $cached, $now, $now],
            );
        } catch (Throwable $e) {
            Log::debug('AiUsageMeter: not recorded', ['error' => $e->getMessage()]);
        }
    }

    /** Кто вызвал: первый класс вне App\Services\AI (короткое имя). */
    private static function caller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $class = (string) ($frame['class'] ?? '');
            if ($class !== '' && ! str_starts_with($class, 'App\\Services\\AI\\') && str_starts_with($class, 'App\\')) {
                return mb_substr(class_basename($class), 0, 120);
            }
        }

        return 'other';
    }

    /** Стоимость в $ по ценам из конфига; неизвестная модель — null. */
    public static function cost(string $model, int $prompt, int $completion, int $cached = 0): ?float
    {
        $prices = (array) config('services.openai.prices', []);
        $p = $prices[$model] ?? null;
        // Версия модели — по самому длинному известному названию: gpt-4o-mini-2024-07-18 → gpt-4o-mini, не gpt-4o.
        $names = array_keys($prices);
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($names as $name) {
            if ($p === null && str_starts_with($model, $name.'-')) {
                $p = $prices[$name];
            }
        }
        if (! is_array($p)) {
            return null;
        }
        $in = (float) ($p[0] ?? 0);
        $out = (float) ($p[1] ?? 0);
        $cachedIn = (float) ($p[2] ?? $in / 2);

        return (($prompt - $cached) * $in + $cached * $cachedIn + $completion * $out) / 1_000_000;
    }
}
