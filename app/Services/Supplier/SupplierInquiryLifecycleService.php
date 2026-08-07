<?php

namespace App\Services\Supplier;

use App\Models\Supplier;
use App\Models\SupplierInquiry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Действия менеджера над «застрявшими» запросами поставщикам (RFQ) из раздела
 * «Снабжение»: безответные, по которым авто-напоминания уже не шлются
 * (лимит исчерпан при тишине ИЛИ поставщик ответил, но без оффера/отказа).
 *
 * Три сценария:
 *   - closeAsDeclined     — закрыть как отказ поставщика (status=closed, позиции
 *                           pending→refused);
 *   - resumeAndRemind     — вернуть в работу: сброс счётчика напоминаний + сразу
 *                           отправить одно напоминание;
 *   - reRequest           — заново запросить: закрыть старый как superseded и
 *                           отправить свежий RFQ по тем же позициям поставщику.
 *
 * Аудит — в notes инквайри (человекочитаемо) + Log.
 */
class SupplierInquiryLifecycleService
{
    public function __construct(
        private readonly SupplierReminderService $reminders,
        private readonly SupplierProcurementDispatchService $dispatcher,
    ) {
    }

    /**
     * Закрыть RFQ как отказ поставщика. Инквайри уходит из напоминаний и из
     * списка «застрявших»; pending-позиции помечаются refused.
     */
    public function closeAsDeclined(SupplierInquiry $inquiry, User $by): void
    {
        DB::transaction(function () use ($inquiry, $by) {
            $inquiry->items()->where('status', 'pending')->update(['status' => 'refused']);
            $inquiry->forceFill([
                'status' => 'closed',
                'notes' => $this->appendNote($inquiry->notes, "Закрыт как отказ поставщика ({$by->name})"),
            ])->save();
        });

        Log::info('SupplierInquiryLifecycle: closed as declined', [
            'inquiry_id' => $inquiry->id, 'by_user' => $by->id,
        ]);
    }

    /**
     * Вернуть в работу: сброс счётчика/времени напоминаний → крон снова подхватит
     * (для тишины), плюс сразу отправляем одно напоминание в тред. Для «ответил
     * без оффера» авто-крон не сработает (есть входящее), но ручной нажим — да.
     *
     * @return bool отправилось ли напоминание сейчас
     */
    public function resumeAndRemind(SupplierInquiry $inquiry, User $by): bool
    {
        $inquiry->forceFill([
            'reminders_sent' => 0,
            'last_reminder_at' => null,
            'status' => 'open',
            'notes' => $this->appendNote($inquiry->notes, "Возвращён в работу ({$by->name})"),
        ])->save();

        $sent = $this->reminders->remind($inquiry->fresh(), $by);

        Log::info('SupplierInquiryLifecycle: resumed', [
            'inquiry_id' => $inquiry->id, 'by_user' => $by->id, 'reminder_sent' => $sent,
        ]);

        return $sent;
    }

    /**
     * Заново запросить: закрыть текущий инквайри как superseded (иначе дедуп
     * dispatch пропустит уже-pending позиции) и отправить свежий RFQ тому же
     * поставщику по тем же каталожным позициям.
     *
     * @return array{ok: bool, error: ?string, sent: int}
     */
    public function reRequest(SupplierInquiry $inquiry, User $by): array
    {
        $supplier = Supplier::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $inquiry->supplier_email))])
            ->first();
        if ($supplier === null) {
            return ['ok' => false, 'error' => 'supplier_not_in_registry', 'sent' => 0];
        }

        $catalogIds = $inquiry->items()
            ->whereNotNull('catalog_item_id')
            ->pluck('catalog_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($catalogIds === []) {
            return ['ok' => false, 'error' => 'no_catalog_items', 'sent' => 0];
        }

        // Закрываем старый до dispatch — иначе дедуп по open+pending пропустит позиции.
        $inquiry->forceFill([
            'status' => 'closed',
            'notes' => $this->appendNote($inquiry->notes, "Пере-запрошен заново ({$by->name})"),
        ])->save();

        $res = $this->dispatcher->dispatch($catalogIds, [$supplier->id], null, $by);

        if ((int) ($res['sent'] ?? 0) < 1) {
            // Откат закрытия — свежий RFQ не ушёл, оставляем старый в работе.
            $inquiry->forceFill(['status' => 'open'])->save();

            return ['ok' => false, 'error' => $res['error'] ?? 'dispatch_failed', 'sent' => 0];
        }

        Log::info('SupplierInquiryLifecycle: re-requested', [
            'inquiry_id' => $inquiry->id, 'by_user' => $by->id, 'supplier_id' => $supplier->id, 'sent' => $res['sent'],
        ]);

        return ['ok' => true, 'error' => null, 'sent' => (int) $res['sent']];
    }

    private function appendNote(?string $existing, string $line): string
    {
        $stamp = now()->format('d.m.Y H:i');
        $entry = "[{$stamp}] {$line}";

        return trim((string) $existing) === '' ? $entry : ($existing . "\n" . $entry);
    }
}
