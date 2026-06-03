<?php

namespace App\Console\Commands;

use App\Enums\ClosedLostReason;
use App\Enums\InvoiceStatus;
use App\Enums\RequestStatus;
use App\Models\ClarificationBatch;
use App\Models\Invoice;
use App\Models\Request;
use App\Models\RequestStateChange;
use App\Services\Mail\ClientNotificationService;
use App\Services\Request\RequestStateService;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Авто-закрытие заявок по молчанию клиента (Foundation §5 «потери по тишине»).
 *
 * Три правила, пороги (календарные дни) — в настройках админки, 0 = выкл:
 *   - auto_close.clarification_days (4): не ответил на уточнение →
 *     closed_lost `no_client_response_to_clarification`;
 *   - auto_close.quote_days (5): не отреагировал на КП →
 *     closed_lost `no_client_response_to_quote`;
 *   - auto_close.invoice_days (5): счёт не оплачен с даты выставления →
 *     closed_lost `invoice_unpaid`.
 *
 * Закрытие идёт через RequestStateService::systemCloseLost (идемпотентно,
 * аудит `system_close_lost`, attention снимается). Пропускаем терминальные,
 * Paused и PostponedUntil (намеренные паузы). После закрытия — best-effort
 * уведомление клиенту (само ничего не шлёт, пока шаблон OrderClosedLost выключен).
 *
 * Восстановление ошибочно закрытых — ручной кнопкой «↻ Реанимировать» на
 * карточке (менеджер сохраняется).
 *
 * Расписание: routes/console.php — раз в сутки. Запускать руками сперва с
 * --dry-run, при большом объёме исторических кандидатов — партиями через --limit.
 */
class RequestsAutoCloseInactiveCommand extends Command
{
    protected $signature = 'requests:auto-close-inactive
        {--dry-run : Показать кандидатов, ничего не закрывать}
        {--limit=0 : Максимум закрытий за прогон (0 = без лимита)}';

    protected $description = 'Авто-закрытие заявок по молчанию клиента (уточнение / КП / счёт). Пороги — в настройках.';

    /** Статусы, которые НИКОГДА не закрываем авто (намеренные паузы). */
    private const SKIP_STATUSES = [RequestStatus::Paused, RequestStatus::PostponedUntil];

    public function handle(SettingsService $settings, RequestStateService $state): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $clarDays = (int) $settings->get('auto_close.clarification_days', 4);
        $quoteDays = (int) $settings->get('auto_close.quote_days', 5);
        $invoiceDays = (int) $settings->get('auto_close.invoice_days', 5);

        if ($dryRun) {
            $this->info('DRY-RUN: ничего не закрывается.');
        }
        $this->info(sprintf('Пороги (дней, 0=выкл): уточнение=%d · КП=%d · счёт=%d', $clarDays, $quoteDays, $invoiceDays));

        $closed = 0;
        $stats = ['clarification' => 0, 'quote' => 0, 'invoice' => 0];

        $reachedLimit = fn (): bool => $limit > 0 && $closed >= $limit;

        $close = function (Request $req, ClosedLostReason $reason, string $comment, string $bucket)
            use ($dryRun, $state, &$closed, &$stats): void {
            $this->line(sprintf('  %s [%s] → %s · %s', $req->internal_code, $req->status->value, $reason->value, $comment));
            if (! $dryRun) {
                $state->systemCloseLost($req, $reason, $comment);
                $this->notifyClosed($req);
            }
            $closed++;
            $stats[$bucket]++;
        };

        // (a) Молчание после уточняющего вопроса.
        if ($clarDays > 0 && ! $reachedLimit()) {
            $cutoff = now()->subDays($clarDays);
            $batches = ClarificationBatch::query()
                ->where('status', ClarificationBatch::STATUS_SENT)
                ->whereNull('answered_at')
                ->where('sent_at', '<', $cutoff)
                ->with('request')
                ->get();
            foreach ($batches as $batch) {
                if ($reachedLimit()) {
                    break;
                }
                $req = $batch->request;
                if (! $this->isClosable($req) || $req->status !== RequestStatus::AwaitingClientClarification) {
                    continue;
                }
                $close($req, ClosedLostReason::NoClientResponseToClarification,
                    sprintf('Клиент не ответил на уточнение более %d дн.', $clarDays), 'clarification');
            }
        }

        // (b) Молчание после КП. last_activity_at — таймер тишины: сбрасывается
        // при ответе клиента / действии менеджера.
        if ($quoteDays > 0 && ! $reachedLimit()) {
            $cutoff = now()->subDays($quoteDays);
            $reqs = Request::query()
                ->where('status', RequestStatus::Quoted->value)
                ->whereRaw('COALESCE(last_activity_at, updated_at) < ?', [$cutoff])
                ->get();
            foreach ($reqs as $req) {
                if ($reachedLimit()) {
                    break;
                }
                if (! $this->isClosable($req)) {
                    continue;
                }
                $close($req, ClosedLostReason::NoClientResponseToQuote,
                    sprintf('Клиент не отреагировал на КП более %d дн.', $quoteDays), 'quote');
            }
        }

        // (c) Счёт не оплачен N дней с выставления.
        if ($invoiceDays > 0 && ! $reachedLimit()) {
            $cutoff = now()->subDays($invoiceDays);
            $invoices = Invoice::query()
                ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Expired->value])
                ->whereNull('paid_at')
                ->where('issued_at', '<', $cutoff)
                ->with('request')
                ->orderBy('request_id')
                ->get();
            $seen = [];
            foreach ($invoices as $inv) {
                if ($reachedLimit()) {
                    break;
                }
                $req = $inv->request;
                if (! $this->isClosable($req)
                    || ! in_array($req->status, [RequestStatus::Invoiced, RequestStatus::AwaitingInvoice], true)) {
                    continue;
                }
                if (isset($seen[$req->id])) {
                    continue;
                }
                $seen[$req->id] = true;
                // Есть оплаченный счёт по заявке — не закрываем.
                if ($req->invoices()->where('status', InvoiceStatus::Paid->value)->exists()) {
                    continue;
                }
                $close($req, ClosedLostReason::InvoiceUnpaid,
                    sprintf('Счёт №%s не оплачен более %d дн. с выставления.', $inv->invoice_number, $invoiceDays), 'invoice');
            }
        }

        $this->info(sprintf(
            'Закрыто: уточнение=%d · КП=%d · счёт=%d (всего %d)%s',
            $stats['clarification'], $stats['quote'], $stats['invoice'], $closed,
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        return self::SUCCESS;
    }

    private function isClosable(?Request $req): bool
    {
        if ($req === null || $req->status->isTerminal()) {
            return false;
        }

        return ! in_array($req->status, self::SKIP_STATUSES, true);
    }

    private function notifyClosed(Request $req): void
    {
        try {
            $sc = RequestStateChange::query()
                ->where('request_id', $req->id)
                ->where('event', 'system_close_lost')
                ->latest('id')
                ->first();
            if ($sc !== null) {
                app(ClientNotificationService::class)->sendOrderClosedLost($req->fresh(), $sc);
            }
        } catch (\Throwable $e) {
            Log::warning('auto-close-inactive: client notification failed (non-fatal)', [
                'request_id' => $req->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
