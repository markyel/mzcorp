<?php

namespace App\Services\Retro;

use App\Models\Mailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Скачивает вложения восстановленных писем заранее, чтобы КП и счета лежали в
 * заявке сразу, а не после нажатия кнопки. Читает только через PEEK.
 * Прерванный проход продолжается: готовые письма отмечены body_fetched_at.
 */
class RetroAttachmentFetcher
{
    /** Целые письма тяжёлые: за один FETCH немного. */
    private const BATCH = 5;

    private const DISK = 'local';

    public const SCOPE_DOCUMENTS = 'documents';

    public const SCOPE_OPEN = 'open';

    /**
     * @param  callable(string):void|null  $progress
     * @return array{messages:int, files:int, bytes:int, missing:int}
     */
    public function fetch(Mailbox $mailbox, string $scope, ?callable $progress = null): array
    {
        $stats = ['messages' => 0, 'files' => 0, 'bytes' => 0, 'missing' => 0];
        $rows = $this->pending($mailbox, $scope);
        if ($rows->isEmpty()) {
            return $stats;
        }
        $imap = new RetroImapReader($mailbox);
        foreach ($rows->groupBy('folder') as $folder => $inFolder) {
            $imap->examine((string) $folder);
            if ($progress) {
                $progress($folder.': писем '.$inFolder->count());
            }
            foreach ($inFolder->chunk(self::BATCH) as $chunk) {
                $byUid = $chunk->keyBy('imap_uid');
                $fetched = [];
                foreach ($imap->fetchResilient((string) $folder, $byUid->keys()->all(), 'UID BODY.PEEK[]', $progress) as [$line, $literals]) {
                    if ($literals !== [] && preg_match('/UID (\d+)/', $line, $uid) && isset($byUid[(int) $uid[1]])) {
                        $this->store($byUid[(int) $uid[1]], $mailbox->id, $literals, $stats);
                        $fetched[(int) $uid[1]] = true;
                    }
                }
                $stats['missing'] += $chunk->count() - count($fetched);
                if ($progress && $stats['messages'] % 500 < self::BATCH) {
                    $progress(sprintf('%s: писем %d, файлов %d, %d МБ', $folder, $stats['messages'], $stats['files'], $stats['bytes'] / 1048576));
                }
            }
        }
        $imap->close();

        return $stats;
    }

    /** Привязывает файлы к записям КП и счетов: по номеру документа в имени файла. */
    public function linkDocuments(): int
    {
        $rows = DB::table('outbound_quotes as q')
            ->join('email_attachments as a', 'a.email_message_id', '=', 'q.email_message_id')
            ->whereNull('q.email_attachment_id')
            ->orderBy('a.id')
            ->get(['q.id as quote_id', 'q.document_number', 'a.id as attachment_id', 'a.filename']);
        $linked = [];
        foreach ($rows as $r) {
            if (isset($linked[$r->quote_id])) {
                continue;
            }
            $document = RetroMailParser::documentFromFilename((string) $r->filename);
            if ($document !== null && $document['number'] === (string) $r->document_number) {
                DB::table('outbound_quotes')->where('id', $r->quote_id)->update(['email_attachment_id' => $r->attachment_id]);
                $linked[$r->quote_id] = true;
            }
        }

        return count($linked);
    }

    private function pending(Mailbox $mailbox, string $scope)
    {
        $query = DB::table('email_messages as e')
            ->where('e.mailbox_id', $mailbox->id)
            ->whereNull('e.body_fetched_at')
            ->whereNotNull('e.imap_uid')
            ->whereRaw("jsonb_exists(e.detected_artifacts, 'retro')");

        if ($scope === self::SCOPE_DOCUMENTS) {
            $query->whereIn('e.id', DB::table('outbound_quotes')->select('email_message_id'));
        } else {
            $query->where('e.history_has_attachments', true)
                ->whereRaw("NOT jsonb_exists(e.detected_artifacts, 'cross_mailbox_copy_of')")
                ->where('e.category', '!=', 'supplier_reply')
                ->whereIn('e.related_request_id', DB::table('requests')->select('id')->where('status', '!=', 'closed_lost'));
        }

        return $query->orderByDesc('e.sent_at')->get(['e.id', 'e.folder', 'e.imap_uid']);
    }

    private function store(object $row, int $mailboxId, array $literals, array &$stats): void
    {
        $raw = '';
        foreach ($literals as $literal) {
            if (strlen($literal) > strlen($raw)) {
                $raw = $literal;
            }
        }
        try {
            $attachments = RetroMailParser::attachments($raw);
            $exists = DB::table('email_attachments')->where('email_message_id', $row->id)->exists();
            if (! $exists) {
                foreach ($attachments as $file) {
                    $name = mb_substr(str_replace("\0", '', mb_scrub($file['filename'], 'UTF-8')), 0, 255);
                    $safe = substr((string) preg_replace('/[^A-Za-z0-9._\-]/', '_', $name), -120) ?: 'file';
                    $path = sprintf('mail/%d/%d/%s_%s', $mailboxId, $row->id, Str::random(8), $safe);
                    Storage::disk(self::DISK)->put($path, $file['content']);
                    DB::table('email_attachments')->insert([
                        'email_message_id' => $row->id,
                        'filename' => $name,
                        'mime_type' => $file['mime'],
                        'size_bytes' => strlen($file['content']),
                        'content_id' => $file['content_id'] !== null ? mb_substr($file['content_id'], 0, 255) : null,
                        'file_path' => $path,
                        'disk' => self::DISK,
                        'is_inline' => $file['inline'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $stats['files']++;
                    $stats['bytes'] += strlen($file['content']);
                }
            }
            DB::table('email_messages')->where('id', $row->id)->update([
                'body_fetched_at' => now(),
                'history_has_attachments' => array_filter($attachments, fn ($f) => ! $f['inline']) !== [],
            ]);
            $stats['messages']++;
        } catch (Throwable $e) {
            Log::error('Retro attachment store failed', ['email_message_id' => $row->id, 'error' => $e->getMessage()]);
        }
    }
}
