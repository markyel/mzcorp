<?php

namespace App\Livewire\Invoices;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Invoice;
use App\Models\Request;
use App\Services\DocumentDetector\OutboundDocumentDetector;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Триаж исходящих счетов, которые не нашли заявку (Слой B, follow-up к
 * lost-invoices-diagnosis).
 *
 * Сюда попадают счета, для которых авто-привязка (`mail:relink-deferred-outbound`,
 * Слой A) НЕ сработала — родителя треда нет в БД, по заголовкам линковать не
 * по чему. Привилегированный пользователь (РОП/секретарь/директор) находит
 * нужную заявку и привязывает счёт вручную → запускается разбор → Invoice.
 *
 * Свежесть: показываем только счета, чья ДАТА в имени файла за последние
 * `period` дней — чтобы не захлебнуться в пересылках архивных счетов
 * (менеджеры форвардят «Счет МЗ-368 от 2025-01» как напоминание).
 */
class Unlinked extends Component
{
    /** Окно свежести по дате счёта (дней). */
    #[Url(as: 'days')]
    public int $period = 30;

    /** Какой счёт сейчас привязываем (email_message_id). */
    public ?int $attachingMsgId = null;

    /** Строка поиска заявки в панели привязки. */
    public string $requestSearch = '';

    /** Раскрытый счёт (email_attachment_id) — показываем извлечённый текст PDF. */
    public ?int $expandedInvoiceAtt = null;

    /** Извлечённый текст текущего раскрытого счёта (кап до 6000 символов). */
    public ?string $invoiceText = null;

    // Только НАШИ счета (формат «Счет МЗ-NNNN …»). Так отсекаем форварды
    // поставщицких счетов («Счет 26-…», «… Деловые Линии Счет(-а).pdf»),
    // которые не наши исходящие и заявку искать им не нужно.
    private const NAME_LIKE = ['Счет МЗ-%', 'Счёт МЗ-%', 'Инвойс МЗ-%'];

    public function mount(): void
    {
        abort_unless($this->isPrivileged(), 403);
    }

    private function isPrivileged(): bool
    {
        return auth()->user()?->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Secretary->value,
            Role::Admin->value,
        ]) ?? false;
    }

    public function setPeriod(int $days): void
    {
        $this->period = in_array($days, [7, 30, 90, 365], true) ? $days : 30;
        unset($this->rows);
    }

    public function startAttach(int $msgId): void
    {
        $this->attachingMsgId = $msgId;
        $this->requestSearch = '';
    }

    public function cancelAttach(): void
    {
        $this->attachingMsgId = null;
        $this->requestSearch = '';
    }

    /**
     * Раскрыть/свернуть позиции счёта. Структурных позиций у НЕпривязанного
     * счёта ещё нет (разбор идёт только после привязки), поэтому достаём
     * текстовый слой PDF (Smalot, без Vision/OpenAI) — в нём видны строки
     * таблицы для сверки с позициями заявки.
     */
    public function toggleInvoiceText(int $attId): void
    {
        if ($this->expandedInvoiceAtt === $attId) {
            $this->expandedInvoiceAtt = null;
            $this->invoiceText = null;

            return;
        }

        $this->expandedInvoiceAtt = $attId;
        $this->invoiceText = $this->extractInvoiceText($attId);
    }

    private function extractInvoiceText(int $attId): ?string
    {
        $att = EmailAttachment::find($attId);
        if (! $att) {
            return null;
        }
        $disk = $att->disk ?: 'local';
        if (! $att->file_path || ! Storage::disk($disk)->exists($att->file_path)) {
            return null;
        }
        if (strtolower((string) pathinfo((string) $att->filename, PATHINFO_EXTENSION)) !== 'pdf') {
            return null; // не-PDF (xlsx и пр.) — смотрим оригинал по ссылке «Открыть»
        }

        try {
            $text = (new \Smalot\PdfParser\Parser())
                ->parseFile(Storage::disk($disk)->path($att->file_path))
                ->getText();
        } catch (\Throwable $e) {
            return null;
        }

        $text = trim((string) preg_replace('/[ \t]{2,}/u', ' ', (string) $text));

        return $text !== '' ? mb_substr($text, 0, 6000) : null;
    }

    /**
     * Привязать письмо-счёт к выбранной заявке и запустить разбор → Invoice.
     */
    public function attach(int $requestId, OutboundDocumentDetector $detector): void
    {
        abort_unless($this->isPrivileged(), 403);

        $msgId = $this->attachingMsgId;
        if (! $msgId) {
            return;
        }

        $email = EmailMessage::with('attachments')->find($msgId);
        $request = Request::find($requestId);
        if (! $email || ! $request) {
            $this->dispatch('toast', message: 'Письмо или заявка не найдены.', type: 'error');

            return;
        }
        if ($email->related_request_id) {
            $this->dispatch('toast', message: 'Письмо уже привязано к заявке #' . $email->related_request_id . '.', type: 'error');
            $this->cancelAttach();
            unset($this->rows);

            return;
        }

        // Привязка + audit в detected_artifacts (как EmailToRequestPromoter).
        $artifacts = is_array($email->detected_artifacts ?? null) ? $email->detected_artifacts : [];
        $artifacts[] = [
            'type' => 'manual_attach_outbound_invoice',
            'request_id' => $request->id,
            'created_at' => now()->toIso8601String(),
            'created_by_user_id' => auth()->id(),
        ];
        $email->forceFill([
            'related_request_id' => $request->id,
            'detected_artifacts' => $artifacts,
        ])->save();

        // Разбор самоопределяющихся счёт/КП-вложений → OutboundQuote → Invoice.
        $dispatched = 0;
        foreach ($email->attachments as $att) {
            $type = $detector->classifyAttachmentByFilename((string) $att->filename);
            if ($type === null) {
                continue;
            }
            \App\Jobs\Quotes\ParseOutboundQuoteJob::dispatch($att->id, $type->value, true);
            $dispatched++;
        }

        Log::info('Invoices\\Unlinked: manual attach outbound invoice', [
            'email_message_id' => $email->id,
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'parse_dispatched' => $dispatched,
            'by_user_id' => auth()->id(),
        ]);

        $this->dispatch(
            'toast',
            message: "Счёт привязан к {$request->internal_code}. Разбор запущен (документов: {$dispatched}) — счёт появится в реестре через минуту.",
            type: 'success',
        );

        $this->cancelAttach();
        unset($this->rows);
    }

    /**
     * Email клиента привязываемого счёта (внешний получатель письма) — для
     * подсказки «открытые заявки этого заказчика».
     */
    #[Computed]
    public function attachingClientEmail(): ?string
    {
        if (! $this->attachingMsgId) {
            return null;
        }
        $msg = EmailMessage::find($this->attachingMsgId);

        return $msg ? $this->firstExternalRecipient($msg) : null;
    }

    /**
     * Открытые (не архивные) заявки заказчика, которому ушёл счёт — главные
     * кандидаты на привязку. Показываем сразу, до ручного поиска.
     *
     * @return \Illuminate\Support\Collection<int, Request>
     */
    #[Computed]
    public function clientOpenRequests()
    {
        $email = $this->attachingClientEmail();
        if (! $email) {
            return collect();
        }

        return Request::query()
            ->where('client_email', 'ilike', $email)
            ->whereNotIn('status', [
                RequestStatus::ClosedWon->value,
                RequestStatus::ClosedLost->value,
            ])
            ->with([
                'assignedUser:id,name',
                'items:id,request_id,position,parsed_name,parsed_article,parsed_qty,parsed_unit',
            ])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'internal_code', 'status', 'client_email', 'client_name', 'subject', 'assigned_user_id', 'created_at']);
    }

    /**
     * Кандидаты-заявки для ручного поиска: по коду / клиенту / теме.
     *
     * @return \Illuminate\Support\Collection<int, Request>
     */
    #[Computed]
    public function requestCandidates()
    {
        $q = trim($this->requestSearch);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        return Request::query()
            ->where(function ($w) use ($needle) {
                $w->where('internal_code', 'ilike', $needle)
                    ->orWhere('client_email', 'ilike', $needle)
                    ->orWhere('client_name', 'ilike', $needle)
                    ->orWhere('subject', 'ilike', $needle);
            })
            ->with([
                'assignedUser:id,name',
                'items:id,request_id,position,parsed_name,parsed_article,parsed_qty,parsed_unit',
            ])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'internal_code', 'status', 'client_email', 'client_name', 'subject', 'assigned_user_id', 'created_at']);
    }

    /**
     * Непривязанные исходящие счета (дедуп по номеру, фильтр свежести + нет Invoice).
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        // Берём исходящие письма со счёт-вложениями за широкое окно по sent_at
        // (письмо могло уйти недавно), фильтр свежести — по ДАТЕ из имени файла.
        $query = EmailMessage::query()
            ->where('direction', 'outbound')
            ->whereNull('related_request_id')
            ->where('sent_at', '>=', now()->subDays(max($this->period, 30) + 30))
            ->whereHas('attachments', function ($q) {
                $q->where(function ($w) {
                    foreach (self::NAME_LIKE as $like) {
                        $w->orWhere('filename', 'ilike', $like);
                    }
                });
            })
            ->with(['attachments', 'mailbox:id,email'])
            ->orderByDesc('sent_at')
            ->limit(400);

        $cutoff = now()->subDays($this->period)->startOfDay();
        $rows = [];
        $seenNumbers = [];

        foreach ($query->get() as $msg) {
            // Клиент = первый ВНЕШНИЙ получатель. Нет такого (письмо ушло только
            // внутренним адресам — внутренняя пересылка) → не наш клиентский счёт.
            $client = $this->firstExternalRecipient($msg);
            if ($client === null) {
                continue;
            }

            foreach ($msg->attachments as $att) {
                $fn = (string) $att->filename;
                if (! preg_match('/^(Сч[её]т|Инвойс)\s+М[ЗЭ]-/u', $fn)) {
                    continue;
                }

                [$number, $docDate] = self::parseInvoiceMeta($fn);

                // Свежесть: по дате счёта (из имени), fallback — sent_at письма.
                $effectiveDate = $docDate ?? ($msg->sent_at ? $msg->sent_at->copy()->startOfDay() : null);
                if ($effectiveDate !== null && $effectiveDate->lt($cutoff)) {
                    continue;
                }

                // Дедуп по номеру (пересылки/копии).
                $key = $number !== null ? mb_strtolower($number) : 'att:' . $att->id;
                if (isset($seenNumbers[$key])) {
                    continue;
                }

                // Уже есть Invoice по этому номеру — не показываем.
                if ($number !== null && Invoice::query()
                    ->where('invoice_number', $number)
                    ->orWhere('invoice_number', 'МЗ-' . $number)
                    ->exists()) {
                    $seenNumbers[$key] = true;

                    continue;
                }

                $seenNumbers[$key] = true;
                $rows[] = [
                    'msg_id' => $msg->id,
                    'att_id' => $att->id,
                    'filename' => $fn,
                    'number' => $number,
                    'doc_date' => $docDate?->format('d.m.Y'),
                    'from_email' => $msg->from_email,
                    'mailbox' => $msg->mailbox?->email,
                    'client' => $client,
                    'subject' => (string) $msg->subject,
                    'sent_at' => $msg->sent_at?->format('d.m.Y H:i'),
                ];
            }
        }

        return $rows;
    }

    /**
     * Номер + дата счёта из имени файла «Счет МЗ-6197 от 2026-06-15_14-29-16.pdf».
     * Pure-функция (static) для тестируемости regex-извлечения.
     *
     * @return array{0: ?string, 1: ?Carbon}
     */
    public static function parseInvoiceMeta(string $filename): array
    {
        $number = null;
        if (preg_match('/М[ЗЭ]-?\s*0*(\d+)/u', $filename, $m)) {
            $number = $m[1];
        } elseif (preg_match('/(?:Сч[её]т|Инвойс)\D*?(\d{3,})/u', $filename, $m)) {
            $number = $m[1];
        }

        $date = null;
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $filename, $m)) {
            try {
                $date = Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
            } catch (\Throwable $e) {
                $date = null;
            }
        }

        return [$number, $date];
    }

    /**
     * Первый ВНЕШНИЙ (не наш домен) получатель письма — это клиент.
     * Внутренние пересылки (только @myzip.ru в to) → null.
     */
    private function firstExternalRecipient(EmailMessage $msg): ?string
    {
        $domains = array_values(array_filter(array_map(
            fn ($d) => mb_strtolower(trim((string) $d)),
            (array) config('services.mail.internal_domains', []),
        )));

        foreach ((array) $msg->to_recipients as $r) {
            $email = is_array($r) ? ($r['email'] ?? null) : null;
            if (! $email) {
                continue;
            }
            $email = mb_strtolower(trim((string) $email));
            $isInternal = false;
            foreach ($domains as $d) {
                if ($d !== '' && str_ends_with($email, '@' . $d)) {
                    $isInternal = true;
                    break;
                }
            }
            if (! $isInternal) {
                return $email;
            }
        }

        return null;
    }

    public function render()
    {
        return view('livewire.invoices.unlinked');
    }
}
