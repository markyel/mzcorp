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
 * PDF-текст берём напрямую Smalot'ом (дёшево, без Vision/рендера страниц).
 */
class CitedOutboundQuoteRouter
{
    /** Контекст «счёт/КП/оплата» в тексте письма. */
    private const KEYWORD_RE = '/сч[её]т|на\s+оплат|коммерческое\s+предложение|\bкп\b|invoice|инвойс/iu';

    /** Числа-кандидаты: 5–8 цифр (наши document_number обычно 6). */
    private const NUMBER_RE = '/\d{5,8}/';

    private const MAX_PDF_BYTES = 8 * 1024 * 1024;

    /**
     * @return array{request: Request, document_number: string, total: float, source: string}|null
     */
    public function detect(EmailMessage $message): ?array
    {
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
        ];
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
