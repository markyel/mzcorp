<?php

namespace App\Console\Commands;

use App\Models\EmailAttachment;
use App\Services\Mail\MessagePersister;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Backfill `email_attachments.filename` для исторических битых имён.
 *
 * Стратегия:
 *   1. Найти подозрительные filename'ы (`??`, `/`, `\`, control bytes).
 *   2. Для каждого письма зачитать raw_source.
 *   3. Регексом вытащить ВСЕ Content-Disposition / Content-Type блоки
 *      с filename/name (с поддержкой RFC 5322 line folding).
 *   4. Для каждого блока определить mime_type и extract'нуть raw-имя
 *      через MessagePersister::extractFilenameFromRawHeader (приватная,
 *      эту логику делаем тут локально).
 *   5. Группировать БД-attachments по mime_type, raw-блоки по mime_type.
 *   6. Внутри группы matching по индексу — порядок появления в raw_source
 *      совпадает с порядком в БД (insert порядок = MIME-парт порядок).
 *   7. Decode имя через MessagePersister::decodeMimeHeader + recoverMojibake.
 *   8. Если новое имя «выглядит читаемым» (нет control bytes / U+FFFD /
 *      кратных `?`) и отличается от текущего — update.
 *
 * Почему НЕ Webklex Message::fromString: проверка показала, что Webklex
 * парсер теряет вложенные attachment'ы (msg#2957: 1 PDF + 2 PNG в raw,
 * Webklex отдаёт только 2 PNG). Прямой regex-парсинг raw_source даёт
 * полную картину.
 *
 * Почему index-within-mime, а не index-overall: MIME-парты PDF и PNG
 * могут идти вперемешку. Index by overall — хрупкий (как в старой
 * mail:redecode-attachment-names-from-raw). Index within same mime_type
 * — надёжный для типичных писем (1-2 PDF, 1-2 PNG).
 *
 * Usage:
 *   php artisan mail:rebuild-attachment-names --dry-run
 *   php artisan mail:rebuild-attachment-names --limit=500
 *   php artisan mail:rebuild-attachment-names --attachment=3985
 */
class MailRebuildAttachmentNamesCommand extends Command
{
    protected $signature = 'mail:rebuild-attachment-names
        {--dry-run : Показать что будет переименовано без записи}
        {--limit=1000 : Максимум email-message обработать за прогон}
        {--attachment= : Точечный режим: ID конкретного attachment\'а}';

    protected $description = 'Backfill email_attachments.filename через regex-парсинг raw_source + новый decoder.';

    public function __construct(private readonly MessagePersister $persister)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $singleId = $this->option('attachment') ? (int) $this->option('attachment') : null;

        $query = EmailAttachment::query()
            ->select(['id', 'email_message_id', 'filename', 'mime_type', 'size_bytes']);

        if ($singleId !== null) {
            $query->where('id', $singleId);
        } else {
            $query->where(function ($q) {
                $q->where('filename', 'like', '%?%?%')
                    ->orWhere('filename', 'like', '%/%')
                    ->orWhere('filename', 'like', '%\\%');
            });
        }

        $rows = $query->orderBy('email_message_id')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            $this->info('Подозрительных filename не найдено.');
            return self::SUCCESS;
        }

        $byMessage = $rows->groupBy('email_message_id');
        $this->info(sprintf(
            'Кандидатов: %d вложений в %d письмах. Лимит писем: %d. Dry-run: %s.',
            $rows->count(),
            $byMessage->count(),
            $limit,
            $dry ? 'да' : 'нет',
        ));

        $stats = [
            'renamed' => 0,
            'unchanged' => 0,
            'no_raw_source' => 0,
            'no_part_for_mime' => 0,
            'still_broken' => 0,
        ];
        $messageCount = 0;

        foreach ($byMessage as $msgId => $atts) {
            if (++$messageCount > $limit) {
                $this->warn("Достигнут лимит писем --limit={$limit}; остаток пропущен.");
                break;
            }

            $rawSource = DB::table('email_messages')->where('id', $msgId)->value('raw_source');
            if (! is_string($rawSource) || $rawSource === '') {
                $stats['no_raw_source'] += $atts->count();
                $this->line("  msg#{$msgId}: raw_source пуст — skip ({$atts->count()} att)");
                continue;
            }

            // Парсим все «filename-несущие» MIME-парт-headers из raw_source.
            // Группируем по mime_type — порядок появления внутри группы
            // должен совпадать с insertion order в БД.
            $partsByMime = $this->extractFilenamesFromRawSource($rawSource);

            // Группируем БД-attachments этого msg по mime_type, сохраняя порядок id.
            $attsByMime = $atts->sortBy('id')->groupBy(
                fn (EmailAttachment $a) => mb_strtolower(trim((string) $a->mime_type))
            );

            foreach ($attsByMime as $mime => $mimeGroup) {
                $partsList = $partsByMime[$mime] ?? [];

                foreach ($mimeGroup->values() as $idx => $dbAtt) {
                    if (! isset($partsList[$idx])) {
                        $stats['no_part_for_mime']++;
                        $this->line(sprintf(
                            '  att#%d: mime=%s idx=%d — нет соответствующего MIME-part (parts_count=%d)',
                            $dbAtt->id,
                            $mime,
                            $idx,
                            count($partsList),
                        ));
                        continue;
                    }

                    $newRaw = $partsList[$idx];
                    if ($newRaw === '') {
                        $stats['no_part_for_mime']++;
                        continue;
                    }

                    // Decode + cleanup pipeline тот же что в MessagePersister.
                    $decoded = $this->persister->decodeMimeHeader($newRaw);
                    $decoded = $this->persister->recoverMojibake($decoded);
                    $decoded = (string) preg_replace(
                        '/\s+(pdf|docx?|xlsx?|pptx?|zip|rar|7z|jpe?g|png|gif|tiff?|heic|webp)\s*$/i',
                        '.$1',
                        $decoded,
                    );
                    $decoded = Str::limit(trim($decoded), 255, '');

                    if ($decoded === '' || $this->looksBroken($decoded)) {
                        $stats['still_broken']++;
                        $this->line(sprintf(
                            '  att#%d: новое имя тоже битое (%s) — skip',
                            $dbAtt->id,
                            mb_substr($decoded, 0, 60),
                        ));
                        continue;
                    }

                    if ($decoded === $dbAtt->filename) {
                        $stats['unchanged']++;
                        continue;
                    }

                    $this->line(sprintf(
                        '  att#%d: %s  →  %s',
                        $dbAtt->id,
                        mb_substr((string) $dbAtt->filename, 0, 60),
                        mb_substr($decoded, 0, 80),
                    ));

                    if (! $dry) {
                        EmailAttachment::where('id', $dbAtt->id)->update(['filename' => $decoded]);
                    }
                    $stats['renamed']++;
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Готово: переименовано=%d · без_изменений=%d · нет_raw=%d · нет_part_for_mime=%d · still_broken=%d',
            $stats['renamed'],
            $stats['unchanged'],
            $stats['no_raw_source'],
            $stats['no_part_for_mime'],
            $stats['still_broken'],
        ));
        if ($dry) {
            $this->warn('--dry-run: изменения НЕ записаны в БД.');
        }

        Log::info('mail:rebuild-attachment-names done', $stats);

        return self::SUCCESS;
    }

    /**
     * Извлечь все Content-Disposition / Content-Type блоки с filename/name
     * из raw_source. Группирует по mime_type, сохраняя порядок появления.
     *
     * Один MIME-парт обычно даёт ДВА совпадения (Content-Type + Content-
     * Disposition), оба с одним и тем же name=/filename=. Сохраняем
     * уникальные пары (mime, raw_filename) по индексу.
     *
     * @return array<string, list<string>> Map<mime_lower, list<raw_filename_value>>
     */
    private function extractFilenamesFromRawSource(string $raw): array
    {
        // (1) Найти все MIME-парт-границы. Каждый «парт» начинается с
        // headers (Content-Type / Content-Disposition / Content-Transfer-Encoding)
        // и продолжается до boundary. Проще: ищем Content-Type заголовки —
        // каждый = новый парт. RFC 5322 line-folding учитываем.
        preg_match_all(
            '/Content-Type:[^\r\n]*(?:\r?\n[ \t]+[^\r\n]+)*/i',
            $raw,
            $ctMatches,
            PREG_OFFSET_CAPTURE,
        );

        // Также нам нужны Content-Disposition блоки рядом с Content-Type,
        // потому что filename часто там, а не в Content-Type.
        preg_match_all(
            '/Content-Disposition:[^\r\n]*(?:\r?\n[ \t]+[^\r\n]+)*/i',
            $raw,
            $cdMatches,
            PREG_OFFSET_CAPTURE,
        );

        $result = [];
        $seenOffsets = [];

        foreach ($ctMatches[0] as $ctMatch) {
            $headerBlock = $ctMatch[0];
            $offset = $ctMatch[1];

            // Берём mime_type из Content-Type.
            if (! preg_match('/Content-Type:\s*([^;\s]+)/i', $headerBlock, $mm)) {
                continue;
            }
            $mime = mb_strtolower(trim($mm[1]));
            if ($mime === '' || $mime === 'multipart/mixed' || $mime === 'multipart/alternative'
                || $mime === 'multipart/related' || $mime === 'text/plain' || $mime === 'text/html'
            ) {
                continue;
            }

            // Ищем filename в Content-Type или в ближайшем Content-Disposition
            // в окне +500 байт после Content-Type (типичная близость).
            $extractFromText = $this->extractFilenameRaw($headerBlock);
            if ($extractFromText === null) {
                // Ищем Content-Disposition в пределах 500 байт.
                foreach ($cdMatches[0] as $cd) {
                    $cdOffset = $cd[1];
                    if ($cdOffset >= $offset && $cdOffset - $offset < 500) {
                        $extractFromText = $this->extractFilenameRaw($cd[0]);
                        if ($extractFromText !== null) {
                            break;
                        }
                    }
                }
            }

            if ($extractFromText !== null && $extractFromText !== '') {
                // Уникальность по offset — чтобы не дублировать если CT+CD
                // обработаны вместе.
                if (! in_array($offset, $seenOffsets, true)) {
                    $result[$mime][] = $extractFromText;
                    $seenOffsets[] = $offset;
                }
            }
        }

        return $result;
    }

    /**
     * Локальная копия MessagePersister::extractFilenameFromRawHeader.
     * Дублируем — этот метод приватный в персистере; делать его публичным
     * только ради backfill-команды — излишне для разовой задачи.
     */
    private function extractFilenameRaw(string $rawHeader): ?string
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

        // (2) MIME encoded-word: filename="=?UTF-8?B?...?=" (может быть несколько)
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
     * Имя «битое» если содержит U+FFFD, control bytes, или много `?` подряд.
     */
    private function looksBroken(string $name): bool
    {
        if ($name === '') {
            return true;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return true;
        }
        if (str_contains($name, "\xEF\xBF\xBD")) {
            return true;
        }
        if (preg_match('/\?{2,}/', $name)) {
            return true;
        }
        return false;
    }
}
