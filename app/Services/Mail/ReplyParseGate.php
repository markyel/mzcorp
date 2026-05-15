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
