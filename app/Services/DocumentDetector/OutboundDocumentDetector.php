<?php

namespace App\Services\DocumentDetector;

use App\Enums\DetectorType;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\Mail\EmailTextCleanerService;
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
    public function __construct(
        private readonly EmailTextCleanerService $cleaner,
    ) {
    }

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
     * Менеджер отказывает «не наш профиль / не наша номенклатура».
     * Точные короткие фразы. Сравнение по str_contains после ё→е fold.
     * Если рядом есть DECLINE_FOLLOWUP_KEYWORDS — это не отказ, это
     * clarification (продолжение работы).
     */
    private const DECLINE_KEYWORDS = [
        'не наша номенклатура',
        'не наш профиль',
        'не наша тема',
        'не наш ассортимент',
        'не работаем с этой',
        'не работаем с этим',
        'мы не работаем с',
        'не занимаемся',
        'не делаем',
        'не торгуем',
        'не поставляем',
        'не наше направление',
        'этого у нас нет',
        'не наш сегмент',
        'ничем не можем помочь',
        'ничем помочь не можем',
    ];

    /**
     * Если в письме рядом с decline-фразой есть один из этих маркеров —
     * это НЕ отказ (менеджер просит дополнительную информацию или предлагает
     * альтернативу). Возвращаем clarification / other вместо declined.
     */
    private const DECLINE_FOLLOWUP_KEYWORDS = [
        'но я',
        'но мы',
        'но могу',
        'но можем',
        'попробую',
        'попробуем',
        'пришлите',
        'можете прислать',
        'уточните',
        'фото',
        'альтернатив',
        'предложить аналог',
        'попробую найти',
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
        // сч[её]?т — ловит «счт» / «счёт» / «счет». Раньше было `счё?т` и
        // распространённое написание «Счет …» (через е) НЕ матчилось, из-за чего
        // счёт в письме с КП оставался неопознанным (M-2026-3456).
        '/(^|[\s_\-])сч[её]?т[\s_\-]/iu',
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
        $hasDeclineKeyword = $this->matchAnyKeyword($text, self::DECLINE_KEYWORDS);
        $hasDeclineFollowup = $hasDeclineKeyword !== null
            ? $this->matchAnyKeyword($text, self::DECLINE_FOLLOWUP_KEYWORDS)
            : null;

        if ($hasQuotationKeyword) {
            $signals['quotation_keyword'] = $hasQuotationKeyword;
        }
        if ($hasInvoiceKeyword) {
            $signals['invoice_keyword'] = $hasInvoiceKeyword;
        }
        if ($hasClarificationKeyword) {
            $signals['clarification_keyword'] = $hasClarificationKeyword;
        }
        if ($hasDeclineKeyword) {
            $signals['decline_keyword'] = $hasDeclineKeyword;
        }
        if ($hasDeclineFollowup) {
            $signals['decline_followup'] = $hasDeclineFollowup;
        }

        // Шаг 3 — финальное решение. Приоритет invoice > quotation > declined > clarification.
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

        // Decline — менеджер сказал «не наш профиль / не наша номенклатура»
        // и нет follow-up'а (просьбы прислать фото и т.п.). Confidence
        // ниже filename-сигналов (0.75), но достаточно для suggestion.
        // Если auto-mode выключен в Settings — менеджер увидит плашку
        // и одним кликом закроет заявку. Phrase + body без followup +
        // отсутствие relevant-вложений = чистый decline.
        if ($hasDeclineKeyword !== null
            && $hasDeclineFollowup === null
            && ! $hasInvoiceFilename
            && ! $hasQuotationFilename
        ) {
            // Cited_phrase: вырезаем строку body содержащую keyword
            // (≤200 симв) для closed_lost_quote в карточке.
            $citedPhrase = $this->extractContainingLine($message, $hasDeclineKeyword);

            return [
                'type' => DetectorType::OutboundDeclined,
                'confidence' => 0.85,
                'signals' => $signals,
                'suggested_closed_lost_reason' => 'off_topic',
                'cited_phrase' => $citedPhrase,
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
     * Вырезать строку body содержащую keyword — для closed_lost_quote.
     * Возвращает trim'нутую строку до 200 символов. Поиск идёт по уже
     * очищенному от цитат body (чтобы не зацепить keyword из цитируемого
     * письма клиента).
     */
    private function extractContainingLine(EmailMessage $message, string $keyword): ?string
    {
        $body = (string) ($message->body_plain ?? '');
        if ($body === '') {
            return null;
        }
        $body = $this->cleaner->cleanInboundReferenceText($body);
        $needle = mb_strtolower(str_replace('ё', 'е', $keyword));
        foreach (preg_split('/\r?\n/', $body) as $line) {
            $hay = mb_strtolower(str_replace('ё', 'е', $line));
            if (str_contains($hay, $needle)) {
                $line = trim($line);
                return $line !== '' ? mb_substr($line, 0, 200) : null;
            }
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
            $type = $this->classifyAttachmentByFilename($fname);
            if ($type === DetectorType::OutboundInvoice) {
                $invoice[] = $fname;
            } elseif ($type === DetectorType::OutboundQuotationFull) {
                $quotation[] = $fname;
            }
        }

        return ['quotation' => $quotation, 'invoice' => $invoice];
    }

    /**
     * Классифицировать ОДНО вложение по имени файла (priority invoice > quotation).
     * Возвращает DetectorType::OutboundInvoice / OutboundQuotationFull, либо null
     * если имя не самоопределяется (или расширение нерелевантно).
     *
     * Используется MailRouter'ом для per-attachment маршрутизации парсинга:
     * одно письмо может нести И КП, И счёт одновременно (M-2026-3456), поэтому
     * каждое вложение парсится по СВОЕМУ типу, а не по типу всего письма.
     */
    public function classifyAttachmentByFilename(string $filename): ?DetectorType
    {
        if ($filename === '') {
            return null;
        }
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($ext, self::RELEVANT_EXTENSIONS, true)) {
            return null;
        }
        foreach (self::INVOICE_FILENAME_RE as $re) {
            if (preg_match($re, $filename) === 1) {
                return DetectorType::OutboundInvoice;
            }
        }
        foreach (self::QUOTATION_FILENAME_RE as $re) {
            if (preg_match($re, $filename) === 1) {
                return DetectorType::OutboundQuotationFull;
            }
        }

        return null;
    }

    private function buildSearchableText(EmailMessage $message): string
    {
        // ВАЖНО: режем quoted-часть письма ДО keyword match.
        // Body_plain включает оригинальное письмо клиента в цитате
        // («> Запрашиваем коммерческое предложение…»), и без очистки
        // выходящий ответ менеджера «Не наша номенклатура» классифицировался
        // как outbound_quotation (keyword «коммерческое предложение» из
        // цитаты клиента). Кейс M-2026-1866.
        $rawBody = (string) ($message->body_plain ?? '');
        $cleanBody = $rawBody !== ''
            ? $this->cleaner->cleanInboundReferenceText($rawBody)
            : '';
        $parts = [
            (string) ($message->subject ?? ''),
            $cleanBody,
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
