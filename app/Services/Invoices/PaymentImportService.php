<?php

namespace App\Services\Invoices;

use App\Models\ImportedPayment;
use App\Models\Invoice;
use App\Models\PaymentImport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Импорт оплат из выгрузки 1С (раздел «Счета» → «Загрузить оплаты»).
 *
 * Поддерживает оба формата выгрузок («Продажи за месяц» и ежедневные «Оплаты
 * МЛ»): колонки ищутся по заголовкам, а не по позициям. Номер счёта 1С
 * («НФ00-005513») сопоставляется со счётом CRM по числовой части.
 *
 * Классификация строки (preview → apply):
 *   mark_paid    — счёт найден, не оплачен, Оп% = 100 → markPaid (дата из файла);
 *   mark_partial — счёт найден, Оп% < 100 → markPartiallyPaid, заявка = успех;
 *   already_paid — счёт уже оплачен → пропуск;
 *   unknown      — счёта нет; счёт выписан после запуска системы → журнал
 *                  «Внешние оплаты» (с дедупом);
 *   skipped_old  — счёта нет и он выписан до запуска системы → не фиксируем.
 *
 * Расхождение суммы оплаты с amount_snapshot счёта (> 1 ₽) не блокирует
 * отметку — предупреждение уходит в комментарий аудита (решение заказчика).
 */
class PaymentImportService
{
    /** Счета, выписанные до этой даты, не фиксируем как «внешние» (до запуска системы). */
    public const SYSTEM_LAUNCH_DATE = '2026-05-25';

    /** Допустимое расхождение суммы оплаты и суммы счёта, ₽. */
    private const SUM_TOLERANCE = 1.0;

    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    /**
     * Разобрать xlsx в нормализованные строки.
     *
     * @return array{rows: array<int, array<string, mixed>>, error: ?string}
     */
    public function parse(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);
            $grid = $sheet->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return ['rows' => [], 'error' => 'Не удалось прочитать файл: '.$e->getMessage()];
        }

        // Строка заголовков — первая, где встречается «Номер счета».
        $headerRowIdx = null;
        $cols = [];
        foreach ($grid as $i => $row) {
            foreach ($row as $j => $cell) {
                $h = mb_strtolower(trim((string) $cell));
                if (str_contains($h, 'номер счет')) {
                    $headerRowIdx = $i;
                    break 2;
                }
            }
            if ($i > 10) {
                break;
            }
        }
        if ($headerRowIdx === null) {
            return ['rows' => [], 'error' => 'Не найдена строка заголовков (колонка «Номер счета»).'];
        }

        foreach ($grid[$headerRowIdx] as $j => $cell) {
            $h = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $cell)));
            $cols[$j] = $h;
        }
        $find = function (array $needles) use ($cols): ?int {
            foreach ($cols as $j => $h) {
                foreach ($needles as $n) {
                    if ($h !== '' && str_contains($h, $n)) {
                        return $j;
                    }
                }
            }

            return null;
        };

        $map = [
            'number' => $find(['номер счет']),
            'invoice_date' => $find(['дата счет']),
            'paid_date' => $find(['дата оплаты']),
            'percent' => $find(['оп%', 'оп %']),
            'paid_sum' => $find(['сумма <оплата>', 'сумма<оплата>']),
            'debt_sum' => $find(['сумма <долг>', 'сумма<долг>']),
            'revenue_sum' => $find(['сумма <выручка>', 'сумма<выручка>']),
            'cost_sum' => $find(['сумма <затраты>', 'сумма<затраты>']),
            'profit_sum' => $find(['сумма <прибыль>', 'сумма<прибыль>']),
            'client' => $find(['контрагент']),
            'manager' => $find(['ответственн']),
            'purpose' => $find(['назначение платежа']),
        ];
        if ($map['number'] === null || $map['paid_sum'] === null) {
            return ['rows' => [], 'error' => 'В файле нет колонок «Номер счета» / «Сумма <Оплата>».'];
        }

        $rows = [];
        for ($i = $headerRowIdx + 1; $i < count($grid); $i++) {
            $r = $grid[$i];
            $numberRaw = trim((string) ($r[$map['number']] ?? ''));
            if ($numberRaw === '') {
                continue;
            }
            $numInt = $this->normalizeNumber($numberRaw);
            $rows[] = [
                'number_raw' => $numberRaw,
                'number_int' => $numInt,
                'invoice_date' => $this->toDate($map['invoice_date'] !== null ? ($r[$map['invoice_date']] ?? null) : null),
                'paid_date' => $this->toDate($map['paid_date'] !== null ? ($r[$map['paid_date']] ?? null) : null),
                'percent' => $map['percent'] !== null ? (int) round((float) $this->toNumber($r[$map['percent']] ?? null)) : 100,
                'paid_sum' => $this->toNumber($r[$map['paid_sum']] ?? null),
                'debt_sum' => $map['debt_sum'] !== null ? $this->toNumber($r[$map['debt_sum']] ?? null) : null,
                'revenue_sum' => $map['revenue_sum'] !== null ? $this->toNumber($r[$map['revenue_sum']] ?? null) : null,
                'cost_sum' => $map['cost_sum'] !== null ? $this->toNumber($r[$map['cost_sum']] ?? null) : null,
                'profit_sum' => $map['profit_sum'] !== null ? $this->toNumber($r[$map['profit_sum']] ?? null) : null,
                'client' => $map['client'] !== null ? trim((string) ($r[$map['client']] ?? '')) : '',
                'manager' => $map['manager'] !== null ? trim((string) ($r[$map['manager']] ?? '')) : '',
                'purpose' => $map['purpose'] !== null ? trim((string) ($r[$map['purpose']] ?? '')) : '',
            ];
        }

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Классифицировать строки против текущего состояния БД (dry-run превью).
     * Каждой строке добавляются action / invoice / детали.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, array{count: int, sum: float}>}
     */
    public function classify(array $rows): array
    {
        $nums = array_values(array_filter(array_column($rows, 'number_int')));
        $invoices = Invoice::query()
            ->with('request:id,internal_code,status,assigned_user_id')
            ->get(['id', 'request_id', 'invoice_number', 'status', 'paid_at', 'amount_snapshot'])
            ->groupBy(fn (Invoice $i) => $this->normalizeNumber((string) $i->invoice_number));

        $knownUnknown = ImportedPayment::query()
            ->whereIn('outcome', [ImportedPayment::OUTCOME_UNKNOWN, ImportedPayment::OUTCOME_IGNORED, ImportedPayment::OUTCOME_LINKED])
            ->whereIn('invoice_number_int', $nums ?: [0])
            ->get(['invoice_number_int', 'paid_date', 'paid_sum'])
            ->map(fn ($p) => $p->invoice_number_int.'|'.$p->paid_date?->format('Y-m-d').'|'.number_format((float) $p->paid_sum, 2, '.', ''))
            ->flip();

        $summary = [];
        $bump = function (string $action, float $sum) use (&$summary): void {
            $summary[$action] ??= ['count' => 0, 'sum' => 0.0];
            $summary[$action]['count']++;
            $summary[$action]['sum'] += $sum;
        };

        foreach ($rows as &$row) {
            $sum = (float) ($row['paid_sum'] ?? 0);
            $candidates = $row['number_int'] !== null ? $invoices->get($row['number_int']) : null;

            if ($candidates === null || $candidates->isEmpty()) {
                $isOld = $row['invoice_date'] !== null && $row['invoice_date'] < self::SYSTEM_LAUNCH_DATE;
                if ($isOld) {
                    $row['action'] = 'skipped_old';
                } else {
                    $dedupKey = $row['number_int'].'|'.$row['paid_date'].'|'.number_format($sum, 2, '.', '');
                    $row['action'] = $knownUnknown->has($dedupKey) ? 'already_recorded' : 'unknown';
                }
                $bump($row['action'], $sum);

                continue;
            }

            /** @var Invoice $inv приоритет: неоплаченный счёт (его и отмечаем) */
            $inv = $candidates->firstWhere(fn (Invoice $i) => $i->paid_at === null) ?? $candidates->first();
            $row['invoice_id'] = $inv->id;
            $row['request_code'] = $inv->request?->internal_code;
            $row['request_id'] = $inv->request_id;
            $row['crm_status'] = $inv->status->value;
            $row['crm_amount'] = (float) $inv->amount_snapshot;
            $row['sum_mismatch'] = $inv->amount_snapshot !== null
                && abs((float) $inv->amount_snapshot - $sum) > self::SUM_TOLERANCE;

            if ($inv->paid_at !== null) {
                $row['action'] = 'already_paid';
            } elseif ((int) $row['percent'] >= 100) {
                $row['action'] = 'mark_paid';
            } else {
                $row['action'] = 'mark_partial';
            }
            $bump($row['action'], $sum);
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * Применить классифицированные строки: отметить оплаты, записать журнал.
     *
     * @param  array<int, array<string, mixed>>  $rows  из classify()
     */
    public function apply(array $rows, string $filename, User $author): PaymentImport
    {
        $import = PaymentImport::create([
            'filename' => mb_substr($filename, 0, 255),
            'uploaded_by_user_id' => $author->id,
            'rows_total' => count($rows),
        ]);

        $counters = ['marked_paid' => 0, 'marked_partial' => 0, 'already_paid' => 0, 'unknown_recorded' => 0, 'skipped_old' => 0, 'errors' => 0];

        foreach ($rows as $row) {
            $action = $row['action'] ?? 'unknown';
            $outcome = null;
            $note = null;
            $invoiceId = $row['invoice_id'] ?? null;
            $requestId = $row['request_id'] ?? null;

            try {
                if ($action === 'mark_paid' || $action === 'mark_partial') {
                    /** @var Invoice $inv */
                    $inv = Invoice::findOrFail($invoiceId);
                    $paidAt = $row['paid_date'] ? Carbon::parse($row['paid_date'])->setTime(12, 0) : null;
                    $mismatch = ! empty($row['sum_mismatch'])
                        ? sprintf('⚠ Сумма оплаты по 1С (%s) отличается от суммы счёта (%s).',
                            number_format((float) $row['paid_sum'], 2, '.', ' '),
                            number_format((float) $row['crm_amount'], 2, '.', ' '))
                        : null;
                    $importNote = sprintf('Импорт оплат 1С («%s»).', $filename);
                    $fullNote = trim(($mismatch ? $mismatch.' ' : '').$importNote);

                    if ($action === 'mark_paid') {
                        $this->invoices->markPaid($inv, $author, $paidAt, (float) $row['paid_sum'], $fullNote, systemTransition: true);
                        $outcome = ImportedPayment::OUTCOME_MARKED_PAID;
                        $counters['marked_paid']++;
                    } else {
                        $this->invoices->markPartiallyPaid($inv, $author, (float) $row['paid_sum'], $paidAt, $fullNote, systemTransition: true);
                        $outcome = ImportedPayment::OUTCOME_MARKED_PARTIAL;
                        $counters['marked_partial']++;
                    }
                    $note = $mismatch;
                } elseif ($action === 'already_paid' || $action === 'already_recorded') {
                    $outcome = ImportedPayment::OUTCOME_ALREADY_PAID;
                    $counters['already_paid']++;
                    // Обогащение: счёт оплатили до внедрения импорта — факт
                    // поступления из 1С дописываем (для «по 1С поступило»
                    // в итогах), статус/даты не трогаем.
                    if ($invoiceId !== null && $row['paid_sum'] !== null) {
                        Invoice::whereKey($invoiceId)
                            ->whereNull('paid_amount')
                            ->update(['paid_amount' => (float) $row['paid_sum']]);
                    }
                } elseif ($action === 'skipped_old') {
                    $outcome = ImportedPayment::OUTCOME_SKIPPED_OLD;
                    $counters['skipped_old']++;
                } else {
                    $outcome = ImportedPayment::OUTCOME_UNKNOWN;
                    $counters['unknown_recorded']++;
                }
            } catch (\Throwable $e) {
                $outcome = ImportedPayment::OUTCOME_ERROR;
                $note = $e->getMessage();
                $counters['errors']++;
                Log::warning('PaymentImport: row failed', [
                    'number' => $row['number_raw'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }

            // Журналируем все строки, кроме дублей уже записанных внешних оплат
            // и пропущенных старых (их незачем копить в таблице).
            if (in_array($action, ['already_recorded', 'skipped_old'], true)) {
                continue;
            }

            $journalRow = ImportedPayment::create([
                'payment_import_id' => $import->id,
                'invoice_number' => mb_substr((string) $row['number_raw'], 0, 64),
                'invoice_number_int' => $row['number_int'],
                'invoice_id' => $invoiceId,
                'request_id' => $requestId,
                'outcome' => $outcome,
                'client_name' => mb_substr((string) ($row['client'] ?? ''), 0, 255) ?: null,
                'manager_name' => mb_substr((string) ($row['manager'] ?? ''), 0, 255) ?: null,
                'payment_purpose' => ($row['purpose'] ?? '') !== '' ? $row['purpose'] : null,
                'invoice_date' => $row['invoice_date'] ?: null,
                'paid_date' => $row['paid_date'] ?: null,
                'paid_percent' => isset($row['percent']) ? max(0, min(65000, (int) $row['percent'])) : null,
                'paid_sum' => $row['paid_sum'],
                'debt_sum' => $row['debt_sum'] ?? null,
                'revenue_sum' => $row['revenue_sum'] ?? null,
                'cost_sum' => $row['cost_sum'] ?? null,
                'profit_sum' => $row['profit_sum'] ?? null,
                'note' => $note,
            ]);

            // Неизвестный счёт: пробуем привязаться через уже распознанный
            // исходящий документ с этим номером (детектор знает заявку, но
            // Invoice не создался — гард договоров/сбой). Успех → создаётся
            // оплаченный счёт, запись из «внешних» уходит в linked.
            if ($outcome === ImportedPayment::OUTCOME_UNKNOWN
                && $this->tryLinkViaOutboundQuote($journalRow, $author)) {
                $counters['unknown_recorded']--;
                if ((int) ($row['percent'] ?? 100) >= 100) {
                    $counters['marked_paid']++;
                } else {
                    $counters['marked_partial']++;
                }
            }
        }

        $import->update($counters);

        Log::info('PaymentImport: applied', array_merge(['import_id' => $import->id, 'file' => $filename, 'by' => $author->id], $counters));

        return $import->fresh();
    }

    /**
     * Автопривязка: при появлении в CRM счёта с номером, по которому уже есть
     * «внешняя» оплата из 1С — отмечаем оплату сразу. Вызывается после
     * создания Invoice (детектор исходящих / ручная привязка).
     */
    public function tryApplyExternalPayment(Invoice $invoice, ?User $author = null): bool
    {
        $num = $this->normalizeNumber((string) $invoice->invoice_number);
        if ($num === null) {
            return false;
        }
        $ext = ImportedPayment::query()
            ->where('outcome', ImportedPayment::OUTCOME_UNKNOWN)
            ->where('invoice_number_int', $num)
            ->orderByDesc('id')
            ->first();
        if ($ext === null) {
            return false;
        }

        $author ??= $ext->import?->uploadedBy;
        if ($author === null) {
            return false;
        }

        try {
            $paidAt = $ext->paid_date ? Carbon::parse($ext->paid_date)->setTime(12, 0) : null;
            $note = sprintf('Автопривязка внешней оплаты из импорта 1С (журнал #%d).', $ext->id);
            if ((int) $ext->paid_percent >= 100) {
                $this->invoices->markPaid($invoice, $author, $paidAt, (float) $ext->paid_sum, $note, systemTransition: true);
            } else {
                $this->invoices->markPartiallyPaid($invoice, $author, (float) $ext->paid_sum, $paidAt, $note, systemTransition: true);
            }
        } catch (\Throwable $e) {
            Log::warning('PaymentImport: auto-apply external payment failed', [
                'invoice_id' => $invoice->id,
                'imported_payment_id' => $ext->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $ext->forceFill([
            'outcome' => ImportedPayment::OUTCOME_LINKED,
            'invoice_id' => $invoice->id,
            'request_id' => $invoice->request_id,
            'resolved_at' => now(),
            'resolved_by_user_id' => $author->id,
        ])->save();

        return true;
    }

    /**
     * Автопривязка внешней оплаты через распознанные исходящие: если документ
     * с этим номером уже задетекчен и привязан к заявке (outbound_quotes),
     * заявка известна — создаём оплаченный счёт без участия человека.
     * Предпочитаем документ типа «счёт», затем свежайший.
     */
    public function tryLinkViaOutboundQuote(ImportedPayment $ext, User $author): bool
    {
        if ($ext->invoice_number_int === null) {
            return false;
        }

        $quote = \App\Models\OutboundQuote::query()
            ->whereNotNull('request_id')
            ->whereRaw("nullif(regexp_replace(coalesce(document_number, ''), '\\D', '', 'g'), '')::bigint = ?", [$ext->invoice_number_int])
            ->orderByRaw("case when document_type = 'outbound_invoice' then 0 else 1 end")
            ->orderByDesc('id')
            ->first();
        if ($quote === null || $quote->request === null) {
            return false;
        }

        try {
            $this->linkExternalToRequest($ext, $quote->request, $author);
        } catch (\Throwable $e) {
            Log::warning('PaymentImport: auto-link via outbound quote failed', [
                'imported_payment_id' => $ext->id,
                'outbound_quote_id' => $quote->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        Log::info('PaymentImport: external payment auto-linked via outbound quote', [
            'imported_payment_id' => $ext->id,
            'outbound_quote_id' => $quote->id,
            'request_id' => $quote->request_id,
        ]);

        return true;
    }

    /**
     * Ручная привязка внешней оплаты к заявке: создаёт счёт (номер — числовая
     * часть из 1С, сумма — выручка/оплата из выгрузки) и сразу отмечает оплату
     * (частичную при Оп% < 100). Журнальная запись → linked.
     */
    public function linkExternalToRequest(ImportedPayment $ext, \App\Models\Request $request, User $author): Invoice
    {
        if ($ext->outcome !== ImportedPayment::OUTCOME_UNKNOWN && $ext->outcome !== ImportedPayment::OUTCOME_IGNORED) {
            throw new \DomainException('Эта внешняя оплата уже привязана или обработана.');
        }

        $paidAt = $ext->paid_date ? Carbon::parse($ext->paid_date)->setTime(12, 0) : now();

        $invoice = Invoice::create([
            'request_id' => $request->id,
            'invoice_number' => (string) ($ext->invoice_number_int ?? $ext->invoice_number),
            'issued_at' => $ext->invoice_date ? Carbon::parse($ext->invoice_date) : $paidAt,
            'expires_at' => $paidAt,
            'validity_days' => 0,
            'status' => \App\Enums\InvoiceStatus::Pending->value,
            'comment' => sprintf(
                'Создан при привязке внешней оплаты из импорта 1С (журнал #%d%s).',
                $ext->id,
                $ext->client_name ? ', контрагент: '.$ext->client_name : '',
            ),
            'created_by_user_id' => $author->id,
            'amount_snapshot' => $ext->revenue_sum ?? $ext->paid_sum,
        ]);

        $note = sprintf('Привязка внешней оплаты 1С (журнал #%d).', $ext->id);
        if ((int) $ext->paid_percent >= 100) {
            $this->invoices->markPaid($invoice, $author, $paidAt, (float) $ext->paid_sum, $note, systemTransition: true);
        } else {
            $this->invoices->markPartiallyPaid($invoice, $author, (float) $ext->paid_sum, $paidAt, $note, systemTransition: true);
        }

        $ext->forceFill([
            'outcome' => ImportedPayment::OUTCOME_LINKED,
            'invoice_id' => $invoice->id,
            'request_id' => $request->id,
            'resolved_at' => now(),
            'resolved_by_user_id' => $author->id,
        ])->save();

        return $invoice->fresh();
    }

    /** «НФ00-005513» / «6960» → 5513 / 6960. */
    public function normalizeNumber(string $raw): ?int
    {
        if (preg_match('/(\d+)\s*$/u', trim($raw), $m) !== 1) {
            return null;
        }
        $n = (int) ltrim($m[1], '0');

        return $n > 0 ? $n : null;
    }

    /**
     * «22 787,46» (1С отдаёт числа текстом с NBSP-разделителем тысяч) /
     * «262.56» / 262.56 → float.
     */
    private function toNumber(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        // Убираем все пробелоподобные (включая U+00A0 / U+202F), запятая → точка.
        $s = (string) preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', '', (string) $v);
        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : null;
    }

    /** Дата из ячейки (строка/DateTime/serial) → 'Y-m-d' или null. */
    private function toDate(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_numeric($v)) {
            // Excel serial date.
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return Carbon::parse(trim((string) $v))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
