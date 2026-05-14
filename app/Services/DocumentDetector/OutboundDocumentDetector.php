<?php

namespace App\Services\DocumentDetector;

use App\Enums\DetectorType;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use Illuminate\Support\Facades\Log;

/**
 * Outbound-детектор документов (Foundation §7.1) — rule-based MVP.
 *
 * Триггер: исходящее письмо из \Sent, привязанное к Request через
 * OutgoingMailLinker. Анализирует имя файла-вложения + ключевые фразы
 * в subject/body, определяет тип события (КП / счёт / уточнение).
 *
 * Возвращает массив `['type' => DetectorType, 'confidence' => float,
 * 'signals' => array]` или null если ничего не найдено.
 *
 * Без OCR/PDF-разбора. Если оператор использует нетипичное имя файла
 * («doc.pdf», «final.pdf») — fallback на body-keywords с пониженной
 * confidence; если и body чистый — null.
 *
 * Priority: invoice > quotation > clarification (по уменьшению специфичности).
 */
class OutboundDocumentDetector
{
    /**
     * RU/EN-фразы маркеров КП в теле/теме письма.
     */
    private const QUOTATION_KEYWORDS = [
        'коммерческое предложение',
        'высылаю кп',
        'кп во вложении',
        'кп прилагаю',
        'отправляем кп',
        'направляю кп',
        'наше предложение',
        'предлагаем',
        'quotation attached',
        'please find quotation',
        'our quote',
    ];

    /**
     * RU/EN-фразы маркеров счёта.
     */
    private const INVOICE_KEYWORDS = [
        'счёт во вложении',
        'счет во вложении',
        'выставляем счёт',
        'выставляем счет',
        'счёт на оплату',
        'счет на оплату',
        'высылаю счёт',
        'высылаю счет',
        'invoice attached',
        'please find invoice',
        'your invoice',
    ];

    /**
     * Запрос уточнения у клиента (без вложений).
     */
    private const CLARIFICATION_KEYWORDS = [
        'прошу уточнить',
        'требуется уточнение',
        'уточните, пожалуйста',
        'для подготовки кп',
        'не хватает информации',
        'пришлите, пожалуйста',
        'можете прислать',
        'пожалуйста, уточните',
    ];

    /**
     * Filename-паттерны КП. Срабатывают на pdf/xlsx/docx.
     */
    private const QUOTATION_FILENAME_RE = [
        '/(^|[\s_\-])кп[\s_\-]/iu',
        '/quotation/i',
        '/(^|[_\-])quote(?![rdmst])/i',  // quote, не quoted/quoter
        '/коммерческ[оае]/iu',
        '/предложен/iu',
    ];

    /**
     * Filename-паттерны счёта.
     */
    private const INVOICE_FILENAME_RE = [
        '/(^|[\s_\-])счё?т[\s_\-]/iu',
        '/invoice/i',
        '/(^|[_\-])bill[\s_\-]/i',
        '/(^|[_\-])inv[\s_\-]/i',
    ];

    private const RELEVANT_EXTENSIONS = ['pdf', 'xlsx', 'xls', 'docx', 'doc'];

    /**
     * @return ?array{type: DetectorType, confidence: float, signals: array<string, mixed>}
     */
    public function analyze(EmailMessage $message, Request $request): ?array
    {
        // Foundation §7.1: outbound-детектор не запускается на закрытых/paused —
        // нечего двигать. Также не запускается если статус уже совпадает с
        // target (например письмо «Re: КП» из quoted состояния — Vision-двойник).
        if ($request->status->isTerminal() || $request->status === RequestStatus::Paused) {
            return null;
        }

        $signals = [];

        // Шаг 1 — анализ имён вложений.
        $attachmentMatches = $this->scanAttachments($message);
        if (! empty($attachmentMatches['quotation'])) {
            $signals['quotation_filenames'] = $attachmentMatches['quotation'];
        }
        if (! empty($attachmentMatches['invoice'])) {
            $signals['invoice_filenames'] = $attachmentMatches['invoice'];
        }

        // Шаг 2 — анализ body + subject (на ключевые фразы).
        $text = $this->buildSearchableText($message);
        $hasQuotationKeyword = $this->matchAnyKeyword($text, self::QUOTATION_KEYWORDS);
        $hasInvoiceKeyword = $this->matchAnyKeyword($text, self::INVOICE_KEYWORDS);
        $hasClarificationKeyword = $this->matchAnyKeyword($text, self::CLARIFICATION_KEYWORDS);

        if ($hasQuotationKeyword) {
            $signals['quotation_keyword'] = $hasQuotationKeyword;
        }
        if ($hasInvoiceKeyword) {
            $signals['invoice_keyword'] = $hasInvoiceKeyword;
        }
        if ($hasClarificationKeyword) {
            $signals['clarification_keyword'] = $hasClarificationKeyword;
        }

        // Шаг 3 — финальное решение. Приоритет invoice > quotation > clarification.
        $hasInvoiceFilename = ! empty($attachmentMatches['invoice']);
        $hasQuotationFilename = ! empty($attachmentMatches['quotation']);

        if ($hasInvoiceFilename || $hasInvoiceKeyword !== null) {
            $confidence = $this->scoreMatch($hasInvoiceFilename, $hasInvoiceKeyword !== null);

            return [
                'type' => DetectorType::OutboundInvoice,
                'confidence' => $confidence,
                'signals' => $signals,
            ];
        }

        if ($hasQuotationFilename || $hasQuotationKeyword !== null) {
            $confidence = $this->scoreMatch($hasQuotationFilename, $hasQuotationKeyword !== null);

            return [
                'type' => DetectorType::OutboundQuotationFull,
                'confidence' => $confidence,
                'signals' => $signals,
            ];
        }

        // Clarification — только если есть body-keyword И нет relevant-вложений.
        // КП/счёт с приложением обычно явно подписаны; «уточните…» без файла —
        // запрос менеджера.
        if ($hasClarificationKeyword !== null
            && ! $hasInvoiceFilename
            && ! $hasQuotationFilename
        ) {
            return [
                'type' => DetectorType::OutboundClarification,
                'confidence' => 0.65,
                'signals' => $signals,
            ];
        }

        return null;
    }

    /**
     * @return array{quotation: list<string>, invoice: list<string>}
     */
    private function scanAttachments(EmailMessage $message): array
    {
        $quotation = [];
        $invoice = [];
        foreach ($message->attachments as $att) {
            $fname = (string) $att->filename;
            if ($fname === '') {
                continue;
            }
            $ext = strtolower((string) pathinfo($fname, PATHINFO_EXTENSION));
            if (! in_array($ext, self::RELEVANT_EXTENSIONS, true)) {
                continue;
            }
            foreach (self::INVOICE_FILENAME_RE as $re) {
                if (preg_match($re, $fname) === 1) {
                    $invoice[] = $fname;
                    continue 2;
                }
            }
            foreach (self::QUOTATION_FILENAME_RE as $re) {
                if (preg_match($re, $fname) === 1) {
                    $quotation[] = $fname;
                    continue 2;
                }
            }
        }

        return ['quotation' => $quotation, 'invoice' => $invoice];
    }

    private function buildSearchableText(EmailMessage $message): string
    {
        $parts = [
            (string) ($message->subject ?? ''),
            (string) ($message->body_plain ?? ''),
        ];
        $text = mb_strtolower(implode(' ', array_filter($parts)));
        // unify ё→е для recall — пишут и так и так.
        return str_replace('ё', 'е', $text);
    }

    private function matchAnyKeyword(string $text, array $keywords): ?string
    {
        foreach ($keywords as $kw) {
            $normalized = str_replace('ё', 'е', mb_strtolower($kw));
            if ($normalized !== '' && str_contains($text, $normalized)) {
                return $kw;
            }
        }

        return null;
    }

    /**
     * Конфиденс по комбинации сигналов.
     *  - filename + body keyword → 0.95 (сильный сигнал)
     *  - только filename         → 0.90
     *  - только body keyword     → 0.60 (слабый)
     */
    private function scoreMatch(bool $hasFilename, bool $hasKeyword): float
    {
        return match (true) {
            $hasFilename && $hasKeyword => 0.95,
            $hasFilename => 0.90,
            $hasKeyword => 0.60,
            default => 0.0,
        };
    }
}
