<?php

namespace App\Services\Mail;

use App\Enums\MailDirection;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Message;

/**
 * Сохраняет распарсенные webklex Message в наш EmailMessage + EmailAttachment.
 *
 * Идемпотентность по Foundation §1: уникальный ключ
 * (mailbox_id, folder, message_id). Один и тот же физический Message-ID может
 * лежать в Inbox получателя и в Sent отправителя — это разные записи.
 *
 * Внимание: webklex 6.x возвращает заголовки и адреса как объекты
 * \Webklex\PHPIMAP\Attribute. У него есть first()/all()/toArray()/__toString,
 * но НЕТ ->values(), как у Laravel Collection. Поэтому работаем через ->all().
 */
class MessagePersister
{
    public function __construct(private readonly string $attachmentDisk = 'local')
    {
    }

    /**
     * @return EmailMessage|null  null = дубликат (пропущен), иначе — сохранённая запись.
     */
    public function persist(Message $msg, Mailbox $mailbox, string $folder, MailDirection $direction): ?EmailMessage
    {
        $messageId = $this->extractMessageId($msg);

        if ($messageId === null) {
            // Без Message-ID полноценная дедупликация невозможна —
            // ставим suffix по UID, чтобы не пропускать письмо.
            $messageId = sprintf('uid-%d-%s', $msg->getUid(), Str::random(16));
        }

        return DB::transaction(function () use ($msg, $mailbox, $folder, $direction, $messageId) {
            $existing = EmailMessage::where('mailbox_id', $mailbox->id)
                ->where('folder', $folder)
                ->where('message_id', $messageId)
                ->first();

            if ($existing) {
                // Phase 1.9 outbound: если это наш собственный sent draft
                // (мы сами создали EmailMessage в OutgoingMailSender и
                // потом APPEND'нули в Sent), то IMAP-копия должна обновить
                // imap_uid + imap_flags + raw_source в существующей записи,
                // а не пропускаться как дубль.
                if ($direction === MailDirection::Outbound
                    && $existing->direction === MailDirection::Outbound
                    && $existing->imap_uid === null) {
                    $existing->update([
                        'imap_uid' => $msg->getUid(),
                        'imap_flags' => $this->extractFlags($msg),
                        'raw_source' => $this->cleanString((string) $msg->getRawBody()),
                    ]);
                }
                // Cross-mailbox delivery: pre-create'ный нашим
                // MailDeliverToManagerService row в личном ящике менеджера.
                // Sync видит existing → только заполняем UID + flags +
                // raw_source. MailRouter (через return null) не запускается,
                // gpt-4o categorize/linker не тратятся, дубля Request нет.
                if ($direction === MailDirection::Inbound
                    && $existing->direction === MailDirection::Inbound
                    && $existing->imap_uid === null) {
                    $existing->update([
                        'imap_uid' => $msg->getUid(),
                        'imap_flags' => $this->extractFlags($msg),
                        'raw_source' => $this->cleanString((string) $msg->getRawBody()),
                    ]);
                }
                return null;
            }

            $email = EmailMessage::create([
                'mailbox_id' => $mailbox->id,
                'folder' => $folder,
                'direction' => $direction->value,
                'imap_uid' => $msg->getUid(),
                'message_id' => $messageId,
                'in_reply_to' => $this->extractInReplyTo($msg),
                'references_header' => $this->extractReferences($msg),
                'subject' => $this->truncate($this->decodeMimeHeader($this->stringify($msg->getSubject())), 998),
                'from_email' => $this->extractFromEmail($msg),
                'from_name' => $this->extractFromName($msg),
                'to_recipients' => $this->extractAddressList($msg, 'to'),
                'cc_recipients' => $this->extractAddressList($msg, 'cc'),
                'sent_at' => $this->extractDate($msg),
                'body_plain' => $this->cleanString((string) $msg->getTextBody()),
                'body_html' => $this->cleanString((string) $msg->getHTMLBody()),
                'raw_source' => $this->cleanString((string) $msg->getRawBody()),
                'headers' => $this->extractAllHeaders($msg),
                'imap_flags' => $this->extractFlags($msg),
            ]);

            foreach ($msg->getAttachments() as $att) {
                $this->persistAttachment($att, $email);
            }

            return $email;
        });
    }

    private function persistAttachment(Attachment $att, EmailMessage $email): void
    {
        // Phase 2.4a fallback: getName() возвращает null ИЛИ пустую строку
        // (iPhone-фотки часто шлются как Content-Type: image/jpeg без
        // параметров name=/filename=). `?? ` ловит только null, поэтому
        // отдельно проверяем после trim. Синтезируем читаемое имя на
        // основе MIME — `inline-a3b2c1d4.jpg` понятнее чем UUID-storage-key.
        //
        // 2026-05-23: до getName() пробуем raw MIME header — Webklex
        // sanitizeName() стрипает `/` из base64 в MIME-encoded словах,
        // что ломает декодирование (length не-кратна 4 → base64_decode
        // выдаёт мусор + control bytes). Кейс M-2026-1471/att#3985.
        // resolveRawFilename() обходит sanitizeName и берёт значение
        // прямо из raw header'а парта.
        $rawName = $this->resolveRawFilename($att);
        if ($rawName === '') {
            $ext = $this->guessExtension((string) $att->getMimeType()) ?: 'bin';
            $disposition = $att->getDisposition() === 'inline' ? 'inline' : 'attachment';
            $rawName = $disposition . '-' . Str::random(8) . '.' . $ext;
        }
        $decodedFilename = $this->decodeMimeHeader($rawName);
        // Mojibake recovery: некоторые отправители (gmail.com forwarder'ы, боты
        // типа liftway) шлют filename как сырые UTF-8 байты без RFC 2047 wrap,
        // а webklex интерпретирует их как Latin-1 → строка содержит «Đµ Đ¾Đ±»
        // вместо «русские буквы». См. recoverMojibake — попытка обратного
        // преобразования. Не трогает уже-корректный UTF-8.
        $decodedFilename = $this->recoverMojibake($decodedFilename);
        // Yandex кейс: после регекс-fallback'а может остаться хвост вида
        // «... pdf» (пробел вместо точки перед расширением — encoded-word
        // обрезался не на границе). Нормализуем на популярных расширениях.
        $decodedFilename = preg_replace(
            '/\s+(pdf|docx?|xlsx?|pptx?|zip|rar|7z|jpe?g|png|gif|tiff?|heic|webp)\s*$/i',
            '.$1',
            $decodedFilename,
        ) ?? $decodedFilename;
        // varchar(255), плюс защита от ультра-длинных имён (Yandex иногда
        // возвращает MIME-encoded имя из 10+ кусков).
        $filename = $this->truncate($decodedFilename, 255);

        $relativePath = sprintf(
            'mail/%d/%s/%s',
            $email->mailbox_id,
            $email->id,
            Str::random(8) . '_' . $this->safeFilename($filename),
        );

        Storage::disk($this->attachmentDisk)->put($relativePath, $att->getContent());

        EmailAttachment::create([
            'email_message_id' => $email->id,
            'filename' => $filename,
            'mime_type' => $att->getMimeType(),
            'size_bytes' => $att->getSize(),
            'content_id' => $att->getContentId(),
            'file_path' => $relativePath,
            'disk' => $this->attachmentDisk,
            'is_inline' => $att->getDisposition() === 'inline',
        ]);
    }

    /**
     * Декодировать MIME-encoded заголовок (`=?utf-8?Q?...?=` / `=?utf-8?B?...?=`)
     * в обычный UTF-8.
     *
     * Робастная цепочка:
     *   1) `iconv_mime_decode` — стандартный путь;
     *   2) `mb_decode_mimeheader` — fallback;
     *   3) ручной regex-парсинг по `=?charset?enc?text?=` токенам — на случай
     *      рваных encoded-word'ов (Yandex иногда отдаёт filename'ы со сломанным
     *      форматированием, где между токенами вставлен пробел или хвост
     *      `?= pdf`).
     *
     * Кейс LZ-REQ-1315: PDF приходил с filename
     *   `=?UTF-8?B?...?= pdf`
     * (пробел перед `pdf` вместо точки) — iconv/mb роняли строку как есть,
     * regex-fallback декодирует encoded-word и оставляет хвост ` pdf` как есть.
     */
    /**
     * Восстановить mojibake'нутый текст (UTF-8 байты прочитанные как Latin-1
     * или Windows-1252). Признак: строка ВАЛИДНА как UTF-8, но содержит
     * характерные «Ð», «Ñ», «Đ» байты сразу после которых идут диакритические
     * латинские символы — это бывшие кириллические буквы.
     *
     * Алгоритм: re-encode строки как Latin-1 (получаем сырые байты),
     * пытаемся прочесть как UTF-8. Если результат валиден и содержит
     * кириллицу — возвращаем его, иначе оригинал.
     *
     * Кейс 2026-05-21: liftway-бот forwards с filename'ом «отчет.pdf»
     * как raw-UTF8 байты в Content-Disposition без RFC 2047 wrap.
     * webklex отдаёт «Đ¾Ñ‚Ñ‡ĐµÑ‚.pdf», recoverMojibake → «отчет.pdf».
     */
    private function recoverMojibake(string $value): string
    {
        if ($value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        // Признак mojibake: содержит «Ð»/«Ñ»/«Đ» с латинской диакритикой рядом.
        // (Чистый английский filename вроде "report.pdf" эти символы не имеет.)
        if (! preg_match('/[ÐĐÑ][\x{0080}-\x{00FF}\x{0100}-\x{017F}]/u', $value)) {
            return $value;
        }

        // Конвертируем строку обратно в Latin-1/Win-1252 (получаем сырые байты),
        // затем читаем эти байты как UTF-8.
        $raw = @mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
        if (! is_string($raw) || $raw === '') {
            return $value;
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return $value;
        }
        // Должна появиться кириллица (или хотя бы убыть mojibake-признаки).
        $hasCyrillic = preg_match('/[\x{0400}-\x{04FF}]/u', $raw);
        if (! $hasCyrillic) {
            return $value;
        }
        return $raw;
    }

    private function decodeMimeHeader(string $value): string
    {
        if ($value === '' || ! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if (is_string($decoded) && $decoded !== '' && ! str_contains($decoded, '=?')) {
            return $decoded;
        }

        $decoded = @mb_decode_mimeheader($value);
        if (is_string($decoded) && $decoded !== '' && ! str_contains($decoded, '=?')) {
            return $decoded;
        }

        // Regex-fallback: декодируем все вхождения `=?charset?enc?text?=` руками.
        // Дополнительно: если B-вариант имеет длину НЕ кратную 4 — это маркер,
        // что Webklex sanitizeName() стрипнул `/` из base64. Пробуем восстановить
        // через repairCorruptedBase64() — вставляет недостающий '/' в каждую
        // позицию, выбирает ту, что даёт валидный UTF-8 с кириллицей.
        $result = preg_replace_callback(
            '/=\?([A-Za-z0-9_\-]+)\?([qQbB])\?([^?]*)\?=/',
            function (array $m): string {
                $charset = $m[1];
                $encoding = strtoupper($m[2]);
                $text = $m[3];

                if ($encoding === 'B') {
                    $bytes = base64_decode($text, true);
                    if ($bytes === false && strlen($text) % 4 !== 0) {
                        $repaired = $this->repairCorruptedBase64($text, $charset);
                        if ($repaired !== null) {
                            $bytes = $repaired;
                        }
                    }
                } else {
                    $bytes = quoted_printable_decode(str_replace('_', ' ', $text));
                }

                if ($bytes === false || $bytes === '') {
                    return $m[0];
                }
                $utf8 = @mb_convert_encoding($bytes, 'UTF-8', $charset);

                return is_string($utf8) && $utf8 !== '' ? $utf8 : $bytes;
            },
            $value,
        );

        return is_string($result) && $result !== '' ? $result : $value;
    }

    /**
     * Получить filename вложения, обходя Webklex sanitizeName().
     *
     * Webklex `Attachment::getName()` пропускает значение через sanitizeName(),
     * который стрипает `/` (опасный для filesystem path). Но в MIME-encoded
     * слове (`=?UTF-8?B?...?=`) тело — это base64-encoded строка, где `/`
     * валидный символ алфавита. Удаление `/` ломает длину (≠ кратной 4),
     * base64_decode падает в strict mode, и в результате декодирования
     * получаем мусор с `?` и control-байтами.
     *
     * Источник истины — raw header attachment'а. Если его нет (старые версии
     * Webklex / нестандартный transport) — fallback на getName() как раньше.
     *
     * Source: LazyLift @ SupplierMailService::resolveAttachmentName (adapted).
     */
    private function resolveRawFilename(Attachment $att): string
    {
        try {
            if (method_exists($att, 'getHeader')) {
                $header = $att->getHeader();
                if ($header !== null && isset($header->raw) && (string) $header->raw !== '') {
                    $extracted = $this->extractFilenameFromRawHeader((string) $header->raw);
                    if ($extracted !== null && $extracted !== '') {
                        return $extracted;
                    }
                }
            }
        } catch (\Throwable) {
            // не критично — упадём в fallback
        }

        return trim((string) ($att->getName() ?? ''));
    }

    /**
     * Извлечь filename= из raw MIME-заголовка, минуя sanitizeName().
     *
     * Покрывает:
     *   1. RFC 2231 single-shot: filename*=charset''percent-encoded
     *   2. MIME encoded-word (RFC 2047): filename="=?UTF-8?B?...?="
     *      Возвращаем raw encoded-string — decodeMimeHeader() её обработает,
     *      включая repair corrupted base64.
     *   3. Plain quoted: filename="имя.pdf"
     *   4. Plain unquoted: filename=name.pdf
     *
     * Source: LazyLift @ SupplierMailService::extractFilenameFromRawHeader
     * (drop-in).
     */
    private function extractFilenameFromRawHeader(string $rawHeader): ?string
    {
        // (1) RFC 2231: filename*=UTF-8''%D0%9F...
        if (preg_match("/filename\*\s*=\s*([^']+)'[^']*'([^\s;]+)/i", $rawHeader, $m)) {
            $charset = $m[1];
            $encoded = rtrim($m[2], " \t;");
            $decoded = rawurldecode($encoded);
            if (stripos($charset, 'UTF-8') !== false && mb_check_encoding($decoded, 'UTF-8')) {
                return $decoded;
            }
            $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);

            return is_string($converted) && $converted !== '' ? $converted : null;
        }

        // (2) MIME encoded-word(s): filename="=?UTF-8?B?...?="
        // Может быть несколько подряд (RFC 2047 §6.2). Возвращаем raw —
        // decodeMimeHeader() декодирует с repair corrupted base64.
        $mimeWordPattern = '=\?[^?]+\?[BQbq]\?[^?]*\?=';
        $pattern = '/(?:filename|name)\s*=\s*"?(' . $mimeWordPattern . '(?:\s*' . $mimeWordPattern . ')*)"?/i';
        if (preg_match($pattern, $rawHeader, $m)) {
            return $m[1];
        }

        // (3) Plain quoted: filename="..."
        if (preg_match('/(?:filename|name)\s*=\s*"([^"]+)"/i', $rawHeader, $m)) {
            return $m[1];
        }

        // (4) Plain unquoted: filename=name.pdf
        if (preg_match('/(?:filename|name)\s*=\s*([^\s;]+)/i', $rawHeader, $m)) {
            $val = rtrim($m[1], '"');

            return $val !== '' ? $val : null;
        }

        return null;
    }

    /**
     * Восстановить base64-данные, повреждённые Webklex sanitizeName() —
     * удалённым `/` (path separator). Длина становится НЕ кратной 4,
     * base64_decode strict возвращает false → весь encoded-word теряется.
     *
     * Алгоритм: для каждой позиции пробуем вставить `/` или `+`, padding'уем,
     * декодируем. Берём кандидата с максимальным score (кол-во кириллических
     * букв + 5 бонус за отсутствие replacement-char).
     *
     * Обрабатываем только 1 missing char — typical Webklex case.
     *
     * Source: LazyLift @ SupplierMailService::repairCorruptedBase64.
     *
     * @return string|null Декодированные байты или null если repair не удался.
     */
    private function repairCorruptedBase64(string $base64, string $charset): ?string
    {
        if (strlen($base64) % 4 === 0) {
            return null;
        }
        $missing = (4 - (strlen($base64) % 4)) % 4;
        if ($missing !== 1) {
            return null; // 2-3 missing — слишком большой brute-force, не пытаемся
        }

        $data = rtrim($base64, '=');
        $tryChars = ['/', '+'];

        $bestDecoded = null;
        $bestScore = 0;

        foreach ($tryChars as $char) {
            for ($pos = 0; $pos <= strlen($data); $pos++) {
                $candidate = substr($data, 0, $pos) . $char . substr($data, $pos);
                $padNeeded = (4 - (strlen($candidate) % 4)) % 4;
                $candidate .= str_repeat('=', $padNeeded);

                $decoded = base64_decode($candidate, true);
                if ($decoded === false) {
                    continue;
                }

                if (stripos($charset, 'UTF-8') !== false) {
                    if (! mb_check_encoding($decoded, 'UTF-8')) {
                        continue;
                    }
                    preg_match_all('/[а-яёА-ЯЁ]/u', $decoded, $matches);
                    $score = count($matches[0]);
                    if (! str_contains($decoded, "\xEF\xBF\xBD")) {
                        $score += 5;
                    }
                    if ($score > $bestScore && $score >= 3) {
                        $bestScore = $score;
                        $bestDecoded = $decoded;
                    }
                }
            }
        }

        return $bestDecoded;
    }

    /**
     * Парсит filename из multi-line Content-Disposition / Content-Type
     * header-блока с поддержкой:
     *   - RFC 2231 continuation (`filename*0*=`, `filename*1*=`, …)
     *   - RFC 5987 single-shot (`filename*=charset''encoded`)
     *   - Plain (`filename="..."` / `filename=...`)
     *   - Альтернатива через `name=...` (Content-Type парам)
     *
     * webklex `Attachment::getName()` не собирает continuation parts корректно
     * и возвращает мусор для filename длиннее одной строки (типичный кейс
     * длинных русских имён). Этот хелпер парсит из raw header'а правильно.
     *
     * Возвращает UTF-8 строку или null если ничего не нашёл.
     *
     * @param  string  $headerBlock  Multi-line Content-Disposition или
     *                                Content-Type блок (как есть из raw_source,
     *                                с возможными CRLF + WSP fold'ами).
     */
    public static function parseRfc2231Filename(string $headerBlock): ?string
    {
        // RFC 5322 fold: «CRLF + WSP» → один пробел. После unfolding всё
        // в одну строку — проще парсить параметры через regex.
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $headerBlock) ?? $headerBlock;

        // (1) RFC 2231 continuation: filename*N*= или filename*N=
        // Собираем все индексы по порядку, склеиваем, URL-decode'им
        // те части где был `*` (encoded form), charset берём из части 0.
        if (preg_match_all(
            '/\bfilename\*(\d+)(\*?)=\s*((?:"[^"]*"|[^;\s]+))/i',
            $unfolded,
            $matches,
            PREG_SET_ORDER,
        )) {
            $parts = [];
            $charset = 'UTF-8';
            foreach ($matches as $m) {
                $idx = (int) $m[1];
                $isEncoded = $m[2] === '*';
                $raw = trim($m[3], '"');
                if ($idx === 0 && $isEncoded && str_contains($raw, "''")) {
                    // charset'language'value
                    [$cs, , $rest] = array_pad(explode("'", $raw, 3), 3, '');
                    $charset = $cs !== '' ? $cs : 'UTF-8';
                    $raw = $rest;
                }
                $parts[$idx] = $isEncoded ? rawurldecode($raw) : $raw;
            }
            ksort($parts);
            $joined = implode('', $parts);
            if (strtoupper($charset) !== 'UTF-8') {
                $converted = @mb_convert_encoding($joined, 'UTF-8', $charset);
                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            }
            return $joined !== '' ? $joined : null;
        }

        // (2) RFC 5987 single-shot без continuation: filename*=charset''encoded
        if (preg_match('/\bfilename\*=\s*((?:"[^"]*"|[^;]+))/i', $unfolded, $m)) {
            $raw = trim(trim($m[1]), '"');
            if (str_contains($raw, "''")) {
                [$cs, , $rest] = array_pad(explode("'", $raw, 3), 3, '');
                $decoded = rawurldecode($rest);
                $cs = $cs !== '' ? $cs : 'UTF-8';
                if (strtoupper($cs) !== 'UTF-8') {
                    $converted = @mb_convert_encoding($decoded, 'UTF-8', $cs);
                    if (is_string($converted) && $converted !== '') {
                        return $converted;
                    }
                }
                return $decoded !== '' ? $decoded : null;
            }
            return $raw !== '' ? $raw : null;
        }

        // (3) Plain filename="..." или filename=...
        if (preg_match('/\bfilename=\s*"([^"]+)"/i', $unfolded, $m)) {
            return trim($m[1]) !== '' ? trim($m[1]) : null;
        }
        if (preg_match('/\bfilename=\s*([^;\s]+)/i', $unfolded, $m)) {
            return trim($m[1]) !== '' ? trim($m[1]) : null;
        }

        // (4) Fallback: name=... в Content-Type (часто content-disposition
        // отсутствует, имя лежит в Content-Type).
        if (preg_match('/\bname=\s*"([^"]+)"/i', $unfolded, $m)) {
            return trim($m[1]) !== '' ? trim($m[1]) : null;
        }
        if (preg_match('/\bname=\s*([^;\s]+)/i', $unfolded, $m)) {
            return trim($m[1]) !== '' ? trim($m[1]) : null;
        }

        return null;
    }

    private function extractMessageId(Message $msg): ?string
    {
        $raw = trim($this->stringify($msg->getMessageId()), " \t\n\r\0\x0B<>");

        return $raw !== '' ? $raw : null;
    }

    private function extractInReplyTo(Message $msg): ?string
    {
        $raw = trim($this->stringify($msg->getInReplyTo()), " \t\n\r\0\x0B<>");

        return $raw !== '' ? $raw : null;
    }

    /**
     * @return array<int, string>|null
     */
    private function extractReferences(Message $msg): ?array
    {
        $raw = $this->stringify($msg->getReferences());
        if ($raw === '') {
            return null;
        }

        // References — это разделённый пробелами список <message-ids>.
        $ids = [];
        foreach (preg_split('/\s+/', $raw) ?: [] as $id) {
            $id = trim($id, "<> \t\r\n");
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids ?: null;
    }

    private function extractFromEmail(Message $msg): string
    {
        $from = $this->firstAddress($msg->getFrom());

        return $from['email'] ?? '';
    }

    private function extractFromName(Message $msg): ?string
    {
        $from = $this->firstAddress($msg->getFrom());

        return $from['name'] ?? null;
    }

    /**
     * @return array<int, array{email: string, name: ?string}>
     */
    private function extractAddressList(Message $msg, string $field): array
    {
        $attribute = match ($field) {
            'to' => $msg->getTo(),
            'cc' => $msg->getCc(),
            default => null,
        };

        if ($attribute === null) {
            return [];
        }

        $items = $this->attributeToArray($attribute);
        $result = [];
        foreach ($items as $addr) {
            if (! is_object($addr)) {
                continue;
            }
            $email = (string) ($addr->mail ?? '');
            if ($email === '') {
                continue;
            }
            $result[] = [
                'email' => $email,
                'name' => isset($addr->personal) ? $this->decodeMimeHeader((string) $addr->personal) : null,
            ];
        }

        return $result;
    }

    /**
     * @return array{email: string, name: ?string}|null
     */
    private function firstAddress(?Attribute $attr): ?array
    {
        if ($attr === null) {
            return null;
        }
        $items = $this->attributeToArray($attr);
        $first = $items[0] ?? null;
        if (! is_object($first)) {
            return null;
        }
        $email = (string) ($first->mail ?? '');
        if ($email === '') {
            return null;
        }

        return [
            'email' => $email,
            'name' => isset($first->personal) ? $this->decodeMimeHeader((string) $first->personal) : null,
        ];
    }

    /**
     * Carbon-дата из Attribute getDate(). Webklex кладёт туда первый элемент Carbon.
     */
    private function extractDate(Message $msg): ?CarbonInterface
    {
        $attr = $msg->getDate();
        if ($attr === null) {
            return null;
        }
        $first = $this->attributeToArray($attr)[0] ?? null;

        return $first instanceof CarbonInterface ? $first : null;
    }

    /**
     * @return array<int, string>
     */
    private function extractFlags(Message $msg): array
    {
        $flags = $msg->getFlags();
        if (! $flags) {
            return [];
        }

        // FlagCollection — Laravel Collection, толерантнее.
        if (method_exists($flags, 'toArray')) {
            $arr = $flags->toArray();

            return array_values(array_filter(
                array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $this->flatten($arr)),
                fn ($v) => $v !== null && $v !== '',
            ));
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractAllHeaders(Message $msg): array
    {
        $header = $msg->getHeader();
        if ($header === null) {
            return [];
        }
        $attrs = $header->getAttributes();
        $out = [];
        foreach ($attrs as $key => $value) {
            $out[(string) $key] = $this->normalizeHeaderValue($value);
        }

        return $out;
    }

    private function normalizeHeaderValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }
        if ($value instanceof Attribute) {
            $items = $value->all();
            if (count($items) === 1) {
                return $this->normalizeHeaderValue($items[0]);
            }

            return array_map(fn ($v) => $this->normalizeHeaderValue($v), $items);
        }
        if (is_object($value)) {
            // Address-объекты и тому подобное — приводим к массиву свойств.
            $vars = get_object_vars($value);
            if ($vars) {
                return array_map(fn ($v) => $this->normalizeHeaderValue($v), $vars);
            }
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return null;
        }
        if (is_array($value)) {
            return array_map(fn ($v) => $this->normalizeHeaderValue($v), $value);
        }

        return null;
    }

    /**
     * Webklex Attribute → массив значений. Безопасно к разным версиям API.
     *
     * @return array<int, mixed>
     */
    private function attributeToArray(mixed $attr): array
    {
        if ($attr instanceof Attribute) {
            return array_values($attr->all());
        }
        if (is_array($attr)) {
            return array_values($attr);
        }
        if (is_object($attr) && method_exists($attr, 'all')) {
            $r = $attr->all();

            return is_array($r) ? array_values($r) : [];
        }

        return [];
    }

    /**
     * Безопасно привести Attribute/object/scalar к строке.
     */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Удалить недопустимые байты UTF-8 (PostgreSQL не примет invalid UTF-8 в text-полях).
     */
    private function cleanString(string $s): string
    {
        if ($s === '') {
            return '';
        }
        // 0x00 не разрешён в text у PostgreSQL.
        $s = str_replace("\0", '', $s);
        if (! mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }

        return $s;
    }

    private function truncate(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._\-]/', '_', $name) ?? 'file';

        return mb_substr($name, 0, 80);
    }

    /**
     * Догадаться о расширении файла по MIME — для синтеза имени, когда
     * Content-Type-header'ы клиента не содержат name=/filename=.
     * Возвращает расширение БЕЗ точки (jpg/png/pdf/…) или null.
     */
    private function guessExtension(string $mime): ?string
    {
        $mime = strtolower(trim($mime));
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/msword' => 'doc',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'text/html' => 'html',
        ];

        return $map[$mime] ?? null;
    }

    /**
     * @param  array<mixed>  $arr
     * @return array<int, mixed>
     */
    private function flatten(array $arr): array
    {
        $out = [];
        array_walk_recursive($arr, function ($v) use (&$out) {
            $out[] = $v;
        });

        return $out;
    }
}
