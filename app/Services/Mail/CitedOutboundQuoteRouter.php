<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Детектор «клиент цитирует наш КП/счёт».
 *
 * Клиент присылает письмо со ссылкой на наш ранее выданный КП (или счёт) —
 * по номеру в теме/теле, ИЛИ приложив наш КП файлом (номер в имени файла или
 * в тексте PDF). Не важно, на какой e-mail был выдан КП (его мог переслать
 * коллега). Мы находим заявку, по которой выдан цитируемый КП, и маршрутизируем
 * письмо туда со статусом «ждёт счёт» вместо создания новой заявки.
 *
 * Матчинг надёжный: из письма/вложений достаём числа-кандидаты и ПЕРЕСЕКАЕМ с
 * реальными `outbound_quotes.document_number` наших исходящих документов —
 * совпадение с нашим номером = сильный сигнал (не парсим хрупкие «КП №…»).
 * Гейт от ложных совпадений: письмо должно упоминать счёт/КП/оплату ЛИБО номер
 * пришёл из вложения. Несколько КП → берём с наибольшей суммой.
 *
 * ВАЖНО: просьбу о счёте (invoice_intent) ищем только в СОБСТВЕННОМ тексте
 * клиента, без цитаты нашего письма. Кейс M-2026-12166: клиент ответил «а с
 * резьбой М10 есть?» на наше напоминание, в цитате которого были номер КП
 * 364274 и «перейти к выставлению счёта» → роутер «нашёл» запрос счёта в
 * нашем же тексте и увёл заявку в «ждёт счёт». Без invoice_intent MailRouter
 * привязывает письмо к заявке КП (и реанимирует закрытую), но статус не
 * трогает.
 *
 * PDF-текст берём напрямую Smalot'ом (дёшево, без Vision/рендера страниц).
 */
class CitedOutboundQuoteRouter
{
    /** Контекст «счёт/КП/оплата» в тексте письма (гейт матчинга номера). */
    private const KEYWORD_RE = '/сч[её]т|на\s+оплат|коммерческое\s+предложение|\bкп\b|invoice|инвойс/iu';

    /** Числа-кандидаты: 5–8 цифр (наши document_number обычно 6). */
    private const NUMBER_RE = '/\d{5,8}/';

    private const MAX_PDF_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly EmailTextCleanerService $cleaner = new EmailTextCleanerService(),
    ) {
    }

    /**
     * @return array{request: Request, document_number: string, total: float, source: string, invoice_intent: bool}|null
     */
    public function detect(EmailMessage $message): ?array
    {
        // Номер КП ищем во ВСЁМ тексте (в т.ч. в цитате нашего письма — клиент
        // часто отвечает «выставите счёт» под нашим КП, и номер только там):
        // это нужно для ПРИВЯЗКИ письма к заявке. А вот просьба о счёте
        // (invoice_intent) — только из собственного текста клиента.
        [$candidates, $hasAttachmentSource] = $this->collectCandidates($message);
        if ($candidates === []) {
            return null;
        }

        $text = mb_strtolower((string) $message->subject . "\n" . (string) $message->body_plain);
        $keywordHit = preg_match(self::KEYWORD_RE, $text) === 1;
        if (! $keywordHit && ! $hasAttachmentSource) {
            // Числа без контекста счёта/КП и не из вложения — не доверяем.
            return null;
        }
        $invoiceIntent = $this->hasInvoiceIntent((string) $message->subject, $this->ownBodyText($message));

        $rows = DB::table('outbound_quotes')
            ->whereIn('document_number', $candidates)
            ->whereNotNull('request_id')
            ->get(['request_id', 'document_number', 'total_amount']);
        if ($rows->isEmpty()) {
            return null;
        }

        // Несколько процитированных КП → берём с наибольшей суммой.
        $best = $rows->sortByDesc(fn ($r) => (float) ($r->total_amount ?? 0))->first();
        $request = Request::find($best->request_id);
        if (! $request) {
            return null;
        }

        Log::info('CitedOutboundQuoteRouter: matched cited quote', [
            'email_message_id' => $message->id,
            'document_number' => $best->document_number,
            'request_id' => $request->id,
            'candidates_matched' => $rows->pluck('document_number')->all(),
        ]);

        return [
            'request' => $request,
            'document_number' => (string) $best->document_number,
            'total' => (float) ($best->total_amount ?? 0),
            'source' => $hasAttachmentSource ? 'attachment' : 'body',
            'invoice_intent' => $invoiceIntent,
        ];
    }

    /**
     * Собственный текст клиента: без цитаты нашего письма («… написал(а):»,
     * «>»-блок) и без блока пересылки, если у клиента есть своя преамбула.
     * Чистый метод — покрыт unit-тестом.
     */
    public function ownBodyText(EmailMessage $message): string
    {
        // Единый владелец понятия — EmailTextCleanerService::clientOwnText
        // (те же правила: срез любой цитаты + блок пересылки при преамбуле).
        return $this->cleaner->clientOwnText($message);
    }

    /**
     * Есть ли в собственном тексте клиента (или теме) просьба о счёте либо
     * намерение оплатить. Единый владелец понятия — InvoiceMentionMatcher.
     * Раньше здесь была широкая regex (любое «счёт/оплат/выстав»), и вопрос
     * «когда получим по счёту № N» уводил заявку в «ждёт счёт»; реплей
     * 2026-09-09 по 166 письмам с цитатой КП: 33 таких ложных срабатывания.
     */
    public function hasInvoiceIntent(string $subject, string $ownBody): bool
    {
        return (new InvoiceMentionMatcher)->requestsInvoiceOrIntendsToPay($subject . "\n" . $ownBody);
    }

    /**
     * Применить маршрут «клиент процитировал наш КП → запрос счёта»: привязать
     * письмо к заявке КП, реанимировать её если закрыта потерей, перевести в
     * «ждёт счёт» (AwaitingInvoice), записать аудит. Идемпотентно. Перенесено
     * из MailRouter без изменений (2026-09-09).
     *
     * @param  array{request: Request, document_number: string, total: float, source: string, invoice_intent?: bool}  $cited
     */
    public function applyInvoiceRequest(EmailMessage $message, array $cited): Request
    {
        $request = $cited['request'];
        $docNo = $cited['document_number'];

        if ($message->related_request_id !== $request->id) {
            $message->forceFill(['related_request_id' => $request->id])->save();
        }

        // Заявка закрыта потерей (клиент молчал, теперь вернулся за счётом) →
        // реанимируем. reanimate() работает только из closed_lost.
        if ($request->status === \App\Enums\RequestStatus::ClosedLost) {
            try {
                $request = app(\App\Services\Request\RequestStateService::class)->reanimate(
                    $request,
                    null,
                    $message,
                    true,
                    'reanimate_from_cited_quote',
                    sprintf('Клиент прислал запрос счёта по КП %s — реанимация', $docNo),
                );
            } catch (\Throwable $e) {
                Log::warning('MailRouter: cited-quote reanimate failed (non-fatal)', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Перевод в «ждёт счёт» — если уже не на этой/более поздней вехе И
        // клиент в собственном тексте действительно просит счёт/оплату
        // (invoice_intent). Цитирует КП с вопросом («а с резьбой М10 есть?»,
        // M-2026-12166) — письмо привязываем, статус не трогаем.
        $skip = [
            \App\Enums\RequestStatus::AwaitingInvoice,
            \App\Enums\RequestStatus::Invoiced,
            \App\Enums\RequestStatus::Paid,
            \App\Enums\RequestStatus::ClosedWon,
        ];
        $invoiceIntent = (bool) ($cited['invoice_intent'] ?? true);
        if (! $invoiceIntent) {
            Log::info('MailRouter: cited quote without invoice intent — linked, status unchanged', [
                'email_message_id' => $message->id,
                'request_id' => $request->id,
                'document_number' => $docNo,
            ]);
        }
        if ($invoiceIntent && ! in_array($request->status, $skip, true)) {
            $from = $request->status->value;
            $request->status = \App\Enums\RequestStatus::AwaitingInvoice;
            $request->save();
            \App\Models\RequestStateChange::create([
                'request_id' => $request->id,
                'from_status' => $from,
                'to_status' => \App\Enums\RequestStatus::AwaitingInvoice->value,
                'by_user_id' => null,
                'event' => 'invoice_requested_cited_quote',
                'comment' => sprintf('Клиент процитировал КП %s и запросил счёт (маршрут по номеру КП, %s)', $docNo, $cited['source']),
                'payload' => [
                    'document_number' => $docNo,
                    'source' => $cited['source'],
                    'email_message_id' => $message->id,
                ],
            ]);
            try {
                app(\App\Services\Request\AttentionService::class)->recompute($request->fresh());
            } catch (\Throwable $e) {
                Log::info('MailRouter: attention recompute after cited-quote route failed (non-fatal)', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('MailRouter: cited-quote invoice-request routed', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'document_number' => $docNo,
            'source' => $cited['source'],
        ]);

        return $request->fresh();
    }

    /**
     * Числа-кандидаты из темы/тела/имён вложений/текста PDF-вложений.
     *
     * @return array{0: array<int,string>, 1: bool}  [числа, был ли источник-вложение]
     */
    private function collectCandidates(EmailMessage $message): array
    {
        $texts = [(string) $message->subject, (string) $message->body_plain];
        $hasAttachmentSource = false;

        foreach ($message->attachments as $att) {
            $fn = (string) $att->filename;
            if ($fn !== '') {
                $texts[] = $fn;
                if (preg_match(self::NUMBER_RE, $fn) === 1) {
                    $hasAttachmentSource = true; // номер в имени файла
                }
            }
            $pdfText = $this->attachmentPdfText($att);
            if ($pdfText !== '') {
                $texts[] = $pdfText;
                $hasAttachmentSource = true;
            }
        }

        preg_match_all(self::NUMBER_RE, implode(' ', $texts), $m);

        return [array_values(array_unique($m[0] ?? [])), $hasAttachmentSource];
    }

    /** Текст-слой PDF-вложения (Smalot). '' если не PDF/большой/сбой. */
    private function attachmentPdfText(\App\Models\EmailAttachment $att): string
    {
        $mime = (string) $att->mime_type;
        $fn = mb_strtolower((string) $att->filename);
        $isPdf = str_contains($mime, 'pdf') || str_ends_with($fn, '.pdf');
        if (! $isPdf || ! $att->file_path) {
            return '';
        }
        try {
            $path = Storage::disk($att->disk ?: 'local')->path($att->file_path);
            if (! is_file($path) || filesize($path) > self::MAX_PDF_BYTES) {
                return '';
            }

            return (string) (new \Smalot\PdfParser\Parser())->parseFile($path)->getText();
        } catch (\Throwable $e) {
            Log::warning('CitedOutboundQuoteRouter: pdf text extract failed (non-fatal)', [
                'attachment_id' => $att->id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }
}
