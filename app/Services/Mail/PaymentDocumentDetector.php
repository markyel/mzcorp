<?php

namespace App\Services\Mail;

use App\Enums\ClosedLostReason;
use App\Enums\EmailCategory;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Invoice;
use App\Models\Request;
use App\Services\Invoices\PaymentImportService;
use App\Services\Request\AttentionService;
use App\Services\Request\RequestStateService;
use Illuminate\Support\Facades\Log;

/**
 * Платёжное поручение во вложении = подтверждение оплаты по УЖЕ выставленному
 * счёту, а не новая заявка.
 *
 * Кейс M-2026-14395 (02.09.2026): клиент с мобильной Почты Mail прислал голый
 * PDF — тема = «b094243a-….pdf», тело 34 символа, ни In-Reply-To, ни References.
 * Классификатор (CategorizeIncomingPrompt, ПРАВИЛО 3: «пустое тело + PDF →
 * client_request, имя вложения значения не имеет») поставил client_request 0.85 —
 * содержимое вложения он не видит в принципе. Линкеру зацепиться не за что:
 * треда нет. Единственная связь с нашей сделкой — «Счет на оплату № 8772»
 * ВНУТРИ платёжки; парсер этот текст читает (classifyHeavyAttachmentRelevance),
 * но выбрасывал вместе с вердиктом doc_type в лог.
 *
 * Проверено по логам за 14 дней: из 23 писем с платёжными документами 18
 * корректно прицепились к существующим заявкам (это были Re:/Fwd: в живых
 * тредах — линкер работает), и ровно 5 без тредовых заголовков породили
 * заявки-фантомы. Регрессии нет, дыра узкая — «платёжка без треда».
 *
 * Поэтому:
 *   1) record()            — вердикт вложения (doc_type + номер счёта из текста)
 *                            сохраняем в email_messages.detected_artifacts;
 *   2) handleEmptyRequest() — в ParseRequestItemsJob, ДО назначения менеджера:
 *                            письмо → post_sale, фантом гасим. Счёт нашли —
 *                            письмо переезжает на заявку счёта + алерт 🛒
 *                            менеджеру; не нашли — просто постпродажа
 *                            (угадывать заказ нельзя, см. PostSaleFulfillmentDetector).
 */
class PaymentDocumentDetector
{
    /** doc_type от ClassifyAttachmentRelevancePrompt, означающий платёжный документ. */
    private const DOC_TYPE_RE = '/плат[её]жн\w*\s+(поручени|документ)|плат[её]жк|квитанц|подтверждени\w*\s+оплат|\bчек\b/iu';

    /** Номер счёта не бывает длиннее 8 знаков — отсекаем р/с (20), ИНН, БИК. */
    private const MAX_INVOICE_DIGITS = 8;

    public function __construct(
        private readonly PaymentImportService $payments,
        private readonly AttentionService $attention,
        private readonly RequestStateService $state,
    ) {
    }

    /**
     * Зафиксировать вердикт по вложению в detected_artifacts письма, если это
     * платёжный документ. Вызывается из RequestItemParsingService, у которого
     * уже есть и doc_type от LLM, и извлечённый текст файла — второй раз
     * читать/классифицировать не нужно.
     *
     * Fail-soft: любая ошибка не должна ронять парсинг.
     */
    public function record(EmailAttachment $att, ?string $docType, string $text): void
    {
        if ($docType === null || preg_match(self::DOC_TYPE_RE, $docType) !== 1) {
            return;
        }

        try {
            $message = EmailMessage::find($att->email_message_id);
            if (! $message) {
                return;
            }

            $invoiceNumber = $this->extractInvoiceNumber($text);

            $artifacts = is_array($message->detected_artifacts) ? $message->detected_artifacts : [];
            $artifacts['payment_document'] = [
                'attachment_id' => $att->id,
                'filename' => $att->filename,
                'doc_type' => $docType,
                'invoice_number' => $invoiceNumber,
                'detected_at' => now()->toIso8601String(),
            ];
            $message->forceFill(['detected_artifacts' => $artifacts])->save();

            Log::info('PaymentDocumentDetector: payment document recorded', [
                'email_message_id' => $message->id,
                'attachment_id' => $att->id,
                'doc_type' => $docType,
                'invoice_number' => $invoiceNumber,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PaymentDocumentDetector: record failed (non-fatal)', [
                'attachment_id' => $att->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Гард пустой заявки: письмо оказалось платёжкой. Возвращает true, если
     * заявку-фантом обработали (вызывающий должен выйти, НЕ назначая менеджера
     * и не отправляя «заявка принята»).
     */
    public function handleEmptyRequest(EmailMessage $message): bool
    {
        // record() пишет артефакт по своей копии модели в ходе ЭТОГО же
        // парсинга — у экземпляра, который держит job, detected_artifacts
        // остались загруженными до старта. Без refresh() маркер не виден.
        $message->refresh();

        $marker = is_array($message->detected_artifacts)
            ? ($message->detected_artifacts['payment_document'] ?? null)
            : null;
        if (! is_array($marker)) {
            return false;
        }

        $phantom = Request::find($message->related_request_id);
        if (! $phantom || $phantom->status->isTerminal() || $phantom->items()->count() > 0) {
            return false;
        }

        $invoice = $this->resolveInvoice($marker['invoice_number'] ?? null, $message);
        $target = $invoice?->request;
        // Заявка счёта должна быть чужой для фантома и живой записью.
        if ($target !== null && $target->id === $phantom->id) {
            $target = null;
        }

        $update = ['category' => EmailCategory::PostSale->value];
        if ($target !== null) {
            $update['related_request_id'] = $target->id;
        }
        $message->forceFill($update)->save();

        if ($target !== null) {
            $this->state->systemCloseLost(
                $phantom,
                ClosedLostReason::Duplicate,
                sprintf(
                    'Письмо — платёжное поручение по счёту %s (заявка %s), а не новая заявка. Переписка перенесена туда.',
                    $invoice->invoice_number,
                    $target->internal_code,
                ),
            );
            try {
                $this->attention->onPostSaleMessage($target->fresh());
            } catch (\Throwable $e) {
                Log::warning('PaymentDocumentDetector: attention failed (non-fatal)', [
                    'request_id' => $target->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            $this->state->systemCloseLost(
                $phantom,
                ClosedLostReason::PostSaleCorrespondence,
                sprintf(
                    'Письмо — платёжный документ («%s»), счёт по нему не найден. Постпродажная переписка, не новая заявка.',
                    (string) ($marker['doc_type'] ?? 'платёжный документ'),
                ),
            );
        }

        Log::info('PaymentDocumentDetector: suppressed phantom request from payment document', [
            'email_message_id' => $message->id,
            'phantom_request_id' => $phantom->id,
            'invoice_number' => $marker['invoice_number'] ?? null,
            'invoice_id' => $invoice?->id,
            'target_request_id' => $target?->id,
        ]);

        return true;
    }

    /**
     * Найти наш счёт по номеру из платёжки. Номера счетов не уникальны глобально
     * (1С нумерует по-своему), поэтому обязательный гард — тот же клиент.
     */
    public function resolveInvoice(mixed $rawNumber, EmailMessage $message): ?Invoice
    {
        if (! is_string($rawNumber) || trim($rawNumber) === '') {
            return null;
        }
        $normalized = $this->payments->normalizeNumber($rawNumber);
        if ($normalized === null) {
            return null;
        }

        $clientEmail = mb_strtolower(trim((string) $message->from_email));
        if ($clientEmail === '') {
            return null;
        }

        return Invoice::query()
            ->with('request')
            ->whereHas('request', fn ($q) => $q->whereRaw('lower(client_email) = ?', [$clientEmail]))
            ->orderByDesc('issued_at')
            ->get()
            ->first(fn (Invoice $inv) => $this->payments->normalizeNumber((string) $inv->invoice_number) === $normalized);
    }

    /**
     * Вытащить номер счёта из текста платёжного поручения — ссылку на счёт из
     * назначения платежа («Счет на оплату № 8772 от 21 августа 2026»).
     *
     * По области «назначение платежа» текст НЕ режем: в извлечённом из PDF
     * потоке порядок блоков формы не совпадает с визуальным — подпись поля
     * оказывается ПОСЛЕ самого назначения. Реквизитный мусор отсекаем иначе:
     *   - банковские счета в платёжке пишутся сокращённо («Сч. № 40702810…»),
     *     а regex требует полное «счет/счёт» — не матчатся;
     *   - собственный номер поручения идёт как «ПЛАТЕЖНОЕ ПОРУЧЕНИЕ № 36»,
     *     без слова «счёт» — тоже мимо;
     *   - страховкой режем кандидатов длиннее 8 цифр (р/с — 20, ИНН, БИК).
     */
    public function extractInvoiceNumber(string $text): ?string
    {
        $patterns = [
            '/сч[её]т\w*\s+на\s+оплату\s*(?:№|n|#)?\s*([^\s,;.]+)/iu',
            '/сч[её]т\w*\s*(?:№|n|#)\s*([^\s,;.]+)/iu',
        ];

        foreach ($patterns as $re) {
            if (preg_match($re, $text, $m) !== 1) {
                continue;
            }
            $candidate = trim($m[1]);
            if ($this->payments->normalizeNumber($candidate) === null) {
                continue;
            }
            if (mb_strlen((string) preg_replace('/\D/u', '', $candidate)) > self::MAX_INVOICE_DIGITS) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}
