<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use Illuminate\Support\Facades\Log;

/**
 * Гейт для Phase 1.9 force-парсинга позиций из reply'ев клиента.
 *
 * Проблема: `MailRouter::route()` после прицепления inbound к existing Request
 * запускает `ParseRequestItemsJob::dispatch($id, true)` для извлечения
 * ДОПОЛНИТЕЛЬНЫХ позиций («забыл указать ещё M-1234 - 3 шт»). Но если клиент
 * прислал короткое сопроводительное письмо («Добрый день! Фото прилагаю»)
 * с фото, Vision-парсер ловит attachment и плодит ложные позиции — мы видели
 * это на M-2026-0759, где «Механизм Orona CD1-TV12Q» родился из reply-фото
 * к существующей «Отводка Orona CD1-TV2 IZQ» (Vision прочёл маркировку
 * чуть-чуть иначе).
 *
 * Решение: смотрим есть ли в очищенном теле reply'я СИГНАЛЫ позиции (артикул,
 * количество, индикативные слова). Если ни одного — парсер не запускаем.
 */
class ReplyParseGate
{
    public function __construct(
        private readonly EmailTextCleanerService $cleaner,
    ) {
    }

    /**
     * Стоит ли запускать парсер позиций на этот reply?
     */
    public function shouldParse(EmailMessage $message): bool
    {
        // Структурированный документ во вложении (pdf/xls/xlsx/docx/csv) почти
        // всегда несёт перечень позиций: спека клиента ИЛИ наше КП/Предложение,
        // которое клиент форварднул «хочу это». Парсим независимо от текста
        // reply'я — иначе позиции из вложения теряются (кейс M-2026-9340: Fwd с
        // «Предложение МЗ-355979.pdf», текст-cover без сигналов → skip → КВШ не
        // подтянулся). Служебные (счёт/договор/сертификат) отсеет downstream
        // relevance-гейт парсера. Осторожность gate'а касалась ФОТО (Vision
        // плодил ложные позиции, M-2026-0759) — документы этого не делают.
        if ($this->hasStructuredDocAttachment($message)) {
            return true;
        }

        $cleaned = $this->extractCleanedText($message);

        // Удаляем известные external-маркеры (LZ-REQ-NNNN и т.п.) — это
        // header, не сигнал позиции.
        $patterns = (array) config('services.mail.external_codes', []);
        foreach ($patterns as $p) {
            $cleaned = (string) preg_replace($p, '', $cleaned);
        }
        $cleaned = mb_strtolower(trim($cleaned));

        if ($cleaned === '') {
            $this->logSkip($message, 'cleaned text empty', '');

            return false;
        }

        $signals = (array) config('services.parser.reply_signals', $this->defaultSignals());
        foreach ($signals as $signal) {
            if (@preg_match($signal, $cleaned)) {
                return true;
            }
        }

        $this->logSkip($message, 'no item signals', $cleaned);

        return false;
    }

    /**
     * Есть ли во вложениях структурированный документ (pdf/excel/word/csv) —
     * такой почти всегда несёт перечень позиций. ФОТО сюда НЕ входят (по ним
     * gate остаётся осторожным — Vision плодил ложные позиции, M-2026-0759).
     */
    private function hasStructuredDocAttachment(EmailMessage $message): bool
    {
        $docExt = ['pdf', 'xls', 'xlsx', 'xlsm', 'xlsb', 'docx', 'doc', 'csv', 'tsv', 'ods'];
        foreach ($message->attachments as $a) {
            $ext = strtolower(pathinfo((string) $a->filename, PATHINFO_EXTENSION));
            $mime = strtolower((string) $a->mime_type);
            if (in_array($ext, $docExt, true)
                || str_contains($mime, 'pdf')
                || str_contains($mime, 'spreadsheet')
                || str_contains($mime, 'excel')
                || str_contains($mime, 'msword')
                || str_contains($mime, 'wordprocessing')
                || str_contains($mime, 'csv')
            ) {
                return true;
            }
        }

        return false;
    }

    private function extractCleanedText(EmailMessage $message): string
    {
        $plain = (string) $message->body_plain;
        if ($plain === '' || $this->cleaner->bodyPlainLooksBroken($plain)) {
            $plain = $this->cleaner->htmlToText((string) $message->body_html);
        }

        // dequote + removeSignature (полный pipeline для inbound текста).
        return $this->cleaner->cleanInboundReferenceText($plain);
    }

    /**
     * @return array<int, string>
     */
    private function defaultSignals(): array
    {
        return [
            // M-SKU (внутренний код каталога).
            '/\bm\d{4,}\b/u',
            // Артикул-подобные: «ABC-123», «ZAA12345», «KM12345»
            '/\b[a-z]{2,}-?\d{2,}\b/u',
            // Артикул с подмешанной цифрой/буквой длиной 5+.
            '/\b[a-z0-9]*\d[a-z0-9-]{3,}[a-z]\b/u',
            // Количество: «5 шт», «10 штук», «3шт.»
            '/\b\d+\s*шт\.?\b/u',
            '/\b\d+\s*штук\b/u',
            // Слова-индикаторы заявки.
            '/\b(?:артикул|арт\.?|позиция|позиции|комплект|комплектация|нужн[оы]?|требу[ею]тся|прош[уй]|пришлите|добавьте)\b/u',
        ];
    }

    private function logSkip(EmailMessage $message, string $reason, string $cleaned): void
    {
        Log::info('ReplyParseGate: skip force-parse', [
            'email_message_id' => $message->id,
            'reason' => $reason,
            'cleaned_length' => mb_strlen($cleaned),
            'cleaned_preview' => mb_substr($cleaned, 0, 200),
        ]);
    }
}
