<?php

namespace App\Services\Retro;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Читает письма ящика в индекс: <index_path>/<email>/<md5 папки>.jsonl.
 * Отправленные читаются с началом письма (номера и имена вложений, сам текст
 * не сохраняется), остальные папки — только заголовки. Прерванный проход
 * продолжается: уже записанные UID не читаются снова. Базу не меняет.
 */
class RetroScanner
{
    private const BODY_BATCH = 40;

    private const HEADER_BATCH = 300;

    private const HEADER_ITEM = 'BODY.PEEK[HEADER.FIELDS (DATE FROM TO CC SUBJECT MESSAGE-ID IN-REPLY-TO REFERENCES)]';

    /** Служебные папки не читаем: в них нет переписки по заявкам. */
    private const SKIP_FLAGS = '/\\\\(Drafts|Trash|Spam|Junk|Noselect)/i';

    private const SKIP_NAMES = '/^(Outbox|Templates|Archives?)(\||$)/';

    /**
     * @param  callable(string):void|null  $progress
     * @return array{folders:int, read:int}
     */
    public function scan(Mailbox $mailbox, ?callable $progress = null): array
    {
        $dir = rtrim((string) config('retro.index_path'), '/').'/'.$mailbox->email;
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $imap = new RetroImapReader($mailbox);
        $sent = null;
        $others = [];
        foreach ($imap->folders() as $folder => $flags) {
            if (stripos($flags, '\\Sent') !== false) {
                $sent = (string) $folder;
            } elseif (! preg_match(self::SKIP_FLAGS, $flags) && ! preg_match(self::SKIP_NAMES, (string) $folder)) {
                $others[] = (string) $folder;
            }
        }
        $folders = array_values(array_filter(array_merge([$sent], $others), fn ($f) => $f !== null));

        $meta = [];
        $read = 0;
        foreach ($folders as $folder) {
            try {
                [$count, $meta[$folder]] = $this->scanFolder($imap, $dir, $folder, $folder === $sent, $progress);
                $read += $count;
                file_put_contents($dir.'/folders.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
            } catch (Throwable $e) {
                Log::error('Retro scan failed', ['mailbox_id' => $mailbox->id, 'folder' => $folder, 'error' => $e->getMessage()]);
                throw $e;
            }
        }
        $imap->close();

        return ['folders' => count($folders), 'read' => $read];
    }

    /** @return array{0:int, 1:array{uidvalidity:?int, uidnext:?int, unseen:list<int>}} */
    private function scanFolder(RetroImapReader $imap, string $dir, string $folder, bool $withBody, ?callable $progress): array
    {
        $file = $dir.'/'.md5($folder).'.jsonl';
        $done = [];
        if (is_file($file)) {
            foreach (file($file) as $line) {
                $row = json_decode($line, true);
                if (is_array($row) && isset($row['uid'])) {
                    $done[(int) $row['uid']] = true;
                }
            }
        }
        $since = (string) config('retro.scan_since');
        $state = $this->retrying($imap, $folder, fn () => $imap->examine($folder), $progress, false);
        $state['unseen'] = $this->retrying($imap, $folder, fn () => $imap->search('UNSEEN SINCE '.$since), $progress);
        $found = $this->retrying($imap, $folder, fn () => $imap->search('SINCE '.$since), $progress);
        $todo = array_values(array_filter($found, fn ($uid) => ! isset($done[$uid])));
        if ($progress) {
            $progress($folder.': новых писем '.count($todo).($withBody ? ' (с началом письма)' : ' (заголовки)'));
        }
        $items = $withBody
            ? 'UID INTERNALDATE BODYSTRUCTURE BODY.PEEK[]<0.'.(int) config('retro.peek_bytes').'>'
            : 'UID INTERNALDATE '.self::HEADER_ITEM;

        $handle = fopen($file, 'a');
        $count = 0;
        foreach (array_chunk($todo, $withBody ? self::BODY_BATCH : self::HEADER_BATCH) as $chunk) {
            foreach ($imap->fetchResilient($folder, $chunk, $items, $progress) as [$line, $literals]) {
                $row = $this->row($line, $literals, $folder, $withBody);
                if ($row !== null) {
                    fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);
                    $count++;
                }
            }
            if ($progress && $count % 2000 < count($chunk)) {
                $progress($folder.': прочитано '.$count.' из '.count($todo));
            }
            usleep(150000);
        }
        fclose($handle);

        return [$count, $state];
    }

    /**
     * Яндекс обрывает долгие соединения: повторяем с новым соединением и нарастающей паузой.
     *
     * @template T
     *
     * @param  callable():T  $action
     * @return T
     */
    private function retrying(RetroImapReader $imap, string $folder, callable $action, ?callable $progress, bool $reopenFolder = true): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $action();
            } catch (Throwable $e) {
                if ($try >= 6) {
                    throw $e;
                }
                if ($progress) {
                    $progress("повтор $try, $folder: ".$e->getMessage());
                }
                sleep(20 * $try);
                $imap->connect();
                if ($reopenFolder) {
                    $imap->examine($folder);
                }
            }
        }
    }

    private function row(string $line, array $literals, string $folder, bool $withBody): ?array
    {
        if ($literals === [] || ! preg_match('/UID (\d+)/', $line, $uid)) {
            return null;
        }
        preg_match('/INTERNALDATE "([^"]+)"/', $line, $date);
        $raw = '';
        foreach ($literals as $literal) {
            if (strlen($literal) > strlen($raw)) {
                $raw = $literal;
            }
        }
        $head = preg_split("/\r?\n\r?\n/", $raw, 2)[0] ?? '';
        $subject = RetroMailParser::decoded(RetroMailParser::headerValue($head, 'Subject'));
        $row = [
            'uid' => (int) $uid[1],
            'folder' => $folder,
            'idate' => $date[1] ?? null,
            'date' => RetroMailParser::headerValue($head, 'Date'),
            'from' => RetroMailParser::decoded(RetroMailParser::headerValue($head, 'From')),
            'to' => RetroMailParser::decoded(RetroMailParser::headerValue($head, 'To')),
            'cc' => RetroMailParser::decoded(RetroMailParser::headerValue($head, 'Cc')),
            'subject' => $subject,
            'mid' => RetroMailParser::headerValue($head, 'Message-ID'),
            'irt' => RetroMailParser::headerValue($head, 'In-Reply-To'),
            'refs' => RetroMailParser::headerValue($head, 'References'),
            'codes' => RetroMailParser::codes($subject),
        ];
        if ($withBody) {
            $text = RetroMailParser::searchableText(RetroMailParser::bodyParts($raw));
            $row['crm'] = RetroMailParser::headerValue($head, 'X-MyLift-Author-User-Id') !== '';
            $row['foot'] = RetroMailParser::footerCodes($text);
            $row['bodyc'] = RetroMailParser::codes($text);
            $row['names'] = RetroMailParser::attachmentNames($line);
        }

        return $row;
    }
}
