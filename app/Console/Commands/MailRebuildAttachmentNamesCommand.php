<?php

namespace App\Console\Commands;

use App\Models\EmailAttachment;
use App\Services\Mail\MessagePersister;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\Message as WebklexMessage;

/**
 * Backfill `email_attachments.filename` для исторических битых имён.
 *
 * Отличия от старого `mail:redecode-attachment-names-from-raw`:
 *   - матч attachment ↔ MIME-part через Webklex `Message::fromString` +
 *     соответствие по `mime_type + size_bytes` (с tolerance), а не по
 *     порядку появления (старая команда часто давала PDF имя PNG-подписи);
 *   - использует свежий `MessagePersister::resolveRawFilename` который
 *     обходит Webklex `sanitizeName()` (стрипает `/` из base64) — тот же
 *     decoder что для новых писем после коммита fa047c9.
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

    protected $description = 'Backfill email_attachments.filename через Webklex MimeMessage + новый decoder (RawFilename + repair base64).';

    /**
     * Размер по mime_type — допустимое расхождение в долях.
     * Webklex getSize() возвращает decoded body size, БД size_bytes исторически
     * хранила encoded (base64 ~33% больше) → если одинаковый mime даёт ровно
     * одного кандидата, мы берём его без size-проверки вообще.
     * При нескольких кандидатах по mime — фильтруем по best size match.
     */
    private const SIZE_TOLERANCE_RATIO = 0.5;

    public function __construct(private readonly MessagePersister $persister)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $singleId = $this->option('attachment') ? (int) $this->option('attachment') : null;

        // Кандидаты: filename содержит признаки битости —
        // повторные `?`, control-bytes (записаны как `_` в file_path
        // sanitizer'ом, но в самом filename могут оставаться '?'/'\f'/'\x00').
        // Дополнительно — slashes (`/` или `\`) в filename: corrupted base64.
        $query = EmailAttachment::query()
            ->select(['id', 'email_message_id', 'filename', 'mime_type', 'size_bytes']);

        if ($singleId !== null) {
            $query->where('id', $singleId);
        } else {
            $query->where(function ($q) {
                $q->where('filename', 'like', '%?%?%')
                    ->orWhere('filename', 'like', '%/%')
                    ->orWhere('filename', 'like', '%\\%');
                // control-byte сложно искать SQL'ом — propagate post-filter ниже.
            });
        }

        $rows = $query->orderBy('email_message_id')->get();
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
            'parse_failed' => 0,
            'no_match' => 0,
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

            try {
                /** @var WebklexMessage $msg */
                $msg = WebklexMessage::fromString($rawSource);
                $msgAtts = $msg->getAttachments();
            } catch (\Throwable $e) {
                $stats['parse_failed'] += $atts->count();
                $this->error("  msg#{$msgId}: parse failed — {$e->getMessage()}");
                continue;
            }

            foreach ($atts as $dbAtt) {
                $resolved = $this->matchAttachmentToParts($dbAtt, $msgAtts);
                if ($resolved === null) {
                    $stats['no_match']++;
                    $this->line(sprintf(
                        '  att#%d: нет соответствующего MIME-part (mime=%s, size=%d)',
                        $dbAtt->id,
                        $dbAtt->mime_type,
                        $dbAtt->size_bytes,
                    ));
                    continue;
                }

                $newRaw = $this->persister->resolveRawFilename($resolved);
                if ($newRaw === '') {
                    $stats['no_match']++;
                    continue;
                }

                $decoded = $this->persister->decodeMimeHeader($newRaw);
                $decoded = $this->persister->recoverMojibake($decoded);
                $decoded = (string) preg_replace(
                    '/\s+(pdf|docx?|xlsx?|pptx?|zip|rar|7z|jpe?g|png|gif|tiff?|heic|webp)\s*$/i',
                    '.$1',
                    $decoded,
                );
                $decoded = Str::limit(trim($decoded), 255, '');

                // Если новое имя такое же кривое (содержит `?`, control bytes) —
                // не пишем, оставляем старое. Лучше старое чем новое такое же.
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

        $this->newLine();
        $this->info(sprintf(
            'Готово: переименовано=%d · без_изменений=%d · нет_raw=%d · parse_failed=%d · нет_match=%d · still_broken=%d',
            $stats['renamed'],
            $stats['unchanged'],
            $stats['no_raw_source'],
            $stats['parse_failed'],
            $stats['no_match'],
            $stats['still_broken'],
        ));
        if ($dry) {
            $this->warn('--dry-run: изменения НЕ записаны в БД.');
        }

        Log::info('mail:rebuild-attachment-names done', $stats);

        return self::SUCCESS;
    }

    /**
     * Найти в коллекции Webklex-attachment'ов один соответствующий записи в БД.
     *
     * Алгоритм:
     *   1. Filter по mime_type (если оба заполнены — должны совпадать).
     *   2. Если после filter ровно один кандидат — берём его (size не сверяем,
     *      БД хранила encoded size исторически, Webklex отдаёт decoded —
     *      расхождение ~33% в base64-случае).
     *   3. Если несколько — выбираем тот, у кого size ближе всего к
     *      dbSize (по ratio, tolerance 0.5).
     *   4. Если ни одного по mime — null.
     */
    private function matchAttachmentToParts(EmailAttachment $dbAtt, \Webklex\PHPIMAP\Support\AttachmentCollection $msgAtts): ?\Webklex\PHPIMAP\Attachment
    {
        $dbMime = mb_strtolower(trim((string) $dbAtt->mime_type));
        $dbSize = (int) $dbAtt->size_bytes;

        $sameMime = [];
        foreach ($msgAtts as $att) {
            $partMime = mb_strtolower(trim((string) $att->getMimeType()));
            if ($dbMime !== '' && $partMime !== '' && $dbMime !== $partMime) {
                continue;
            }
            $sameMime[] = $att;
        }

        if (count($sameMime) === 0) {
            return null;
        }
        if (count($sameMime) === 1) {
            return $sameMime[0];
        }

        // Несколько кандидатов с одинаковым mime — ищем по size proximity.
        if ($dbSize <= 0) {
            return $sameMime[0]; // фолбэк: первый
        }

        $best = null;
        $bestDelta = PHP_INT_MAX;
        foreach ($sameMime as $att) {
            $partSize = (int) $att->getSize();
            $delta = abs($partSize - $dbSize);
            // tolerance: 50% от max(dbSize, partSize). Allows base64 inflation.
            $threshold = (int) (max($dbSize, $partSize) * self::SIZE_TOLERANCE_RATIO);
            if ($delta > $threshold) {
                continue;
            }
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $best = $att;
            }
        }
        return $best;
    }

    /**
     * Имя «битое» если содержит `?` (replacement), control-bytes (\x00-\x1F),
     * либо подряд idущие подчёркивания (sanitized из path separator) занимают
     * больше половины длины.
     */
    private function looksBroken(string $name): bool
    {
        if ($name === '') {
            return true;
        }
        // Control bytes (включая 0x0C из MIME-decode мусора).
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return true;
        }
        // Заменённые символы UTF-8 (U+FFFD).
        if (str_contains($name, "\xEF\xBF\xBD")) {
            return true;
        }
        // Много `?` подряд — mojibake.
        if (preg_match('/\?{2,}/', $name)) {
            return true;
        }
        // Подчёркивания + `?` больше половины — pseudonym из sanitize.
        $len = mb_strlen($name);
        $junk = preg_match_all('/[_?]/u', $name);
        if ($len > 8 && $junk > $len / 2) {
            return true;
        }
        return false;
    }
}
