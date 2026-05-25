<?php

namespace App\Console\Commands;

use App\Models\EmailAttachment;
use App\Services\Mail\MessagePersister;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Backfill `email_attachments.filename` для исторических битых имён,
 * читая их напрямую из raw RFC822 body (email_messages.raw_source).
 *
 * 2026-05-25 переписана с mime-group bucket-индексирования на чистый
 * ordinal-match через `MessagePersister::extractFilenamesFromRawBody`:
 *
 *   1. Загружаем письма (фильтр: «битые-выглядящие» имена ИЛИ --all).
 *   2. Парсим filename'ы из raw_source в документ-ордере.
 *   3. Если count(parsed) === count(attachments письма) → mapping
 *      по ordinal. Иначе msg пропускается (типичный кейс пересланный
 *      message/rfc822 с вложенным MIME tree'ем — ordinal ненадёжен).
 *   4. Для каждой пары (stored, proposed): если различаются и proposed
 *      выглядит не битым → обновляем.
 *
 * Старая mime-group реализация (до 2026-05-25) ломалась на сложных
 * структурах (PDF + 2 PNG inline в multipart/related): индекс
 * within-mime не соответствовал insertion-order БД из-за рекурсивного
 * обхода Webklex'а в multipart/alternative + multipart/related.
 *
 * Usage:
 *   php artisan mail:rebuild-attachment-names --dry-run
 *   php artisan mail:rebuild-attachment-names --all --limit=2000
 *   php artisan mail:rebuild-attachment-names --attachment=4747
 *   php artisan mail:rebuild-attachment-names --message=3882
 */
class MailRebuildAttachmentNamesCommand extends Command
{
    protected $signature = 'mail:rebuild-attachment-names
        {--dry-run : Показать что будет переименовано без записи}
        {--all : Обработать ВСЕ attachment\'ы (не только подозрительные)}
        {--limit=1000 : Максимум писем за прогон}
        {--attachment= : Точечный режим: ID конкретного attachment\'а}
        {--message= : Точечный режим: ID конкретного email-message}';

    protected $description = 'Backfill email_attachments.filename через extractFilenamesFromRawBody (ordinal match).';

    public function __construct(private readonly MessagePersister $persister)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all');
        $limit = (int) $this->option('limit');
        $singleAttId = $this->option('attachment') ? (int) $this->option('attachment') : null;
        $singleMsgId = $this->option('message') ? (int) $this->option('message') : null;

        // Фильтр писем для обработки.
        $msgQuery = DB::table('email_messages as m')
            ->whereNotNull('m.raw_source')
            ->where('m.raw_source', '!=', '')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('email_attachments as a')
                    ->whereColumn('a.email_message_id', 'm.id');
            });

        if ($singleAttId !== null) {
            $msgQuery->where('m.id', function ($q) use ($singleAttId) {
                $q->select('email_message_id')->from('email_attachments')->where('id', $singleAttId)->limit(1);
            });
        } elseif ($singleMsgId !== null) {
            $msgQuery->where('m.id', $singleMsgId);
        } elseif (! $all) {
            // По умолчанию — только подозрительные имена (для скорости и безопасности).
            $msgQuery->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('email_attachments as a2')
                    ->whereColumn('a2.email_message_id', 'm.id')
                    ->where(function ($w) {
                        $w->where('a2.filename', 'like', '%?%?%')
                            ->orWhere('a2.filename', 'like', '%Д`%')
                            ->orWhere('a2.filename', 'like', '%/%')
                            ->orWhere('a2.filename', 'like', '%\\%');
                    });
            });
        }

        $msgIds = $msgQuery->orderByDesc('m.id')->limit($limit)->pluck('m.id');

        if ($msgIds->isEmpty()) {
            $this->info('Кандидатов нет.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Кандидатов: %d писем. Dry-run: %s. Mode: %s.',
            $msgIds->count(),
            $dry ? 'да' : 'НЕТ (запись в БД)',
            $singleAttId !== null ? "att#{$singleAttId}" : ($singleMsgId !== null ? "msg#{$singleMsgId}" : ($all ? 'all' : 'suspect-only')),
        ));

        if (! $dry && $singleAttId === null && $singleMsgId === null && ! $this->confirm('Реально применить изменения?', false)) {
            $this->warn('Отменено.');
            return self::SUCCESS;
        }

        $stats = [
            'renamed' => 0,
            'unchanged' => 0,
            'no_raw' => 0,
            'mismatch_skip' => 0,
            'no_proposal' => 0,
            'proposal_still_broken' => 0,
        ];

        foreach ($msgIds as $msgId) {
            $msg = DB::table('email_messages')->where('id', $msgId)->first(['id', 'raw_source']);
            if (! $msg || ! is_string($msg->raw_source) || $msg->raw_source === '') {
                $stats['no_raw']++;
                continue;
            }

            $atts = EmailAttachment::where('email_message_id', $msgId)->orderBy('id')->get();
            if ($atts->isEmpty()) {
                continue;
            }

            $parsed = MessagePersister::extractFilenamesFromRawBody($msg->raw_source);

            if (count($parsed) !== $atts->count()) {
                $stats['mismatch_skip'] += $atts->count();
                $this->line(sprintf(
                    '  msg#%d: ordinal mismatch (atts=%d parsed=%d) — skip',
                    $msgId,
                    $atts->count(),
                    count($parsed),
                ));
                continue;
            }

            foreach ($atts as $i => $att) {
                if ($singleAttId !== null && $att->id !== $singleAttId) {
                    continue;
                }

                $proposed = $parsed[$i] ?? '';
                if ($proposed === '') {
                    $stats['no_proposal']++;
                    continue;
                }

                // Тот же decode-pipeline что в MessagePersister::persistAttachment.
                $proposed = $this->persister->decodeMimeHeader($proposed);
                $proposed = $this->persister->recoverMojibake($proposed);
                $proposed = (string) preg_replace(
                    '/\s+(pdf|docx?|xlsx?|pptx?|zip|rar|7z|jpe?g|png|gif|tiff?|heic|webp)\s*$/i',
                    '.$1',
                    $proposed,
                );
                $proposed = Str::limit(trim($proposed), 255, '');

                if ($proposed === '' || $this->looksBroken($proposed)) {
                    $stats['proposal_still_broken']++;
                    continue;
                }

                if ($proposed === $att->filename) {
                    $stats['unchanged']++;
                    continue;
                }

                $this->line(sprintf(
                    '  att#%-5d msg#%-5d: %s  →  %s',
                    $att->id,
                    $msgId,
                    mb_substr((string) $att->filename, 0, 50),
                    mb_substr($proposed, 0, 80),
                ));

                if (! $dry) {
                    EmailAttachment::where('id', $att->id)->update(['filename' => $proposed]);
                }
                $stats['renamed']++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Готово: переименовано=%d · без_изменений=%d · нет_raw=%d · mismatch_skip=%d · no_proposal=%d · proposal_still_broken=%d',
            $stats['renamed'],
            $stats['unchanged'],
            $stats['no_raw'],
            $stats['mismatch_skip'],
            $stats['no_proposal'],
            $stats['proposal_still_broken'],
        ));
        if ($dry) {
            $this->warn('--dry-run: изменения НЕ записаны.');
        }

        Log::info('mail:rebuild-attachment-names done', $stats);

        return self::SUCCESS;
    }

    /**
     * Имя «битое» если содержит U+FFFD, control bytes или подряд `?`.
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
        if (mb_strpos($name, 'Д`') !== false) {
            return true;
        }
        return false;
    }
}
