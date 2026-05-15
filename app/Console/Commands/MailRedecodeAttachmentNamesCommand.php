<?php

namespace App\Console\Commands;

use App\Models\EmailAttachment;
use Illuminate\Console\Command;

/**
 * Re-decode исторических `email_attachments.filename` которые
 * хранятся в raw MIME-encoded виде (`=?UTF-8?B?...?=`).
 *
 * Причина: до фикса в `MessagePersister::decodeMimeHeader` (2026-05-15)
 * filename'ы с рваными encoded-words (Yandex иногда отдаёт PDF с filename
 * вида `=?UTF-8?B?...?= pdf` — пробел вместо точки) попадали в БД
 * не декодированными. Из-за этого:
 *   - в UI отображается base64-имя,
 *   - OutboundDocumentDetector не матчит regex'ы по filename
 *     (`/предложен/iu` не сработает на base64).
 *
 * После фикса новый поток декодит корректно. Эта команда чинит уже
 * накопленные записи.
 *
 * Usage:
 *   php artisan mail:redecode-attachment-names                # apply
 *   php artisan mail:redecode-attachment-names --dry-run      # только показать
 */
class MailRedecodeAttachmentNamesCommand extends Command
{
    protected $signature = 'mail:redecode-attachment-names
        {--dry-run : Показать что будет переименовано без записи}
        {--limit=10000 : Максимум записей за прогон}';

    protected $description = 'Re-decode email_attachments.filename, содержащие raw MIME encoded-words (=?charset?B?...?=).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $rows = EmailAttachment::query()
            ->where('filename', 'like', '%=?%')
            ->where('filename', 'like', '%?=%')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Нет filename с raw encoded-word, всё уже декодировано.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Найдено: %d (limit %d). Режим: %s.',
            $rows->count(), $limit, $dryRun ? 'DRY-RUN' : 'APPLY',
        ));

        $changed = 0;
        $unchanged = 0;
        $preview = [];

        foreach ($rows as $att) {
            $old = (string) $att->filename;
            $new = $this->decodeMimeHeader($old);
            $new = preg_replace(
                '/\s+(pdf|docx?|xlsx?|pptx?|zip|rar|7z|jpe?g|png|gif|tiff?|heic|webp)\s*$/i',
                '.$1',
                $new,
            ) ?? $new;

            if ($new === $old) {
                $unchanged++;
                continue;
            }

            if (! $dryRun) {
                $att->forceFill(['filename' => mb_substr($new, 0, 255)])->save();
            }
            $changed++;

            if (count($preview) < 10) {
                $preview[] = [
                    'id' => $att->id,
                    'old' => mb_substr($old, 0, 60),
                    'new' => mb_substr($new, 0, 60),
                ];
            }
        }

        $this->table(['id', 'old (60)', 'new (60)'], $preview);
        $this->info(sprintf(
            '%s: %d переименовано, %s unchanged (decode не дал отличий).',
            $dryRun ? 'DRY-RUN' : 'APPLY',
            $changed,
            $unchanged,
        ));

        return self::SUCCESS;
    }

    /**
     * Копия `MessagePersister::decodeMimeHeader` (DRY нарушено сознательно
     * чтобы CLI был независимым).
     */
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

        $result = preg_replace_callback(
            '/=\?([A-Za-z0-9_\-]+)\?([qQbB])\?([^?]*)\?=/',
            function (array $m): string {
                $charset = $m[1];
                $encoding = strtoupper($m[2]);
                $text = $m[3];
                $bytes = $encoding === 'B'
                    ? base64_decode($text, true)
                    : quoted_printable_decode(str_replace('_', ' ', $text));
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
}
