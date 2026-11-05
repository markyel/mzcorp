<?php

namespace App\Services\Retro;

use App\Models\Mailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Подтягивает текст восстановленных писем заявок, чтобы карточка открывалась
 * без ожидания почтового сервера. Вложения не качает: они скачиваются по
 * кнопке (EmailMessage::needsBodyFetch), поэтому body_fetched_at не ставится.
 * Читает только через PEEK.
 */
class RetroBodyHydrator
{
    private const BATCH = 30;

    /**
     * @param  callable(string):void|null  $progress
     * @return array{updated:int, missing:int}
     */
    public function hydrate(Mailbox $mailbox, ?callable $progress = null): array
    {
        $stats = ['updated' => 0, 'missing' => 0];
        $folders = DB::table('email_messages')
            ->where('mailbox_id', $mailbox->id)
            ->whereNotNull('related_request_id')
            ->whereNotNull('imap_uid')
            ->whereNull('body_plain')
            ->whereRaw("jsonb_exists(detected_artifacts, 'retro')")
            ->whereRaw("NOT jsonb_exists(detected_artifacts, 'cross_mailbox_copy_of')")
            ->distinct()->pluck('folder');
        if ($folders->isEmpty()) {
            return $stats;
        }

        $imap = new RetroImapReader($mailbox);
        $peek = (int) config('retro.hydrate_peek_bytes');
        foreach ($folders as $folder) {
            $imap->examine((string) $folder);
            $rows = DB::table('email_messages')
                ->where('mailbox_id', $mailbox->id)->where('folder', $folder)
                ->whereNotNull('related_request_id')->whereNotNull('imap_uid')->whereNull('body_plain')
                ->whereRaw("jsonb_exists(detected_artifacts, 'retro')")
                ->whereRaw("NOT jsonb_exists(detected_artifacts, 'cross_mailbox_copy_of')")
                ->orderByDesc('sent_at')
                ->get(['id', 'imap_uid', 'detected_artifacts']);
            if ($progress) {
                $progress($folder.': писем '.$rows->count());
            }
            foreach ($rows->chunk(self::BATCH) as $chunk) {
                $byUid = $chunk->keyBy('imap_uid');
                $fetched = $this->fetch($imap, (string) $folder, $byUid->keys()->all(), $peek);
                foreach ($fetched as $uid => $data) {
                    $row = $byUid[$uid] ?? null;
                    if ($row === null) {
                        continue;
                    }
                    $artifacts = json_decode((string) $row->detected_artifacts, true) ?: [];
                    $artifacts['retro']['attachments'] = array_slice($data['names'], 0, 50);
                    DB::table('email_messages')->where('id', $row->id)->update([
                        'body_plain' => $this->clean(RetroMailParser::plainBody($data)),
                        'body_html' => $this->clean($data['html']),
                        'history_has_attachments' => $data['names'] !== [],
                        'detected_artifacts' => json_encode($artifacts, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    ]);
                    $stats['updated']++;
                }
                $stats['missing'] += $chunk->count() - count($fetched);
                if ($progress && $stats['updated'] % 3000 < self::BATCH) {
                    $progress($folder.': загружено '.$stats['updated']);
                }
                usleep(100000);
            }
        }
        $imap->close();

        return $stats;
    }

    /** @return array<int, array{plain:string, html:string, names:list<string>}> */
    private function fetch(RetroImapReader $imap, string $folder, array $uids, int $peek): array
    {
        try {
            $rows = $imap->fetchResilient($folder, $uids, 'UID BODYSTRUCTURE BODY.PEEK[]<0.'.$peek.'>');
        } catch (Throwable $e) {
            Log::error('Retro hydrate failed', ['folder' => $folder, 'error' => $e->getMessage()]);
            throw $e;
        }
        $out = [];
        foreach ($rows as [$line, $literals]) {
            if ($literals === [] || ! preg_match('/UID (\d+)/', $line, $uid)) {
                continue;
            }
            $raw = '';
            foreach ($literals as $literal) {
                if (strlen($literal) > strlen($raw)) {
                    $raw = $literal;
                }
            }
            $out[(int) $uid[1]] = RetroMailParser::bodyParts($raw) + ['names' => RetroMailParser::attachmentNames($line)];
        }

        return $out;
    }

    private function clean(string $text): string
    {
        return str_replace("\0", '', mb_scrub($text, 'UTF-8'));
    }
}
