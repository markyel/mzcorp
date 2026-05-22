<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Invoices\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4 — daily cron: помечает просроченные счета как expired.
 *
 *  - Находит Invoice со status=pending и expires_at <= now().
 *  - Через InvoiceService::expire ставит status=expired и возвращает
 *    Request в AwaitingInvoice (если у него больше нет pending invoices).
 *  - Idempotent: можно запускать многократно, no-op для уже expired.
 *
 * Расписание (см. routes/console.php):
 *   Schedule::command('invoices:check-expiry')
 *       ->dailyAt('07:00')->onOneServer()->withoutOverlapping();
 *
 * --dry-run: печатает что будет сделано, ничего не пишет в БД.
 */
class InvoicesCheckExpiryCommand extends Command
{
    protected $signature = 'invoices:check-expiry
        {--dry-run : Показать что было бы expired, без UPDATE.}';

    protected $description = 'Помечает просроченные счета как expired + возвращает Request в AwaitingInvoice.';

    public function handle(InvoiceService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Invoice::query()
            ->where('status', InvoiceStatus::Pending->value)
            ->where('expires_at', '<=', now())
            ->with('request:id,internal_code,status,assigned_user_id')
            ->orderBy('expires_at')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Просроченных счетов нет.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d просроченных счетов:',
            $dryRun ? '[DRY-RUN] Нашли' : 'Помечаем',
            $candidates->count(),
        ));

        $rows = [];
        $ok = 0;
        $fail = 0;

        foreach ($candidates as $invoice) {
            $row = [
                $invoice->id,
                $invoice->invoice_number,
                $invoice->request?->internal_code ?? '—',
                $invoice->expires_at->format('d.m.Y'),
                $invoice->expires_at->diffForHumans(),
            ];

            if ($dryRun) {
                $rows[] = $row;
                continue;
            }

            try {
                $service->expire($invoice);
                $ok++;
                $rows[] = [...$row, '✓'];
            } catch (\Throwable $e) {
                $fail++;
                $rows[] = [...$row, '✗ ' . $e->getMessage()];
                Log::error('InvoicesCheckExpiryCommand: expire failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $headers = ['id', 'number', 'request', 'expires_at', 'overdue'];
        if (! $dryRun) {
            $headers[] = 'result';
        }
        $this->table($headers, $rows);

        if (! $dryRun) {
            $this->info(sprintf('Готово: ok=%d, fail=%d.', $ok, $fail));
            Log::info('InvoicesCheckExpiryCommand finished', [
                'expired' => $ok,
                'failed' => $fail,
                'candidates' => $candidates->count(),
            ]);
        }

        return self::SUCCESS;
    }
}
