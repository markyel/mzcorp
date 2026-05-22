<?php

namespace App\Services\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\RequestStatus;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Request;
use App\Models\User;
use App\Services\Calendar\RussianWorkingDayService;
use App\Services\Request\RequestStateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Бизнес-логика счетов (Phase 4).
 *
 * Счёт выставляется ВНЕ системы (1С), мы только трекаем номер + сроки.
 * Cron `invoices:check-expiry` помечает expired по сроку + возвращает
 * Request в AwaitingInvoice для re-issue.
 */
class InvoiceService
{
    public function __construct(
        private readonly RussianWorkingDayService $calendar,
        private readonly RequestStateService $stateService,
    ) {
    }

    /**
     * Выставить счёт от лица менеджера.
     *
     * @param  Request                $request
     * @param  string                 $invoiceNumber  Номер из 1С / бухгалтерии.
     * @param  Carbon                 $issuedAt       Дата выставления (timestamp).
     * @param  int                    $validityDays   Срок действия в рабочих днях.
     * @param  string|null            $comment        Свободный комментарий.
     * @param  User                   $author         Кто выставляет (для audit).
     */
    public function issue(
        Request $request,
        string $invoiceNumber,
        Carbon $issuedAt,
        int $validityDays,
        ?string $comment,
        User $author,
    ): Invoice {
        $expiresAt = $this->calendar->addBusinessDays($issuedAt, $validityDays)
            ->endOfDay()
            ->setTimezone(config('app.timezone'));

        // Snapshot total из последней sent quotation (или active draft если sent нет).
        $amountSnapshot = $this->resolveAmountSnapshot($request);

        return DB::transaction(function () use (
            $request, $invoiceNumber, $issuedAt, $expiresAt, $validityDays, $comment, $author, $amountSnapshot
        ) {
            $invoice = Invoice::create([
                'request_id' => $request->id,
                'invoice_number' => $invoiceNumber,
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'validity_days' => $validityDays,
                'status' => InvoiceStatus::Pending->value,
                'comment' => $comment,
                'created_by_user_id' => $author->id,
                'amount_snapshot' => $amountSnapshot,
            ]);

            // Перевод Request → Invoiced (если переход разрешён).
            // С Quoted/AwaitingInvoice/UnderReview/InProgress — разрешено по карте.
            try {
                $this->stateService->transitionTo(
                    $request,
                    RequestStatus::Invoiced,
                    $author,
                    [
                        'event' => 'invoice_issued',
                        'comment' => sprintf(
                            'Выставлен счёт №%s, действителен до %s.',
                            $invoiceNumber,
                            $expiresAt->format('d.m.Y')
                        ),
                        'payload' => [
                            'invoice_id' => $invoice->id,
                            'invoice_number' => $invoiceNumber,
                            'expires_at' => $expiresAt->toIso8601String(),
                        ],
                    ],
                );
            } catch (\Throwable $e) {
                // Non-fatal — Invoice уже сохранён, в логе фиксируем,
                // в UI менеджер увидит несоответствие статуса и сможет
                // вручную перевести через action-panel.
                Log::warning('InvoiceService::issue: transitionTo failed (non-fatal)', [
                    'request_id' => $request->id,
                    'current_status' => is_object($request->status) ? $request->status->value : $request->status,
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $invoice->fresh();
        });
    }

    /**
     * Пометить счёт оплаченным + перевести Request → Paid.
     */
    public function markPaid(Invoice $invoice, User $author): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Pending) {
            throw new \DomainException(sprintf(
                'Нельзя оплатить счёт в статусе %s. Допустимо только pending.',
                $invoice->status->value
            ));
        }

        return DB::transaction(function () use ($invoice, $author) {
            $invoice->update([
                'status' => InvoiceStatus::Paid->value,
                'paid_at' => now(),
                'paid_by_user_id' => $author->id,
            ]);

            try {
                $this->stateService->transitionTo(
                    $invoice->request,
                    RequestStatus::Paid,
                    $author,
                    [
                        'event' => 'invoice_paid',
                        'comment' => sprintf('Счёт №%s оплачен.', $invoice->invoice_number),
                        'payload' => [
                            'invoice_id' => $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                        ],
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('InvoiceService::markPaid: transitionTo failed (non-fatal)', [
                    'request_id' => $invoice->request_id,
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $invoice->fresh();
        });
    }

    /**
     * Аннулировать счёт вручную (ещё до expiry). Request возвращается
     * в AwaitingInvoice если у него больше нет pending invoices.
     */
    public function cancel(Invoice $invoice, ?string $reason, User $author): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Pending) {
            throw new \DomainException('Можно аннулировать только pending счёт.');
        }

        return DB::transaction(function () use ($invoice, $reason, $author) {
            $invoice->update([
                'status' => InvoiceStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->maybeTransitionToAwaitingInvoice($invoice, $author);

            return $invoice->fresh();
        });
    }

    /**
     * Помечает invoice как expired и возвращает Request в AwaitingInvoice
     * (если у Request нет других pending invoices).
     *
     * Вызывается из cron `invoices:check-expiry`. Author=null означает
     * системный actor — RequestStateService должен пропустить permission.
     */
    public function expire(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Pending) {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::Expired->value,
            ]);

            $this->maybeTransitionToAwaitingInvoice($invoice, null, systemTransition: true);

            return $invoice->fresh();
        });
    }

    /**
     * Перевести Request → AwaitingInvoice если все её Invoice'ы в финальных
     * статусах (нет pending). Используется после cancel / expire.
     */
    private function maybeTransitionToAwaitingInvoice(
        Invoice $invoice,
        ?User $author,
        bool $systemTransition = false,
    ): void {
        $request = $invoice->request;
        if (! $request) {
            return;
        }

        $hasPending = Invoice::where('request_id', $request->id)
            ->where('status', InvoiceStatus::Pending->value)
            ->where('id', '!=', $invoice->id)
            ->exists();

        if ($hasPending) {
            return; // ещё есть актуальный счёт — Request не трогаем
        }

        $currentStatus = is_object($request->status) ? $request->status : RequestStatus::tryFrom((string) $request->status);
        if ($currentStatus === RequestStatus::AwaitingInvoice
            || $currentStatus?->isTerminal()) {
            return; // уже там / финал
        }

        try {
            $this->stateService->transitionTo(
                $request,
                RequestStatus::AwaitingInvoice,
                $author,
                [
                    'event' => $invoice->status === InvoiceStatus::Expired ? 'invoice_expired' : 'invoice_cancelled',
                    'comment' => sprintf(
                        'Счёт №%s %s. Заявка возвращена в ожидание счёта.',
                        $invoice->invoice_number,
                        $invoice->status === InvoiceStatus::Expired ? 'просрочен' : 'аннулирован'
                    ),
                    'payload' => [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                    ],
                ],
                systemTransition: $systemTransition,
            );
        } catch (\Throwable $e) {
            Log::warning('InvoiceService::maybeTransitionToAwaitingInvoice failed (non-fatal)', [
                'request_id' => $request->id,
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Total из последней sent quotation (для UI snapshot). Если sent нет —
     * берём active draft. Null если quotation вообще нет.
     */
    private function resolveAmountSnapshot(Request $request): ?float
    {
        $q = $request->quotations()
            ->whereIn('status', ['sent', 'accepted'])
            ->orderByDesc('version')
            ->first();

        if (! $q) {
            $q = $request->quotations()
                ->where('status', 'draft')
                ->orderByDesc('version')
                ->first();
        }

        return $q ? (float) $q->total : null;
    }
}
