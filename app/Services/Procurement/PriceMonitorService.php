<?php

namespace App\Services\Procurement;

use App\Models\PriceMonitor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Автоматический мониторинг цен (Фаза 4.3): включение при отправке запроса
 * поставщикам, отметка о состоявшейся рассылке, выключение.
 *
 * Мониторинг живёт на каталожной позиции, а не на запросе: одна позиция —
 * одна строка. Повторная отправка той же позиции с включённым флагом
 * обновляет период и набор поставщиков, а не плодит дубли.
 */
class PriceMonitorService
{
    /**
     * Включить или обновить мониторинг для набора позиций.
     *
     * @param  array<int, int>  $catalogItemIds
     * @param  array<int, int>  $supplierIds  кому уходил запрос — им же пойдут повторные
     * @return int сколько записей создано или обновлено
     */
    public function enable(array $catalogItemIds, array $supplierIds, int $intervalDays, ?User $by = null, ?int $inquiryId = null): int
    {
        $ids = array_values(array_unique(array_map('intval', $catalogItemIds)));
        if ($ids === []) {
            return 0;
        }

        $days = PriceMonitor::clampInterval($intervalDays);
        $suppliers = array_values(array_unique(array_map('intval', $supplierIds)));
        $now = now();
        $touched = 0;

        DB::transaction(function () use ($ids, $suppliers, $days, $by, $inquiryId, $now, &$touched) {
            foreach ($ids as $cid) {
                $m = PriceMonitor::firstOrNew(['catalog_item_id' => $cid]);
                $m->interval_days = $days;
                $m->supplier_ids = $suppliers !== [] ? $suppliers : $m->supplier_ids;
                // Запрос только что ушёл — отсчитываем период от него.
                $m->last_dispatched_at = $now;
                $m->next_due_at = $now->copy()->addDays($days);
                $m->dispatch_count = (int) $m->dispatch_count + 1;
                $m->is_active = true;
                $m->disabled_at = null;
                $m->created_by_user_id = $m->created_by_user_id ?? $by?->id;
                if ($inquiryId !== null) {
                    $m->last_inquiry_id = $inquiryId;
                }
                $m->save();
                $touched++;
            }
        });

        return $touched;
    }

    /** Отметить состоявшуюся авто-рассылку и отодвинуть срок. */
    public function markDispatched(PriceMonitor $monitor, ?int $inquiryId = null): void
    {
        $monitor->forceFill([
            'last_dispatched_at' => now(),
            'next_due_at' => now()->addDays($monitor->interval_days),
            'dispatch_count' => (int) $monitor->dispatch_count + 1,
            'last_inquiry_id' => $inquiryId ?? $monitor->last_inquiry_id,
        ])->save();
    }

    /**
     * Отложить срок, не считая рассылку состоявшейся: например, когда
     * у позиции не осталось поставщиков и слать некому.
     */
    public function postpone(PriceMonitor $monitor, int $days = 1): void
    {
        $monitor->forceFill(['next_due_at' => now()->addDays(max(1, $days))])->save();
    }

    public function disable(PriceMonitor $monitor): void
    {
        $monitor->forceFill(['is_active' => false, 'disabled_at' => now(), 'next_due_at' => null])->save();
    }

    public function setInterval(PriceMonitor $monitor, int $days): void
    {
        $days = PriceMonitor::clampInterval($days);
        $base = $monitor->last_dispatched_at ?? now();
        $monitor->forceFill([
            'interval_days' => $days,
            'next_due_at' => $monitor->is_active ? $base->copy()->addDays($days) : null,
        ])->save();
    }
}
