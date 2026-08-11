<?php

namespace App\Services\Request;

use App\Models\DealerEmail;
use App\Models\Request;
use Illuminate\Support\Carbon;

/**
 * Авто-пометка «дилерских» email'ов.
 *
 * Если у одного `client_email` пришло ≥ `dealer.auto_threshold` заявок за
 * скользящее окно `dealer.auto_window_days` (любой статус, суммарно по всей
 * системе) — email автоматически получает запись в `dealer_emails`. Окно по
 * потоку (а не «N одновременно открытых») ловит и дистрибьюторов с быстрым
 * оборотом, у которых открытых мало, но общий поток заявок большой.
 *
 * AssignmentService::pickStickyByClientEmail для дилеров возвращает
 * null → client-sticky (1b) не применяется. Catalog (1a) и text (1c)
 * sticky продолжают работать для дилеров.
 *
 * Управление — только порог в Настройках (app_setting 'dealer.auto_threshold').
 * Никакой ручной пометки/снятия по требованию заказчика. 0 — выключить.
 */
class DealerEmailService
{
    /**
     * In-memory cache for hot lookups в рамках одного autoAssign() вызова.
     * Не PSR/Redis — простая ассоциация на время процесса.
     *
     * @var array<string, bool>
     */
    private array $cache = [];

    /**
     * @return bool true если email помечен как дилерский.
     */
    public function isDealer(string $email): bool
    {
        $normalized = $this->normalize($email);
        if ($normalized === '') {
            return false;
        }
        if (array_key_exists($normalized, $this->cache)) {
            return $this->cache[$normalized];
        }
        $exists = DealerEmail::query()->where('email', $normalized)->exists();

        return $this->cache[$normalized] = $exists;
    }

    /**
     * Проверить порог и пометить email как дилерский, если ещё не помечен.
     * Вызывать ПЕРЕД client-sticky lookup'ом, чтобы новая заявка тут же
     * исключилась из 1b при превышении порога.
     */
    public function autoMarkIfNeeded(string $email): void
    {
        $normalized = $this->normalize($email);
        if ($normalized === '') {
            return;
        }
        if ($this->isDealer($normalized)) {
            return;
        }

        $threshold = (int) app_setting(
            'dealer.auto_threshold',
            config('services.dealer.auto_threshold', 8),
        );
        // 0 = автопометка выключена (escape hatch для РОПа на случай отладки).
        if ($threshold <= 0) {
            return;
        }

        // Считаем ПОТОК заявок клиента за скользящее окно (любой статус), а не
        // «N одновременно открытых». Иначе дистрибьюторы с быстрым оборотом
        // (быстро квотируют/закрывают) никогда не достигают порога по открытым
        // и ускользают от пометки, хотя суммарный поток большой.
        $windowDays = (int) app_setting(
            'dealer.auto_window_days',
            config('services.dealer.auto_window_days', 30),
        );

        $count = Request::query()
            ->whereRaw('LOWER(client_email) = ?', [$normalized])
            ->when($windowDays > 0, fn ($q) => $q->where('created_at', '>=', Carbon::now()->subDays($windowDays)))
            ->count();

        if ($count >= $threshold) {
            DealerEmail::query()->updateOrCreate(
                ['email' => $normalized],
                [
                    'open_count_at_mark' => $count,
                    'marked_at' => Carbon::now(),
                ],
            );
            $this->cache[$normalized] = true;
        }
    }

    private function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
