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
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
    /**
     * Счёт с этим номером (по числовой части) уже привязан к ДРУГОЙ заявке?
     * Политика «первая привязка выигрывает»: счёт остаётся за заявкой, к
     * которой был прикреплён первым; повторные привязки — дубли, не создаём.
     * Аннулированные копии не считаем (их уже разжаловали).
     */
    public function findDuplicateOnOtherRequest(string $invoiceNumber, int $requestId): ?Invoice
    {
        if (preg_match('/(\d+)\s*$/', trim($invoiceNumber), $m) !== 1) {
            return null;
        }
        $num = (int) ltrim($m[1], '0');
        if ($num <= 0) {
            return null;
        }

        return Invoice::query()
            ->with('request:id,internal_code')
            ->whereRaw("nullif(regexp_replace(invoice_number, '\\D', '', 'g'), '')::bigint = ?", [$num])
            ->where('request_id', '!=', $requestId)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->orderBy('id')
            ->first();
    }

    /**
     * Счёт с этим номером уже в другой заявке — но по реквизитам покупателя он
     * принадлежит этой: ИНН из документа совпадает с организацией клиента
     * этой заявки и не совпадает с той. Оплаченный и частично оплаченный счёт
     * не переносим — за ним уже деньги и закрытая сделка.
     */
    private function shouldMoveDuplicateHere(\App\Models\OutboundQuote $quote, Request $request, Invoice $dup): bool
    {
        if (in_array($dup->status, [InvoiceStatus::Paid, InvoiceStatus::PartiallyPaid], true)) {
            return false;
        }
        $inn = trim((string) (($quote->payload ?? [])['requisites_buyer_inn'] ?? ''));
        if ($inn === '') {
            return false;
        }
        // Переезжает только гонка разбора: письмо покупателю ушло РАНЬШЕ, чем
        // письмо, по которому счёт записан сейчас. Старый счёт, переотправленный
        // позже в новой переписке (сверка, «вот счёт по прошлому заказу»), так
        // и остаётся в своей заявке — M-2026-11318 собрал бы три чужих счёта.
        $sentHere = EmailMessage::withHistory()->whereKey($quote->email_message_id)->value('sent_at');
        $sentThere = EmailMessage::withHistory()->whereKey($dup->email_message_id)->value('sent_at');
        if ($sentHere === null || $sentThere === null || Carbon::parse($sentHere)->gt(Carbon::parse($sentThere))) {
            return false;
        }

        $other = Request::query()->find($dup->request_id);

        return $this->requestBuyerInn($request, $inn) && ($other === null || ! $this->requestBuyerInn($other, $inn));
    }

    /**
     * Сделка ожила: счёт лежит в заявке, закрытой отказом, и тот же клиент
     * получил его заново — позже, уже в новой заявке. M-2026-16626: по счёту
     * 9254 заказчик отказался 11.09 (M-2026-14409 закрыта), 21.09 «заказчик
     * опять вернулся», менеджер переотправил 9254 «под заказ» — защита от
     * дублей оставила счёт в закрытой заявке, а новая неделю висела «Назначена».
     */
    private function shouldReviveDuplicateHere(\App\Models\OutboundQuote $quote, Request $request, Invoice $dup): bool
    {
        if (! in_array($dup->status, [InvoiceStatus::Pending, InvoiceStatus::Expired], true)) {
            return false;
        }
        $other = Request::query()->find($dup->request_id);
        if ($other === null || $other->status !== RequestStatus::ClosedLost) {
            return false;
        }
        $email = mb_strtolower(trim((string) $request->client_email));
        if ($email === '' || $email !== mb_strtolower(trim((string) $other->client_email))) {
            return false;
        }
        $sentHere = EmailMessage::withHistory()->whereKey($quote->email_message_id)->value('sent_at');
        $sentThere = EmailMessage::withHistory()->whereKey($dup->email_message_id)->value('sent_at');

        return $sentHere !== null && $sentThere !== null && Carbon::parse($sentHere)->gt(Carbon::parse($sentThere));
    }

    /**
     * Сквозной счёт: менеджер ведёт для клиента один счёт и дописывает в него
     * новые заказы, отправляя новую версию в тред каждой заявки. M-2026-17478:
     * счёт 10339 ушёл tk-e.ru в пяти заявках (01.10–05.10, 10 → 293 тыс. ₽), а
     * защита от дублей оставила его только в первой — остальные висели «В
     * работе». Признаки: заявка не закрыта, тот же клиент, другое письмо (не
     * копия одного письма в двух тредах) и это другая версия документа — иная
     * дата или сумма. Переотправка старого счёта без изменений сюда не
     * попадает (M-2026-11318).
     */
    private function isCumulativeRevision(\App\Models\OutboundQuote $quote, Request $request, Invoice $dup): bool
    {
        if (! in_array($dup->status, [InvoiceStatus::Pending, InvoiceStatus::Expired], true)) {
            return false;
        }
        // Закрытую заявку не трогаем: оплата сквозного счёта иначе воскресит
        // заявку, которую менеджер закрыл сам.
        if ($request->status->isTerminal()) {
            return false;
        }
        $other = Request::query()->find($dup->request_id);
        if ($other === null) {
            return false;
        }
        $email = mb_strtolower(trim((string) $request->client_email));
        $sameClient = ($email !== '' && $email === mb_strtolower(trim((string) $other->client_email)))
            || ($request->organization_id !== null && $request->organization_id === $other->organization_id);
        if (! $sameClient) {
            return false;
        }

        $here = $quote->email_message_id ? EmailMessage::withHistory()->find($quote->email_message_id) : null;
        $there = $dup->email_message_id ? EmailMessage::withHistory()->find($dup->email_message_id) : null;
        // Одно письмо, разложенное в два треда, — не новая версия. Порядок
        // отправки не важен: источник счёта уже мог обновиться до более
        // поздней версии из третьей заявки.
        if ($here === null || $there === null || $here->id === $there->id
            || ($here->message_id !== null && $here->message_id === $there->message_id)) {
            return false;
        }

        $newerDate = $quote->document_date !== null && $dup->issued_at !== null
            && Carbon::parse((string) $quote->document_date)->startOfDay()->greaterThan($dup->issued_at->copy()->startOfDay());
        $otherSum = $quote->total_amount !== null && $dup->amount_snapshot !== null
            && abs((float) $quote->total_amount - (float) $dup->amount_snapshot) > 1.0;

        return $newerDate || $otherSum;
    }

    /**
     * Заявка входит в сквозной счёт: запись счёта остаётся одна (в первой
     * заявке, сумма — по последней версии), эта заявка переходит в «Счёт
     * отправлен» со ссылкой на него. Оплата счёта закрывает все такие заявки
     * (transitionAfterPayment → sharedRequestIds). Отдельную запись не
     * создаём — иначе сумма сквозного счёта задвоится в аналитике.
     */
    private function shareCumulativeInvoice(Invoice $dup, \App\Models\OutboundQuote $quote, Request $request, string $number): Invoice
    {
        $this->refreshFromNewerQuote($dup, $quote, $number);
        $dup->refresh();

        $author = $request->assignedUser
            ?? \App\Models\User::role(\App\Enums\Role::Admin->value)->first();
        $primary = $dup->request?->internal_code ?? ('#'.$dup->request_id);
        $context = [
            'event' => 'invoice_shared',
            'comment' => sprintf(
                'Сквозной счёт №%s: тот же счёт, что в %s, дополнен позициями этой заявки (%s ₽).',
                $number,
                $primary,
                $quote->total_amount !== null ? number_format((float) $quote->total_amount, 2, '.', ' ') : '—',
            ),
            'payload' => [
                'invoice_id' => $dup->id,
                'invoice_number' => $number,
                'primary_request_id' => $dup->request_id,
                'outbound_quote_id' => $quote->id,
            ],
        ];

        try {
            if ($author !== null && in_array(RequestStatus::Invoiced, $request->status->allowedTransitions(), true)) {
                $this->stateService->transitionTo($request, RequestStatus::Invoiced, $author, $context);
            } else {
                \App\Models\RequestStateChange::create([
                    'request_id' => $request->id,
                    'from_status' => $request->status->value,
                    'to_status' => $request->status->value,
                    'by_user_id' => null,
                ] + $context);
            }
        } catch (\Throwable $e) {
            Log::info('InvoiceService: shared invoice transition skipped', [
                'request_id' => $request->id,
                'invoice_id' => $dup->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('InvoiceService: cumulative invoice shared with another request', [
            'invoice_id' => $dup->id,
            'invoice_number' => $number,
            'primary_request_id' => $dup->request_id,
            'request_id' => $request->id,
            'outbound_quote_id' => $quote->id,
        ]);

        return $dup;
    }

    /**
     * Заявки, вошедшие в сквозной счёт (событие invoice_shared), кроме заявки
     * самого счёта.
     *
     * @return list<int>
     */
    public function sharedRequestIds(Invoice $invoice): array
    {
        return \App\Models\RequestStateChange::query()
            ->where('event', 'invoice_shared')
            ->whereRaw("(payload->>'invoice_id')::bigint = ?", [$invoice->id])
            ->where('request_id', '!=', $invoice->request_id)
            ->distinct()
            ->pluck('request_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Клиент заявки — эта организация: сама заявка к ней привязана или адрес клиента связан с ней. */
    private function requestBuyerInn(Request $request, string $inn): bool
    {
        if ($request->organization_id !== null
            && \App\Models\Organization::query()->whereKey($request->organization_id)->where('inn', $inn)->exists()) {
            return true;
        }
        $email = mb_strtolower(trim((string) $request->client_email));

        return $email !== '' && \App\Models\ClientContact::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->whereHas('organizations', fn ($q) => $q->where('inn', $inn))
            ->exists();
    }

    /**
     * Менеджер отметил: счёт отправлен в этой заявке по ошибке.
     *
     * Если тот же счёт отправлен и в другой заявке (там есть разобранный
     * документ с этим номером) — счёт переезжает туда, предпочтительно в
     * заявку, чей клиент — покупатель по реквизитам. Иначе аннулируется с
     * причиной «отправлен по ошибке». Эта заявка возвращается в статус до
     * счёта, если других действующих счетов в ней нет. Незакрытое
     * предупреждение о сомнительных реквизитах по этому счёту — отклоняется:
     * реквизиты ушли не тому клиенту, привязывать нечего.
     *
     * @return array{moved_to: ?Request, cancelled: bool}
     */
    public function detachMisdirected(Invoice $invoice, User $author): array
    {
        if (in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::PartiallyPaid], true)) {
            throw new \DomainException('Оплаченный счёт отвязать нельзя.');
        }
        $from = $invoice->request;
        if (! $from) {
            throw new \DomainException('Счёт не привязан к заявке.');
        }

        $number = (string) $invoice->invoice_number;
        $digits = preg_replace('/\D+/', '', $number);
        $candidates = \App\Models\OutboundQuote::query()
            ->where('document_type', \App\Enums\DetectorType::OutboundInvoice->value)
            ->where('request_id', '!=', $from->id)
            ->whereNotNull('request_id')
            ->whereRaw("regexp_replace(coalesce(document_number, ''), '\\D', '', 'g') = ?", [$digits])
            ->orderByDesc('id')
            ->get();
        $target = $candidates->first(function ($q) {
            $inn = trim((string) (($q->payload ?? [])['requisites_buyer_inn'] ?? ''));

            return $inn !== '' && $q->request && $this->requestBuyerInn($q->request, $inn);
        }) ?? $candidates->first();

        // Статус до счёта — из последнего НАСТОЯЩЕГО перехода в «Счёт
        // отправлен» (записи invoiced→invoiced — это аудит без смены статуса).
        $before = \App\Models\RequestStateChange::query()
            ->where('request_id', $from->id)
            ->where('to_status', RequestStatus::Invoiced->value)
            ->where('from_status', '!=', RequestStatus::Invoiced->value)
            ->orderByDesc('id')
            ->value('from_status');

        $movedTo = null;
        DB::transaction(function () use ($invoice, $from, $target, $author, $number, &$movedTo) {
            if ($target !== null) {
                $movedTo = $target->request;
                $invoice->forceFill(['request_id' => $movedTo->id, 'email_message_id' => $target->email_message_id])->save();
                \App\Models\RequestStateChange::create([
                    'request_id' => $from->id,
                    'from_status' => $from->status->value,
                    'to_status' => $from->status->value,
                    'by_user_id' => $author->id,
                    'event' => 'invoice_misdirected',
                    'comment' => sprintf('Счёт №%s отправлен сюда по ошибке — перенесён в заявку %s, где он тоже отправлен.', $number, $movedTo->internal_code),
                    'payload' => ['invoice_id' => $invoice->id, 'to_request_id' => $movedTo->id],
                ]);
                try {
                    $this->stateService->transitionTo($movedTo, RequestStatus::Invoiced, $author, [
                        'event' => 'invoice_auto_issued',
                        'comment' => sprintf('Счёт №%s перенесён из заявки %s: там он был отправлен по ошибке.', $number, $from->internal_code),
                        'payload' => ['invoice_id' => $invoice->id, 'moved_from_request_id' => $from->id],
                    ], systemTransition: true);
                } catch (\Throwable $e) {
                    Log::info('InvoiceService::detachMisdirected: target status unchanged', ['request_id' => $movedTo->id, 'error' => $e->getMessage()]);
                }
            } else {
                $invoice->forceFill([
                    'status' => InvoiceStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'Отправлен по ошибке',
                ])->save();
                \App\Models\RequestStateChange::create([
                    'request_id' => $from->id,
                    'from_status' => $from->status->value,
                    'to_status' => $from->status->value,
                    'by_user_id' => $author->id,
                    'event' => 'invoice_misdirected',
                    'comment' => sprintf('Счёт №%s отправлен по ошибке — аннулирован.', $number),
                    'payload' => ['invoice_id' => $invoice->id],
                ]);
            }

            foreach (\App\Models\OrganizationLinkRequest::query()
                ->where('request_id', $from->id)
                ->where('document_number', $number)
                ->where('status', \App\Enums\OrganizationLinkStatus::Pending->value)
                ->get() as $pending) {
                app(\App\Services\Clients\OrganizationLinkGuard::class)->reject($pending, $author);
            }
        });

        // Эта заявка — назад в статус до счёта, если действующих счетов не осталось.
        $from->refresh();
        $stillHas = Invoice::query()->where('request_id', $from->id)
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Paid->value, InvoiceStatus::PartiallyPaid->value])
            ->exists();
        // Карта переходов из «Счёт отправлен» назад не пускает (только «В
        // работе», оплата, закрытие), а исправление ошибки — это именно откат
        // к прежнему статусу. Пишем его напрямую, с записью в истории.
        $back = RequestStatus::tryFrom((string) $before) ?? RequestStatus::InProgress;
        if (! $stillHas && $from->status === RequestStatus::Invoiced && $back !== RequestStatus::Invoiced) {
            DB::transaction(function () use ($from, $back, $author, $number, $invoice) {
                \App\Models\RequestStateChange::create([
                    'request_id' => $from->id,
                    'from_status' => RequestStatus::Invoiced->value,
                    'to_status' => $back->value,
                    'by_user_id' => $author->id,
                    'event' => 'invoice_misdirected',
                    'comment' => sprintf('Счёт №%s был отправлен по ошибке — заявка возвращена в статус до счёта.', $number),
                    'payload' => ['invoice_id' => $invoice->id],
                ]);
                $from->forceFill(['status' => $back->value])->save();
                app(\App\Services\Request\AttentionService::class)->recompute($from->fresh());
            });
        }

        return ['moved_to' => $movedTo, 'cancelled' => $movedTo === null];
    }

    /**
     * Перенести счёт в эту заявку, с записью в истории обеих.
     * $revived — сделка ожила (shouldReviveDuplicateHere): счёт снова
     * действующий, дата и срок — по переотправленному документу.
     */
    private function moveDuplicateHere(Invoice $dup, \App\Models\OutboundQuote $quote, Request $request, string $number, bool $revived = false): Invoice
    {
        $fromRequest = Request::query()->find($dup->request_id);
        $author = $request->assignedUser ?? \App\Models\User::role(\App\Enums\Role::Admin->value)->first();
        $inn = (string) (($quote->payload ?? [])['requisites_buyer_inn'] ?? '');
        $outComment = $revived
            ? sprintf('Счёт №%s переотправлен тому же клиенту в заявке %s — сделка ожила, счёт перенесён туда.', $number, $request->internal_code)
            : sprintf('Счёт №%s перенесён в заявку %s: покупатель по реквизитам (ИНН %s) — клиент той заявки.', $number, $request->internal_code, $inn);
        $inComment = $revived
            ? sprintf('Счёт №%s из закрытой отказом заявки %s переотправлен клиенту — сделка ожила.', $number, $fromRequest?->internal_code ?? '—')
            : sprintf('Счёт №%s перенесён из заявки %s: покупатель по реквизитам (ИНН %s) — клиент этой заявки.', $number, $fromRequest?->internal_code ?? '—', $inn);

        DB::transaction(function () use ($dup, $quote, $request, $fromRequest, $number, $author, $revived, $outComment, $inComment) {
            $fill = ['request_id' => $request->id, 'email_message_id' => $quote->email_message_id];
            if ($revived) {
                $issuedAt = $quote->document_date ? Carbon::parse((string) $quote->document_date) : now();
                [$expiresAt, $validityDays] = self::computeExpiry(
                    $this->calendar,
                    $issuedAt,
                    $quote->valid_until,
                    (int) config('services.invoices.default_validity_business_days', 5),
                );
                $fill += [
                    'status' => InvoiceStatus::Pending,
                    'issued_at' => $issuedAt,
                    'expires_at' => $expiresAt,
                    'validity_days' => $validityDays,
                    'amount_snapshot' => $quote->total_amount !== null ? (float) $quote->total_amount : $dup->amount_snapshot,
                ];
            }
            $dup->forceFill($fill)->save();

            if ($fromRequest) {
                \App\Models\RequestStateChange::create([
                    'request_id' => $fromRequest->id,
                    'from_status' => $fromRequest->status->value,
                    'to_status' => $fromRequest->status->value,
                    'by_user_id' => null,
                    'event' => 'invoice_moved_out',
                    'comment' => $outComment,
                    'payload' => ['invoice_id' => $dup->id, 'to_request_id' => $request->id],
                ]);
            }

            try {
                $this->stateService->transitionTo($request, RequestStatus::Invoiced, $author, [
                    'event' => 'invoice_auto_issued',
                    'comment' => $inComment,
                    'payload' => ['invoice_id' => $dup->id, 'invoice_number' => $number, 'outbound_quote_id' => $quote->id, 'moved_from_request_id' => $fromRequest?->id],
                ], systemTransition: true);
            } catch (\Throwable $e) {
                Log::info('InvoiceService: moved invoice, status not changed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            }
        });

        Log::info('InvoiceService: duplicate invoice moved to the buyer\'s request', [
            'invoice_id' => $dup->id, 'number' => $number, 'from_request_id' => $fromRequest?->id, 'to_request_id' => $request->id, 'inn' => $inn,
        ]);

        return $dup->fresh();
    }

    public function issue(
        Request $request,
        string $invoiceNumber,
        Carbon $issuedAt,
        int $validityDays,
        ?string $comment,
        User $author,
    ): Invoice {
        $dup = $this->findDuplicateOnOtherRequest($invoiceNumber, $request->id);
        if ($dup !== null) {
            throw new \DomainException(sprintf(
                'Счёт №%s уже привязан к заявке %s — дубль не создан. Если счёт должен быть здесь, сначала аннулируйте его там.',
                $invoiceNumber,
                $dup->request?->internal_code ?? ('#'.$dup->request_id),
            ));
        }

        $expiresAt = $this->calendar->addBusinessDays($issuedAt, $validityDays)
            ->endOfDay()
            ->setTimezone(config('app.timezone'));

        // Snapshot total из последней sent quotation (или active draft если sent нет).
        $amountSnapshot = $this->resolveAmountSnapshot($request);

        $invoice = DB::transaction(function () use (
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

        return $this->applyExternalPaymentIfAny($invoice);
    }

    /**
     * Автопривязка внешней оплаты: если по номеру только что созданного счёта
     * в журнале импорта 1С уже есть «внешняя» оплата (счёт оплачен по банку
     * раньше, чем появился в CRM) — сразу отмечаем оплату. Fail-soft.
     */
    private function applyExternalPaymentIfAny(Invoice $invoice): Invoice
    {
        try {
            if (app(PaymentImportService::class)->tryApplyExternalPayment($invoice)) {
                return $invoice->fresh();
            }
        } catch (\Throwable $e) {
            Log::warning('InvoiceService: external payment auto-apply failed (non-fatal)', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $invoice;
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

        // Защита от дублей: этот номер уже привязан к другой заявке — «первая
        // привязка выигрывает», здесь копию не создаём. След — в активности
        // заявки (audit-row без смены статуса), чтобы менеджер видел, куда
        // ушёл счёт (кейс: одно письмо со счётом в двух тредах клиента).
        $dup = $this->findDuplicateOnOtherRequest($number, $request->id);

        // «Первая привязка выигрывает» — это первая по времени РАЗБОРА, а не
        // по праву: M-2026-17145 (28.09) — счёт 10119 на «Яуза Риэлти» ушёл
        // «Яузе», через 6 минут по ошибке «Лифтремонту», второе письмо
        // разобралось на полчаса раньше, и счёт осел в чужой заявке. Если
        // покупатель по реквизитам счёта — клиент ЭТОЙ заявки, а не той, —
        // счёт переезжает сюда. Оплаченный не трогаем.
        if ($dup !== null && $this->shouldMoveDuplicateHere($quote, $request, $dup)) {
            return $this->moveDuplicateHere($dup, $quote, $request, $number);
        }
        if ($dup !== null && $this->shouldReviveDuplicateHere($quote, $request, $dup)) {
            return $this->moveDuplicateHere($dup, $quote, $request, $number, revived: true);
        }
        if ($dup !== null && $this->isCumulativeRevision($quote, $request, $dup)) {
            return $this->shareCumulativeInvoice($dup, $quote, $request, $number);
        }

        if ($dup !== null) {
            Log::info('InvoiceService::autoIssueFromOutboundQuote: skip — duplicate number on another request', [
                'outbound_quote_id' => $quote->id,
                'request_id' => $request->id,
                'number' => $number,
                'original_invoice_id' => $dup->id,
                'original_request_id' => $dup->request_id,
            ]);
            try {
                \App\Models\RequestStateChange::create([
                    'request_id' => $request->id,
                    'from_status' => $request->status->value,
                    'to_status' => $request->status->value,
                    'by_user_id' => null,
                    'event' => 'invoice_duplicate_skipped',
                    'comment' => sprintf(
                        'Счёт №%s уже привязан к заявке %s — здесь не создан (защита от дублей).',
                        $number,
                        $dup->request?->internal_code ?? ('#'.$dup->request_id),
                    ),
                    'payload' => ['original_invoice_id' => $dup->id, 'original_request_id' => $dup->request_id],
                ]);
            } catch (\Throwable $e) {
                Log::warning('InvoiceService: duplicate-skip audit failed (non-fatal)', ['error' => $e->getMessage()]);
            }

            return null;
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

        [$expiresAt, $validityDays] = self::computeExpiry(
            $this->calendar,
            $issuedAt,
            $quote->valid_until,
            (int) config('services.invoices.default_validity_business_days', 5),
        );

        $amountSnapshot = $quote->total_amount !== null
            ? (float) $quote->total_amount
            : $this->resolveAmountSnapshot($request);

        $invoice = DB::transaction(function () use (
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

        return $this->applyExternalPaymentIfAny($invoice);
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
        [$expiresAt, $validityDays] = self::computeExpiry(
            $this->calendar,
            $issuedAt,
            $quote->valid_until,
            (int) config('services.invoices.default_validity_business_days', 5),
        );
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
     * Применить срок действия из (пере)распарсенного исходящего документа к уже
     * существующему счёту — для backfillّа исторических счетов, у которых
     * `valid_until` появился только после введения извлечения даты.
     *
     * В отличие от refreshFromNewerQuote НЕ требует «более свежего источника»
     * (источник тот же самый — это и есть тот документ, из которого счёт создан),
     * и трогает ТОЛЬКО expires_at / validity_days, не переписывая сумму/номер.
     * Работает только с pending-счётом. Возвращает true, если дата изменилась.
     */
    public function applyValidityFromQuote(Invoice $invoice, \App\Models\OutboundQuote $quote): bool
    {
        if ($invoice->status !== InvoiceStatus::Pending) {
            return false;
        }

        $issuedAt = $invoice->issued_at instanceof CarbonInterface
            ? $invoice->issued_at
            : Carbon::parse((string) $invoice->issued_at);

        [$expiresAt, $validityDays] = self::computeExpiry(
            $this->calendar,
            $issuedAt,
            $quote->valid_until,
            (int) config('services.invoices.default_validity_business_days', 5),
        );

        // Сравниваем по дню — время в expires_at всегда 23:59:59.
        $oldExpiry = $invoice->expires_at instanceof CarbonInterface
            ? $invoice->expires_at->toDateString()
            : (string) $invoice->expires_at;
        if ($oldExpiry === $expiresAt->toDateString()) {
            return false;
        }

        $invoice->update([
            'expires_at' => $expiresAt,
            'validity_days' => $validityDays,
        ]);

        Log::info('InvoiceService: applied validity from quote (backfill)', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'outbound_quote_id' => $quote->id,
            'old_expires_at' => $oldExpiry,
            'new_expires_at' => $expiresAt->toDateString(),
            'source' => $quote->valid_until !== null ? 'document_or_email' : 'default',
        ]);

        return true;
    }

    /**
     * Расчёт срока действия счёта (expires_at) + validity_days.
     *
     * Если из документа/письма извлечена явная дата `$validUntil` (и она не
     * раньше даты выставления) — срок = эта дата (конец дня). Иначе fallback:
     * `$issuedAt + $defaultValidityDays` рабочих дней (российский календарь).
     *
     * Pure-функция (без БД/состояния) — вынесена static для тестируемости.
     *
     * @return array{0: CarbonImmutable, 1: int}  [$expiresAt, $validityDays]
     */
    public static function computeExpiry(
        RussianWorkingDayService $calendar,
        CarbonInterface $issuedAt,
        ?CarbonInterface $validUntil,
        int $defaultValidityDays,
    ): array {
        $tz = config('app.timezone');
        $issuedDay = CarbonImmutable::instance($issuedAt)->startOfDay();

        if ($validUntil !== null) {
            $validDay = CarbonImmutable::instance($validUntil)->startOfDay();
            // Дата действия не может быть раньше даты выставления — иначе это
            // мусор от парсера (или дата из чужого контекста). Откатываемся на default.
            if ($validDay->greaterThanOrEqualTo($issuedDay)) {
                $expiresAt = $validDay->endOfDay()->setTimezone($tz);
                $validityDays = self::businessDaysBetween($calendar, $issuedDay, $validDay);

                return [$expiresAt, $validityDays];
            }
        }

        $validityDays = $defaultValidityDays;
        $expiresAt = $calendar->addBusinessDays($issuedAt, $validityDays)
            ->endOfDay()
            ->setTimezone($tz);

        return [$expiresAt, $validityDays];
    }

    /**
     * Кол-во рабочих дней строго ПОСЛЕ $from и по $to включительно
     * (для записи validity_days, когда срок задан явной датой). Само $from
     * не считается. Cap на случай далёких/кривых дат.
     */
    private static function businessDaysBetween(
        RussianWorkingDayService $calendar,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): int {
        $count = 0;
        $cursor = $from->startOfDay();
        $end = $to->startOfDay();
        $safety = 0;
        while ($cursor->lessThan($end)) {
            $cursor = $cursor->addDay();
            if ($calendar->isBusinessDay($cursor)) {
                $count++;
            }
            if (++$safety > 366) {
                break;
            }
        }

        return $count;
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
    public const PAYABLE_STATUSES = [InvoiceStatus::Pending, InvoiceStatus::Expired, InvoiceStatus::Cancelled, InvoiceStatus::PartiallyPaid];

    /**
     * Пометить счёт оплаченным + перевести Request → Paid.
     *
     * @param  \DateTimeInterface|null  $paidAt  фактическая дата оплаты (импорт
     *                                           из 1С); null → now().
     * @param  float|null  $paidAmount  фактически поступившая сумма (1С).
     * @param  string|null  $note  доп. строка в комментарий аудита (например,
     *                             предупреждение о расхождении сумм).
     * @param  bool  $systemTransition  переход статуса заявки без permission-гейта
     *                                  (импорт оплат: загрузивший может не иметь
     *                                  прав на чужие заявки — секретарь).
     */
    public function markPaid(
        Invoice $invoice,
        User $author,
        ?\DateTimeInterface $paidAt = null,
        ?float $paidAmount = null,
        ?string $note = null,
        bool $systemTransition = false,
    ): Invoice {
        if (! in_array($invoice->status, self::PAYABLE_STATUSES, true)) {
            throw new \DomainException(sprintf(
                'Нельзя оплатить счёт в статусе %s. Допустимо pending, expired, cancelled или partially_paid.',
                $invoice->status->value
            ));
        }

        // Реанимация ранее аннулированного счёта: снимаем отметку аннулирования,
        // чтобы статус/комментарий в UI не противоречили (был «отменён» → стал
        // «оплачен»). Сам факт оплаты фиксируется в request_state_changes.
        $wasCancelled = $invoice->status === InvoiceStatus::Cancelled;

        return DB::transaction(function () use ($invoice, $author, $wasCancelled, $paidAt, $paidAmount, $note, $systemTransition) {
            $invoice->update([
                'status' => InvoiceStatus::Paid->value,
                'paid_at' => $paidAt ?? now(),
                'paid_by_user_id' => $author->id,
                'paid_amount' => $paidAmount ?? $invoice->paid_amount,
                'cancelled_at' => $wasCancelled ? null : $invoice->cancelled_at,
                'cancellation_reason' => $wasCancelled ? null : $invoice->cancellation_reason,
            ]);

            $comment = $wasCancelled
                ? sprintf('Ранее аннулированный счёт №%s оплачен (реанимация).', $invoice->invoice_number)
                : sprintf('Счёт №%s оплачен.', $invoice->invoice_number);
            if ($note !== null && trim($note) !== '') {
                $comment .= ' '.trim($note);
            }

            $this->transitionAfterPayment($invoice, RequestStatus::Paid, $author, [
                'event' => 'invoice_paid',
                'comment' => $comment,
                'payload' => [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'reanimated' => $wasCancelled,
                ],
            ], $systemTransition);

            return $invoice->fresh();
        });
    }

    /**
     * Частичная оплата (импорт 1С, Оп% < 100): счёт → partially_paid,
     * заявка закрывается как успех (решение заказчика: деньги пришли — сделка
     * состоялась; остаток контролирует бухгалтерия в 1С). Доплата до 100%
     * позже переводит счёт в Paid обычным markPaid.
     */
    public function markPartiallyPaid(
        Invoice $invoice,
        User $author,
        float $paidAmount,
        ?\DateTimeInterface $paidAt = null,
        ?string $note = null,
        bool $systemTransition = false,
    ): Invoice {
        // PartiallyPaid входит в PAYABLE_STATUSES: повторная частичная оплата
        // допустима (обновит сумму/дату).
        if (! in_array($invoice->status, self::PAYABLE_STATUSES, true)) {
            throw new \DomainException(sprintf(
                'Нельзя провести частичную оплату счёта в статусе %s.',
                $invoice->status->value
            ));
        }

        $wasCancelled = $invoice->status === InvoiceStatus::Cancelled;

        return DB::transaction(function () use ($invoice, $author, $wasCancelled, $paidAt, $paidAmount, $note, $systemTransition) {
            $invoice->update([
                'status' => InvoiceStatus::PartiallyPaid->value,
                'partially_paid_at' => $paidAt ?? now(),
                'paid_by_user_id' => $author->id,
                'paid_amount' => $paidAmount,
                'cancelled_at' => $wasCancelled ? null : $invoice->cancelled_at,
                'cancellation_reason' => $wasCancelled ? null : $invoice->cancellation_reason,
            ]);

            $comment = sprintf(
                'Счёт №%s оплачен частично: %s из %s.',
                $invoice->invoice_number,
                number_format($paidAmount, 2, '.', ' '),
                $invoice->amount_snapshot !== null ? number_format((float) $invoice->amount_snapshot, 2, '.', ' ') : '—',
            );
            if ($note !== null && trim($note) !== '') {
                $comment .= ' '.trim($note);
            }

            $this->transitionAfterPayment($invoice, RequestStatus::ClosedWon, $author, [
                'event' => 'invoice_partially_paid',
                'comment' => $comment,
                'payload' => [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'paid_amount' => $paidAmount,
                ],
            ], $systemTransition);

            return $invoice->fresh();
        });
    }

    /**
     * Перевод заявки после оплаты счёта, с реанимацией закрытой как потеря:
     * заявка могла быть авто-закрыта «счёт не оплачен», а клиент заплатил
     * позже — деньги пришли, значит возвращаем в работу и закрываем успехом.
     * Ошибка перехода не валит отметку оплаты (non-fatal, как раньше).
     */
    private function transitionAfterPayment(
        Invoice $invoice,
        RequestStatus $to,
        User $author,
        array $context,
        bool $systemTransition,
    ): void {
        $this->transitionRequestAfterPayment($invoice, $invoice->request, $to, $author, $context, $systemTransition);

        // Сквозной счёт: оплата закрывает и заявки, вошедшие в него.
        foreach ($this->sharedRequestIds($invoice) as $requestId) {
            $shared = Request::query()->find($requestId);
            if ($shared === null) {
                continue;
            }
            $sharedContext = $context;
            $sharedContext['comment'] = trim((string) ($context['comment'] ?? '')).' Сквозной счёт — оплата и по этой заявке.';
            $this->transitionRequestAfterPayment($invoice, $shared, $to, $author, $sharedContext, $systemTransition);
        }
    }

    private function transitionRequestAfterPayment(
        Invoice $invoice,
        ?Request $request,
        RequestStatus $to,
        User $author,
        array $context,
        bool $systemTransition,
    ): void {
        try {
            if ($request === null) {
                return;
            }

            if ($request->status === RequestStatus::ClosedLost) {
                $this->stateService->reanimate(
                    $request,
                    $author,
                    null,
                    reassessAssignee: false,
                    event: 'reanimated_by_payment',
                    comment: sprintf('Оплата по счёту №%s после закрытия — реанимация.', $invoice->invoice_number),
                );
                $request->refresh();
            }

            // Уже в целевом статусе или закрыта успехом (повторный импорт,
            // второй счёт той же заявки) — переводить некуда.
            if ($request->status === $to || $request->status === RequestStatus::ClosedWon) {
                return;
            }

            // Стейт-машина допускает Paid только из Invoiced, а оплата может
            // прийти на заявку в ЛЮБОМ рабочем статусе (внешний счёт из 1С,
            // привязка задним числом, реанимация из closed_lost попадает в
            // in_progress). Без моста заявка застревала: «Запрещённый переход:
            // in_progress → paid» — кейс M-2026-1530, 15 заявок 08.07.
            $allowed = $request->status->allowedTransitions();
            if (! in_array($to, $allowed, true)
                && $to !== RequestStatus::Invoiced
                && in_array(RequestStatus::Invoiced, $allowed, true)) {
                $this->stateService->transitionTo($request, RequestStatus::Invoiced, $author, [
                    'event' => 'payment_status_bridge',
                    'comment' => sprintf(
                        'Промежуточный переход к «Оплачен»: оплата счёта №%s пришла на заявку вне цепочки счёт→оплата.',
                        $invoice->invoice_number,
                    ),
                ], $systemTransition);
                $request->refresh();
            }

            $this->stateService->transitionTo($request, $to, $author, $context, $systemTransition);
        } catch (\Throwable $e) {
            Log::warning('InvoiceService: post-payment transition failed (non-fatal)', [
                'request_id' => $request?->id,
                'invoice_id' => $invoice->id,
                'to' => $to->value,
                'error' => $e->getMessage(),
            ]);
        }
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

            // Статус заявки НЕ трогаем: счёт выставлен, и заявка живёт с этим
            // статусом до оплаты либо до авто-закрытия по неоплате (ветка (c)
            // в requests:auto-close-inactive, она смотрит и pending, и expired
            // счета). Раньше просрочка возвращала заявку в «Согласован / ждёт
            // счёт», и в карточке получалось «счёт выставлен, а статус ждёт
            // счёт» — решение заказчика 18.09.2026 по кейсу M-2026-15292.
            // Аннулирование счёта (cancel) поведения не меняет: там счёта
            // действительно больше нет и нужен новый.
            $this->logExpiryWithoutTransition($invoice);

            return $invoice->fresh();
        });
    }

    /**
     * Отметить просрочку в истории заявки, не двигая статус: без записи
     * событие пропадает из хронологии карточки, и «почему счёт просрочен»
     * приходится искать в таблице счетов.
     */
    private function logExpiryWithoutTransition(Invoice $invoice): void
    {
        $request = $invoice->request;
        if (! $request) {
            return;
        }

        $status = is_object($request->status) ? $request->status->value : (string) $request->status;

        \App\Models\RequestStateChange::create([
            'request_id' => $request->id,
            'from_status' => $status,
            'to_status' => $status,
            'by_user_id' => null,
            'event' => 'invoice_expired',
            'comment' => sprintf(
                'Счёт №%s просрочен. Статус заявки не меняем — ждём оплату.',
                $invoice->invoice_number,
            ),
            'payload' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ],
        ]);
    }

    /**
     * Перевести Request → AwaitingInvoice если все её Invoice'ы в финальных
     * статусах (нет pending). Используется после cancel: аннулированный счёт
     * надо перевыставить. При ПРОСРОЧКЕ статус не трогаем — см. expire().
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
