<?php

namespace App\Services\Supplier;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ход фоновой рассылки запросов поставщикам по заявке (SendSupplierDispatchJob): сколько ушло,
 * кому пропущено как повтор, кому не ушло, идёт ли ещё. Одна рассылка на заявку за раз; держим
 * в кэше (общем для веба и воркеров) сутки — панель «Поставщики» показывает итог.
 */
class SupplierDispatchRuns
{
    private const TTL_HOURS = 24;

    /** Рассылка «зависла», если не обновлялась столько минут: можно запускать новую. */
    private const STALE_MIN = 20;

    private static function key(int $requestId): string
    {
        return 'supplier-dispatch-run:'.$requestId;
    }

    /** @return array<string, mixed>|null */
    public function current(int $requestId): ?array
    {
        $run = Cache::get(self::key($requestId));

        return is_array($run) ? $run : null;
    }

    /** Идёт рассылка по заявке (в очереди или отправляется, и не зависла). */
    public function active(int $requestId): ?array
    {
        $run = $this->current($requestId);
        if ($run === null || ! in_array($run['status'] ?? null, ['queued', 'running'], true)) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($run['updated_at'])->gt(now()->subMinutes(self::STALE_MIN)) ? $run : null;
    }

    /**
     * @param  list<string>  $suppliers  имена поставщиков рассылки
     * @param  list<array{supplier: string, at: string}>  $skipped  отсеянные сразу как повтор
     * @return array<string, mixed>
     */
    public function start(int $requestId, int $userId, string $userName, array $suppliers, array $skipped = []): array
    {
        $run = [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'user_name' => $userName,
            'status' => 'queued',
            'total' => count($suppliers),
            'suppliers' => $suppliers,
            'sent' => 0,
            'failed' => 0,
            'skipped' => $skipped,
            'current' => null,
            'error' => null,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];
        Cache::put(self::key($requestId), $run, now()->addHours(self::TTL_HOURS));

        return $run;
    }

    /** @param  array<string, mixed>  $fields */
    public function update(int $requestId, string $runId, array $fields): void
    {
        $run = $this->current($requestId);
        if ($run === null || ($run['id'] ?? null) !== $runId) {
            return;   // уже другая рассылка
        }
        Cache::put(self::key($requestId), array_merge($run, $fields, ['updated_at' => now()->toIso8601String()]), now()->addHours(self::TTL_HOURS));
    }
}
