<?php

namespace App\Console\Commands;

use App\Enums\DetectorType;
use App\Enums\InvoiceStatus;
use App\Jobs\Quotes\ParseOutboundQuoteJob;
use App\Models\Invoice;
use App\Models\OutboundQuote;
use App\Models\Request as RequestModel;
use App\Services\Calendar\RussianWorkingDayService;
use App\Services\Invoices\InvoiceService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Backfill реального срока действия (expires_at) у уже выставленных pending-счетов
 * на основе даты «действителен до / срок резерва», которую парсер теперь извлекает
 * из документа и сопроводительного письма (поле outbound_quotes.valid_until).
 *
 * Раньше срок всегда считался как «дата счёта + 5 рабочих дней», из-за чего
 * напоминания об истечении уходили раньше реального резерва (M-2026-3307).
 *
 * Двухшаговый сценарий (valid_until заполняется асинхронным reparse'ом):
 *
 *   1) Переразобрать вложения, чтобы заполнить valid_until:
 *      php artisan invoices:backfill-validity --reparse --request=M-2026-3307        # dry-run
 *      php artisan invoices:backfill-validity --reparse --request=M-2026-3307 --apply # dispatch
 *
 *   2) Дождаться очереди, затем применить извлечённые сроки к счетам:
 *      php artisan invoices:backfill-validity --request=M-2026-3307                   # dry-run preview
 *      php artisan invoices:backfill-validity --request=M-2026-3307 --apply           # применить
 *
 * Без --request обрабатывает все pending-счета (с учётом --limit).
 */
class InvoicesBackfillValidityCommand extends Command
{
    protected $signature = 'invoices:backfill-validity
        {--apply : Реально применить/диспатчить, иначе dry-run}
        {--request= : Точечно по internal_code заявки (M-2026-NNNN)}
        {--reparse : Шаг 1 — dispatch ParseOutboundQuoteJob(force) для заполнения valid_until}
        {--limit=200 : Максимум счетов за прогон}';

    protected $description = 'Backfill срока действия pending-счетов из valid_until (документ/письмо)';

    public function handle(InvoiceService $invoiceService, RussianWorkingDayService $calendar): int
    {
        $apply = (bool) $this->option('apply');
        $reparse = (bool) $this->option('reparse');
        $limit = (int) $this->option('limit');
        $requestCode = $this->option('request');

        $query = Invoice::query()
            ->where('status', InvoiceStatus::Pending->value)
            ->orderBy('id');

        if ($requestCode !== null && $requestCode !== '') {
            $req = RequestModel::where('internal_code', $requestCode)->first();
            if (! $req) {
                $this->error("Request {$requestCode} not found");

                return self::FAILURE;
            }
            $query->where('request_id', $req->id);
        }

        $invoices = $query->limit($limit)->get();

        $this->info(sprintf(
            'Pending invoices: %d. Mode: %s. Step: %s.',
            $invoices->count(),
            $apply ? 'APPLY' : 'DRY-RUN',
            $reparse ? 'reparse (fill valid_until)' : 'apply validity',
        ));
        if ($invoices->isEmpty()) {
            return self::SUCCESS;
        }

        $default = (int) config('services.invoices.default_validity_business_days', 5);
        $touched = 0;
        $skipped = 0;

        foreach ($invoices as $invoice) {
            $quote = $this->resolveQuote($invoice);

            if ($reparse) {
                if (! $quote || $quote->email_attachment_id === null) {
                    $this->line(sprintf('  [skip] inv#%d №%s — нет вложения для reparse', $invoice->id, $invoice->invoice_number));
                    $skipped++;

                    continue;
                }
                $this->line(sprintf(
                    '  %s inv#%d №%s → ParseOutboundQuoteJob(att=%d, force)',
                    $apply ? '[DISPATCH]' : '[DRY]',
                    $invoice->id,
                    $invoice->invoice_number,
                    $quote->email_attachment_id,
                ));
                if ($apply) {
                    ParseOutboundQuoteJob::dispatch(
                        $quote->email_attachment_id,
                        $quote->document_type?->value ?? DetectorType::OutboundInvoice->value,
                        true,
                    );
                }
                $touched++;

                continue;
            }

            // Шаг 2 — применить срок из valid_until.
            if (! $quote) {
                $this->line(sprintf('  [skip] inv#%d №%s — связанный OutboundQuote не найден', $invoice->id, $invoice->invoice_number));
                $skipped++;

                continue;
            }

            $issuedAt = $invoice->issued_at ?? Carbon::parse((string) $invoice->issued_at);
            [$newExpires] = InvoiceService::computeExpiry($calendar, $issuedAt, $quote->valid_until, $default);
            $oldDay = $invoice->expires_at?->toDateString();
            $newDay = $newExpires->toDateString();

            if ($oldDay === $newDay) {
                $this->line(sprintf(
                    '  [no-change] inv#%d №%s — срок %s (valid_until=%s)',
                    $invoice->id,
                    $invoice->invoice_number,
                    $oldDay ?? '—',
                    $quote->valid_until?->toDateString() ?? 'нет',
                ));
                $skipped++;

                continue;
            }

            $this->line(sprintf(
                '  %s inv#%d №%s: %s → %s (valid_until=%s)',
                $apply ? '[APPLY]' : '[DRY]',
                $invoice->id,
                $invoice->invoice_number,
                $oldDay ?? '—',
                $newDay,
                $quote->valid_until?->toDateString() ?? 'нет (fallback +'.$default.' дн.)',
            ));

            if ($apply) {
                $invoiceService->applyValidityFromQuote($invoice, $quote);
            }
            $touched++;
        }

        $this->info(sprintf('Done. Touched: %d. Skipped: %d.', $touched, $skipped));
        if (! $apply) {
            $this->warn('Это был DRY-RUN. Запусти с --apply чтобы реально применить/диспатчить.');
        }

        return self::SUCCESS;
    }

    /**
     * Связанный исходящий счёт-документ: сперва по email_message_id счёта,
     * иначе по (request_id + document_number = invoice_number). Берём последний
     * распарсенный.
     */
    private function resolveQuote(Invoice $invoice): ?OutboundQuote
    {
        $base = OutboundQuote::query()
            ->where('document_type', DetectorType::OutboundInvoice->value)
            ->orderByDesc('id');

        if ($invoice->email_message_id !== null) {
            $byMessage = (clone $base)->where('email_message_id', $invoice->email_message_id)->first();
            if ($byMessage) {
                return $byMessage;
            }
        }

        return (clone $base)
            ->where('request_id', $invoice->request_id)
            ->where('document_number', $invoice->invoice_number)
            ->first();
    }
}
