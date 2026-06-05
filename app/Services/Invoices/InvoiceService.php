<?php

namespace App\Services\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
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
     * Автосоздать счёт из распарсенного исходящего документа
     * (OutboundQuote с document_type=outbound_invoice).
     *
     * Идемпотентно: если для заявки уже есть Invoice по этому email-сообщению
     * или с таким же invoice_number — возвращает существующий, ничего не пишет.
     * Если document_number пуст (парсер не вытащил номер) — пропускает
     * с лог-warning'ом: менеджеру лучше выставить вручную.
     *
     * Перевод Request → Invoiced — best-effort: если карта переходов
     * не разрешает (заявка уже Paid/Closed), оставляем Invoice созданным,
     * статус не трогаем.
     */
    public function autoIssueFromOutboundQuote(\App\Models\OutboundQuote $quote): ?Invoice
    {
        $documentType = $quote->document_type?->value ?? null;
        if ($documentType !== \App\Enums\DetectorType::OutboundInvoice->value) {
            return null;
        }

        // Guard: вложение по имени — договор / спецификация (приложение к
        // договору), а не счёт на оплату. Детектор уровня письма штампует ВСЕ
        // парсимые вложения invoice-письма как outbound_invoice, поэтому
        // приложенные к счёту договор/спецификация тоже доходят сюда и плодят
        // фантомный счёт, который никогда не оплатят (тикет M-2026-2582:
        // «Договор … (счет 5687).pdf» рядом со «Счет МЗ-5687.pdf»). Имя файла —
        // самый надёжный сигнал; счёт-файл («Счёт …») сюда не попадает.
        if ($this->looksLikeContractDocument($quote)) {
            Log::info('InvoiceService::autoIssueFromOutboundQuote: skip — attachment is contract/spec, not an invoice', [
                'outbound_quote_id' => $quote->id,
                'request_id' => $quote->request_id,
                'email_attachment_id' => $quote->email_attachment_id,
            ]);

            return null;
        }

        $number = trim((string) ($quote->document_number ?? ''));
        if ($number === '') {
            Log::warning('InvoiceService::autoIssueFromOutboundQuote: skip — no document_number', [
                'outbound_quote_id' => $quote->id,
                'request_id' => $quote->request_id,
            ]);
            return null;
        }
        $request = $quote->request;
        if (! $request) {
            return null;
        }

        // Идемпотентность: тот же email-источник или тот же номер у этой заявки.
        $existing = Invoice::where('request_id', $request->id)
            ->where(function ($q) use ($quote, $number) {
                if ($quote->email_message_id) {
                    $q->where('email_message_id', $quote->email_message_id);
                }
                $q->orWhere('invoice_number', $number);
            })
            ->first();
        if ($existing) {
            // Несколько счетов с одним номером в переписке: ориентируемся на
            // ПОСЛЕДНИЙ отправленный (тикет M-2026-1824 — счёт МЗ-5668
            // переотправляли с разными суммами). Если входящий документ свежее
            // источника существующего счёта — обновляем сумму/дату/срок/
            // источник, оставаясь одной записью. Тот же/старый источник — no-op.
            $this->refreshFromNewerQuote($existing, $quote, $number);

            return $existing->fresh();
        }

        $issuedAt = $quote->document_date
            ? Carbon::parse((string) $quote->document_date)
            : now();

        // Author: менеджер заявки (если есть), иначе первый admin для audit.
        $author = $request->assignedUser
            ?? \App\Models\User::role(\App\Enums\Role::Admin->value)->first();
        if (! $author) {
            Log::warning('InvoiceService::autoIssueFromOutboundQuote: skip — no author', [
                'outbound_quote_id' => $quote->id,
                'request_id' => $request->id,
            ]);
            return null;
        }

        $validityDays = (int) config('services.invoices.default_validity_business_days', 5);
        $expiresAt = $this->calendar->addBusinessDays($issuedAt, $validityDays)
            ->endOfDay()
            ->setTimezone(config('app.timezone'));

        $amountSnapshot = $quote->total_amount !== null
            ? (float) $quote->total_amount
            : $this->resolveAmountSnapshot($request);

        return DB::transaction(function () use (
            $request, $quote, $number, $issuedAt, $expiresAt, $validityDays, $author, $amountSnapshot
        ) {
            $invoice = Invoice::create([
                'request_id' => $request->id,
                'invoice_number' => mb_substr($number, 0, 128),
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'validity_days' => $validityDays,
                'status' => InvoiceStatus::Pending->value,
                'comment' => sprintf(
                    'Автосоздан из исходящего письма (документ #%d, %s от %s, %s ₽).',
                    $quote->id,
                    $number,
                    $issuedAt->format('d.m.Y'),
                    $amountSnapshot !== null ? number_format($amountSnapshot, 2, '.', ' ') : '—'
                ),
                'created_by_user_id' => $author->id,
                'email_message_id' => $quote->email_message_id,
                'amount_snapshot' => $amountSnapshot,
            ]);

            // Перевод Request → Invoiced если карта переходов разрешает.
            try {
                $this->stateService->transitionTo(
                    $request,
                    RequestStatus::Invoiced,
                    $author,
                    [
                        'event' => 'invoice_auto_issued',
                        'comment' => sprintf('Автосоздан счёт №%s из исходящего письма.', $number),
                        'payload' => [
                            'invoice_id' => $invoice->id,
                            'invoice_number' => $number,
                            'outbound_quote_id' => $quote->id,
                        ],
                    ],
                );
            } catch (\Throwable $e) {
                Log::info('InvoiceService::autoIssueFromOutboundQuote: transitionTo skipped', [
                    'request_id' => $request->id,
                    'current_status' => is_object($request->status) ? $request->status->value : $request->status,
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('InvoiceService: auto-issued invoice from outbound quote', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'outbound_quote_id' => $quote->id,
                'request_id' => $request->id,
                'amount' => $amountSnapshot,
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * Обновить существующий счёт значениями из более свежего исходящего
     * документа с тем же номером («последний отправленный счёт», M-2026-1824).
     *
     * Трогаем только pending-счёт (оплаченный / аннулированный / истёкший —
     * историчны). «Свежее» определяем по времени исходящего письма-источника.
     */
    private function refreshFromNewerQuote(Invoice $existing, \App\Models\OutboundQuote $quote, string $number): void
    {
        if ($existing->status !== InvoiceStatus::Pending) {
            return;
        }

        $incoming = $quote->email_message_id ? EmailMessage::find($quote->email_message_id) : null;
        $current = $existing->email_message_id ? EmailMessage::find($existing->email_message_id) : null;
        if (! $this->isNewerSource($incoming, $current)) {
            return;
        }

        $issuedAt = $quote->document_date ? Carbon::parse((string) $quote->document_date) : now();
        $validityDays = (int) config('services.invoices.default_validity_business_days', 5);
        $expiresAt = $this->calendar->addBusinessDays($issuedAt, $validityDays)
            ->endOfDay()
            ->setTimezone(config('app.timezone'));
        $amount = $quote->total_amount !== null ? (float) $quote->total_amount : $existing->amount_snapshot;

        $existing->update([
            'invoice_number' => mb_substr($number, 0, 128),
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'validity_days' => $validityDays,
            'amount_snapshot' => $amount,
            'email_message_id' => $quote->email_message_id,
            'comment' => sprintf(
                'Обновлён по последнему отправленному счёту (документ #%d, %s от %s, %s ₽).',
                $quote->id,
                $number,
                $issuedAt->format('d.m.Y'),
                $amount !== null ? number_format((float) $amount, 2, '.', ' ') : '—'
            ),
        ]);

        Log::info('InvoiceService: refreshed invoice from newer outbound quote', [
            'invoice_id' => $existing->id,
            'invoice_number' => $number,
            'outbound_quote_id' => $quote->id,
            'request_id' => $existing->request_id,
            'amount' => $amount,
        ]);
    }

    /**
     * Источник $incoming свежее, чем $current? «Свежесть» — по sent_at письма,
     * при равных/неизвестных датах — по id письма (позже пришедшее = больший id).
     * Тот же источник (равный id) — НЕ свежее (защита от churn при reparse).
     */
    private function isNewerSource(?EmailMessage $incoming, ?EmailMessage $current): bool
    {
        if ($incoming === null) {
            return false;
        }
        if ($current === null) {
            return true;
        }
        if ($incoming->id === $current->id) {
            return false;
        }
        $a = $incoming->sent_at;
        $b = $current->sent_at;
        if ($a !== null && $b !== null && ! $a->equalTo($b)) {
            return $a->greaterThan($b);
        }

        return $incoming->id > $current->id;
    }

    /**
     * Имя вложения указывает на договор / спецификацию (приложение к договору),
     * а не на счёт на оплату. Файл-счёт («Счёт …» / «Инвойс …») сюда НЕ
     * попадает, даже если в его имени в скобках мелькает «(счет 5687)».
     * См. тикет M-2026-2582.
     */
    private function looksLikeContractDocument(\App\Models\OutboundQuote $quote): bool
    {
        if (! $quote->email_attachment_id) {
            return false;
        }
        $attachment = \App\Models\EmailAttachment::find($quote->email_attachment_id);

        return self::isContractOrSpecFilename((string) ($attachment->filename ?? ''));
    }

    /**
     * Имя файла — договор / спецификация (приложение к счёту), а не сам счёт.
     * Pure-функция (без БД) — вынесена для тестируемости.
     *
     * Ловит и полное «спецификац…», и сокращение «Спец» как отдельный токен
     * («Спец 31.pdf», «Спец_31», «Спец.pdf») — тикет M-2026-1797: счёт
     * получил номер спецификации (31) вместо номера счёта (5742), т.к.
     * «Спец 31.pdf» не подпадал под `спецификац` и доходил до создания Invoice.
     * Токен-граница ([^а-яё]) исключает ложные срабатывания на «специальн…»,
     * «спецодежда» и т.п.
     */
    public static function isContractOrSpecFilename(string $filename): bool
    {
        $name = mb_strtolower(trim($filename));
        if ($name === '') {
            return false;
        }

        // Сам файл — счёт на оплату (имя начинается со «счёт/счет/инвойс/invoice»):
        // не трогаем, это и есть инвойс.
        if (preg_match('/^(сч[ёе]т|инвойс|invoice)/u', $name)) {
            return false;
        }

        return str_contains($name, 'договор')
            || str_contains($name, 'спецификац')
            || preg_match('/(^|[^а-яё])спец([^а-яё]|$)/u', $name) === 1;
    }

    /**
     * Статусы, из которых счёт можно пометить оплаченным.
     * Pending — обычная оплата; Expired — оплата с опозданием (клиент заплатил
     * после истечения срока, заявку всё равно закрываем оплатой).
     * Cancelled — реанимация: счёт аннулировали по просрочке/закрытию заявки,
     * но клиент всё-таки оплатил, а заявку вернули в работу (тикет M-2026-2195).
     * Paid — терминальный, повторно не оплачиваем.
     */
    public const PAYABLE_STATUSES = [InvoiceStatus::Pending, InvoiceStatus::Expired, InvoiceStatus::Cancelled];

    /**
     * Пометить счёт оплаченным + перевести Request → Paid.
     */
    public function markPaid(Invoice $invoice, User $author): Invoice
    {
        if (! in_array($invoice->status, self::PAYABLE_STATUSES, true)) {
            throw new \DomainException(sprintf(
                'Нельзя оплатить счёт в статусе %s. Допустимо pending, expired или cancelled.',
                $invoice->status->value
            ));
        }

        // Реанимация ранее аннулированного счёта: снимаем отметку аннулирования,
        // чтобы статус/комментарий в UI не противоречили (был «отменён» → стал
        // «оплачен»). Сам факт оплаты фиксируется в request_state_changes.
        $wasCancelled = $invoice->status === InvoiceStatus::Cancelled;

        return DB::transaction(function () use ($invoice, $author, $wasCancelled) {
            $invoice->update([
                'status' => InvoiceStatus::Paid->value,
                'paid_at' => now(),
                'paid_by_user_id' => $author->id,
                'cancelled_at' => $wasCancelled ? null : $invoice->cancelled_at,
                'cancellation_reason' => $wasCancelled ? null : $invoice->cancellation_reason,
            ]);

            try {
                $this->stateService->transitionTo(
                    $invoice->request,
                    RequestStatus::Paid,
                    $author,
                    [
                        'event' => 'invoice_paid',
                        'comment' => $wasCancelled
                            ? sprintf('Ранее аннулированный счёт №%s оплачен (реанимация).', $invoice->invoice_number)
                            : sprintf('Счёт №%s оплачен.', $invoice->invoice_number),
                        'payload' => [
                            'invoice_id' => $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                            'reanimated' => $wasCancelled,
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
     * Массовая оплата: помечает оплаченными переданные счета (каждый — через
     * markPaid, со своей транзакцией и переводом заявки в Paid). Авторизацию
     * НЕ проверяет — вызывающий обязан передать только доступные счета. Ошибки
     * по отдельному счёту (например, статус уже не payable) не валят весь батч.
     *
     * @param  iterable<\App\Models\Invoice>  $invoices
     * @return array{paid: int, failed: array<int, array{number: string, error: string}>}
     */
    public function bulkMarkPaid(iterable $invoices, User $author): array
    {
        $paid = 0;
        $failed = [];

        foreach ($invoices as $invoice) {
            try {
                $this->markPaid($invoice, $author);
                $paid++;
            } catch (\Throwable $e) {
                $failed[] = [
                    'number' => (string) $invoice->invoice_number,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['paid' => $paid, 'failed' => $failed];
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
