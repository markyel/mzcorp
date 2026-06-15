<?php

namespace App\Services\AI;

use Illuminate\Http\Client\RequestException;

/**
 * Стратегия задержки между ретраями к OpenAI.
 *
 * Используется как `$sleepMilliseconds`-замыкание в Http::retry() сервисов
 * OpenAIChatService / OpenAIEmbeddingService.
 *
 * Раньше задержка была фиксированной (500мс / 2000мс) и игнорировала
 * `Retry-After` — на rate-limit (429) OpenAI просит подождать секунды, а мы
 * повторяли через 0.5с, снова ловили 429 и сжигали попытку впустую. Теперь:
 *   1) если сервер прислал Retry-After (или x-ratelimit-reset-*) — ждём
 *      столько (с капом, чтобы не подвесить джобу с лимитом времени);
 *   2) иначе — экспоненциальный бэкофф с джиттером.
 */
class OpenAIRetry
{
    /**
     * Задержка перед попыткой $attempt (1-based) в миллисекундах.
     */
    public static function backoffMs(int $attempt, ?\Throwable $exception, int $baseMs, int $capMs): int
    {
        $hinted = self::serverHintMs($exception);
        if ($hinted !== null) {
            return max($baseMs, min($hinted, $capMs));
        }

        // Экспоненциально: base * 2^(attempt-1) + джиттер ±20%, с капом.
        $exp = $baseMs * (2 ** max(0, $attempt - 1));
        $jitter = (int) round($exp * (mt_rand(-20, 20) / 100));

        return max($baseMs, min($exp + $jitter, $capMs));
    }

    /**
     * Подсказка сервера о паузе в мс из заголовков ответа, либо null.
     */
    private static function serverHintMs(?\Throwable $exception): ?int
    {
        if (! $exception instanceof RequestException || ! $exception->response) {
            return null;
        }
        $response = $exception->response;

        // Retry-After: число секунд (HTTP-date вариант игнорируем — у OpenAI
        // на rate-limit приходит число).
        $retryAfter = trim((string) $response->header('Retry-After'));
        if ($retryAfter !== '' && is_numeric($retryAfter)) {
            return (int) round(((float) $retryAfter) * 1000);
        }

        // x-ratelimit-reset-requests / -tokens: формат «1s», «320ms», «1m30s».
        foreach (['x-ratelimit-reset-requests', 'x-ratelimit-reset-tokens'] as $h) {
            $ms = self::parseDurationMs(trim((string) $response->header($h)));
            if ($ms !== null) {
                return $ms;
            }
        }

        return null;
    }

    /**
     * «1s» / «320ms» / «1m30s» → миллисекунды; null если не распарсилось.
     */
    private static function parseDurationMs(string $value): ?int
    {
        if ($value === '' || ! preg_match_all('/(\d+(?:\.\d+)?)(ms|s|m|h)/', $value, $m, PREG_SET_ORDER)) {
            return null;
        }
        $ms = 0.0;
        foreach ($m as $part) {
            $n = (float) $part[1];
            $ms += match ($part[2]) {
                'ms' => $n,
                's' => $n * 1000,
                'm' => $n * 60_000,
                'h' => $n * 3_600_000,
                default => 0,
            };
        }

        return (int) round($ms);
    }
}
