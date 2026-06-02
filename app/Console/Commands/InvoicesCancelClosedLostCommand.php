<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\Request as RequestModel;
use App\Models\User;
use App\Services\Invoices\InvoiceService;
use Illuminate\Console\Command;

/**
 * Backfill: аннулирование «зависших» pending-счетов у заявок, уже закрытых
 * как потеря (closed_lost).
 *
 * До фикса в RequestStateService::transitionTo() закрытие заявки как потеря
 * НЕ трогало её счета — invoice оставался pending, хотя заказчик отказался
 * (тикет M-2026-2389). Эта команда находит такие случаи и аннулирует счёт
 * через тот же InvoiceService::cancel(), что и боевой путь.
 *
 * Аннулирование безопасно: заявка уже terminal (closed_lost), поэтому
 * cancel() → maybeTransitionToAwaitingInvoice не вернёт её в AwaitingInvoice.
 *
 * Usage:
 *   php artisan invoices:cancel-closed-lost            # dry-run (default)
 *   php artisan invoices:cancel-closed-lost --apply    # реально аннулировать
 *   php artisan invoices:cancel-closed-lost --apply --request=M-2026-2389
 */
class InvoicesCancelClosedLostCommand extends Command
{
    protected $signature = 'invoices:cancel-closed-lost
        {--dry-run : Показать что будет аннулировано без записи (default)}
        {--apply : Реально аннулировать pending-счета}
        {--request= : Фильтр по internal_code заявки (например M-2026-2389)}';

    protected $description = 'Аннулирует зависшие pending-счета у заявок, закрытых как потеря (closed_lost).';

    public function handle(InvoiceService $invoiceService): int
    {
        $apply = (bool) $this->option('apply');
        $requestFilter = $this->option('request') ? trim((string) $this->option('request')) : null;

        $query = RequestModel::query()
            ->where('status', RequestStatus::ClosedLost->value)
            ->whereHas('invoices', fn ($q) => $q->where('status', InvoiceStatus::Pending->value))
            ->with(['assignedUser', 'invoices' => fn ($q) => $q->where('status', InvoiceStatus::Pending->value)]);

        if ($requestFilter !== null) {
            $query->where('internal_code', $requestFilter);
        }

        $requests = $query->orderBy('id')->get();

        if ($requests->isEmpty()) {
            $this->info('Зависших pending-счетов у closed_lost заявок не найдено.');

            return self::SUCCESS;
        }

        $fallbackAuthor = User::role(Role::Admin->value)->first();

        $stats = ['cancelled' => 0, 'fail' => 0, 'dry' => 0, 'requests' => 0];

        foreach ($requests as $req) {
            $stats['requests']++;
            $reason = $req->closed_lost_reason ?: '—';
            $this->line(sprintf(
                '%s (closed %s, reason=%s):',
                $req->internal_code,
                $req->closed_at?->format('d.m.Y') ?? '?',
                $reason,
            ));

            $author = $req->assignedUser ?? $fallbackAuthor;

            foreach ($req->invoices as $invoice) {
                $amount = $invoice->amount_snapshot !== null
                    ? number_format((float) $invoice->amount_snapshot, 2, '.', ' ').' ₽'
                    : '—';

                if (! $apply) {
                    $this->line(sprintf('  DRY: счёт №%s (%s) → cancelled', $invoice->invoice_number, $amount));
                    $stats['dry']++;

                    continue;
                }

                if ($author === null) {
                    $this->warn(sprintf('  SKIP: счёт №%s — нет автора (assignee/admin)', $invoice->invoice_number));
                    $stats['fail']++;

                    continue;
                }

                try {
                    $invoiceService->cancel(
                        $invoice,
                        'Backfill: заявка закрыта как потеря — счёт аннулирован.',
                        $author,
                    );
                    $this->line(sprintf('  OK : счёт №%s (%s) аннулирован', $invoice->invoice_number, $amount));
                    $stats['cancelled']++;
                } catch (\Throwable $e) {
                    $this->error(sprintf('  ERR: счёт №%s — %s', $invoice->invoice_number, $e->getMessage()));
                    $stats['fail']++;
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Заявок=%d · аннулировано=%d · ошибки=%d · dry=%d',
            $stats['requests'], $stats['cancelled'], $stats['fail'], $stats['dry'],
        ));
        if (! $apply && $stats['dry'] > 0) {
            $this->warn('--dry-run: cancel() не вызван. Используй --apply.');
        }

        return self::SUCCESS;
    }
}
