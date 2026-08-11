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
        // Дилер, если запись есть И статус не снят вручную (manual=false).
        // manual: null (авто) | true (вкл вручную) | false (снято вручную).
        $exists = DealerEmail::query()
            ->where('email', $normalized)
            ->where(fn ($q) => $q->whereNull('manual')->orWhere('manual', true))
            ->exists();

        return $this->cache[$normalized] = $exists;
    }

    /** Пометить e-mail «перепродавцом» вручную (менеджер из карточки заявки). */
    public function markManual(string $email, ?int $userId): bool
    {
        $normalized = $this->normalize($email);
        if ($normalized === '') {
            return false;
        }
        DealerEmail::query()->updateOrCreate(
            ['email' => $normalized],
            [
                'manual' => true,
                'marked_by_user_id' => $userId,
                'marked_at' => Carbon::now(),
                // open_count_at_mark NOT NULL — для ручной пометки 0.
                'open_count_at_mark' => 0,
            ],
        );
        unset($this->cache[$normalized]);

        return true;
    }

    /**
     * Снять статус «перепродавец» вручную. Оставляем запись с manual=false как
     * суппресс — авто-пометка по потоку не воскресит снятый вручную статус.
     */
    public function unmarkManual(string $email, ?int $userId): bool
    {
        $normalized = $this->normalize($email);
        if ($normalized === '') {
            return false;
        }
        DealerEmail::query()->updateOrCreate(
            ['email' => $normalized],
            [
                'manual' => false,
                'marked_by_user_id' => $userId,
                'marked_at' => Carbon::now(),
                'open_count_at_mark' => 0,
            ],
        );
        unset($this->cache[$normalized]);

        return true;
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
        // Снято вручную (manual=false) — авто-пометка по потоку не воскрешает.
        if (DealerEmail::query()->where('email', $normalized)->where('manual', false)->exists()) {
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
