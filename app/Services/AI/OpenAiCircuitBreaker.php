<?php

namespace App\Services\AI;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\OpenAiCircuitOpenedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit-breaker для OpenAI-вызовов.
 *
 * Зачем: 25.05.2026 OpenAI вернул insufficient_quota (429) на ВСЕ
 * категоризации писем. Категоризатор молча возвращал empty, а scheduler
 * `mail:categorize --all` каждые 5 минут жёг 50 безнадёжных вызовов
 * подряд — лишние списания у прокси-провайдера + 18 писем накопились
 * в backlog без понятной причины.
 *
 * Логика:
 *   - При N подряд transient-failures (429/503/502/insufficient_quota/
 *     timeout) circuit «открывается» на M минут — все isOpen()-проверки
 *     возвращают true, потребитель пропускает вызов без обращения к API.
 *   - При открытии шлём notification админу (db + email) — но не чаще
 *     1 раза в час, чтобы не спамить при длительном простое.
 *   - Первый успешный вызов после открытия → recordSuccess() очищает
 *     счётчик и снимает паузу.
 *
 * Состояние в Cache (database driver) с TTL = max(cooldown, notify-cooldown):
 *   openai:circuit:fail_count   — счётчик подряд-failures
 *   openai:circuit:opened_at    — момент открытия circuit
 *   openai:circuit:notified_at  — когда последний раз шлали notification
 */
class OpenAiCircuitBreaker
{
    private const CACHE_FAIL = 'openai:circuit:fail_count';
    private const CACHE_OPEN = 'openai:circuit:opened_at';
    private const CACHE_NOTIFIED = 'openai:circuit:notified_at';

    public function isOpen(): bool
    {
        $openedAt = Cache::get(self::CACHE_OPEN);
        if (! $openedAt) {
            return false;
        }
        $cooldown = $this->cooldownMinutes();
        return Carbon::parse($openedAt)->addMinutes($cooldown)->isFuture();
    }

    /**
     * Сколько минут осталось до автоматического закрытия (для UI).
     */
    public function remainingMinutes(): int
    {
        $openedAt = Cache::get(self::CACHE_OPEN);
        if (! $openedAt) {
            return 0;
        }
        $closes = Carbon::parse($openedAt)->addMinutes($this->cooldownMinutes());
        return max(0, (int) ceil($closes->diffInMinutes(now())));
    }

    /**
     * Зарегистрировать transient-ошибку. После N подряд — открыть circuit.
     */
    public function recordFailure(string $reason, array $context = []): void
    {
        // increment без явного init может вернуть int|bool в зависимости
        // от драйвера → нормализуем.
        if (! Cache::has(self::CACHE_FAIL)) {
            Cache::put(self::CACHE_FAIL, 0, now()->addHours(2));
        }
        $count = (int) Cache::increment(self::CACHE_FAIL);

        $threshold = $this->failThreshold();
        if ($count < $threshold) {
            return;
        }

        // Уже открыт — продлеваем opened_at не нужно (cooldown стартует
        // от первого открытия и сам себя обновляет при новых failures
        // только если этого требует политика; пока — нет).
        if (Cache::has(self::CACHE_OPEN)) {
            return;
        }

        Cache::put(self::CACHE_OPEN, now()->toIso8601String(), now()->addHours(2));
        Log::warning('OpenAiCircuitBreaker: opened', [
            'fail_count' => $count,
            'threshold' => $threshold,
            'cooldown_minutes' => $this->cooldownMinutes(),
            'reason' => mb_substr($reason, 0, 300),
            'context' => $context,
        ]);

        $this->maybeNotifyAdmins($reason, $count);
    }

    /**
     * Удачный ответ → сбрасываем всё состояние.
     */
    public function recordSuccess(): void
    {
        $wasOpen = Cache::has(self::CACHE_OPEN);
        Cache::forget(self::CACHE_FAIL);
        Cache::forget(self::CACHE_OPEN);
        Cache::forget(self::CACHE_NOTIFIED);
        if ($wasOpen) {
            Log::info('OpenAiCircuitBreaker: closed by successful call');
        }
    }

    /**
     * @param  \Throwable  $e
     * @return bool  true → это transient OpenAI-ошибка, имеет смысл
     *               записать в circuit-breaker.
     */
    public function isTransientError(\Throwable $e): bool
    {
        $msg = mb_strtolower($e->getMessage());
        return str_contains($msg, '429')
            || str_contains($msg, '503')
            || str_contains($msg, '502')
            || str_contains($msg, '504')
            || str_contains($msg, 'insufficient_quota')
            || str_contains($msg, 'rate limit')
            || str_contains($msg, 'rate_limit')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'connection reset');
    }

    private function maybeNotifyAdmins(string $reason, int $failCount): void
    {
        if (Cache::has(self::CACHE_NOTIFIED)) {
            return; // спам-гейт
        }
        Cache::put(self::CACHE_NOTIFIED, now()->toIso8601String(), now()->addMinutes($this->notifyCooldownMinutes()));

        try {
            $admins = User::role(Role::Admin->value)->get();
            foreach ($admins as $admin) {
                $admin->notify(new OpenAiCircuitOpenedNotification(
                    reason: $reason,
                    failCount: $failCount,
                    cooldownMinutes: $this->cooldownMinutes(),
                ));
            }
        } catch (\Throwable $e) {
            Log::error('OpenAiCircuitBreaker: notify failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function failThreshold(): int
    {
        return (int) config('services.openai.circuit_breaker.fail_threshold', 3);
    }

    private function cooldownMinutes(): int
    {
        return (int) config('services.openai.circuit_breaker.cooldown_minutes', 15);
    }

    private function notifyCooldownMinutes(): int
    {
        return (int) config('services.openai.circuit_breaker.notify_cooldown_minutes', 60);
    }
}
