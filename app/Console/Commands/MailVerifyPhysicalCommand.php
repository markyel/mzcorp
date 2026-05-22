<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Services\Mail\MailboxConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Физическая верификация: для каждого письма проверить через IMAP,
 * совпадает ли БД-значение `folder` с реальностью.
 *
 * Для каждого письма из выборки делаем два SEARCH по Message-ID:
 *   1) в INBOX
 *   2) в БД-папке (folder из email_messages)
 * И сводим в один из вердиктов:
 *   only_in_target — MOVE отработал штатно (БД = реальность).
 *   only_in_inbox  — MOVE НЕ сработал, БД врёт (Yandex CLIENTBUG?).
 *   in_both        — COPY сработал, EXPUNGE — нет (Yandex quirk).
 *   nowhere        — не найдено нигде (странно — удалили?).
 *
 * Запуск:
 *   php artisan mail:verify-physical --latest=20
 *   php artisan mail:verify-physical --message-id=2592
 *   php artisan mail:verify-physical --mailbox=1 --latest=10
 */
class MailVerifyPhysicalCommand extends Command
{
    protected $signature = 'mail:verify-physical
        {--latest=10 : сколько последних "переехавших" проверить}
        {--message-id= : email_message.id (точечно)}
        {--mailbox= : ограничить ящиком}';

    protected $description = 'Сверить БД-folder с физическим расположением писем в IMAP.';

    public function handle(MailboxConnector $connector): int
    {
        $latest = (int) $this->option('latest');
        $explicitId = $this->option('message-id') ? (int) $this->option('message-id') : null;
        $mailboxId = $this->option('mailbox') ? (int) $this->option('mailbox') : null;

        $q = EmailMessage::query()
            ->select(['id', 'mailbox_id', 'folder', 'imap_uid', 'message_id', 'subject', 'from_email']);

        if ($explicitId) {
            $q->whereKey($explicitId);
        } else {
            // По дефолту смотрим только записи с БД-folder начинающимся на
            // MZ/ или MZ| — это те, которые мы пытались переместить через
            // routeToManager. Личные ящики тоже могут иметь такие записи
            // (если ParseRequestItemsJob сделал routing в самом personal),
            // их тоже включаем.
            $q->where(function ($w) {
                $w->where('folder', 'like', 'MZ|%')
                  ->orWhere('folder', 'like', 'MZ/%');
            })->orderByDesc('id')->limit($latest);
        }
        if ($mailboxId) {
            $q->where('mailbox_id', $mailboxId);
        }

        $messages = $q->get();
        if ($messages->isEmpty()) {
            $this->warn('Нет писем под критерии. Попробуй убрать --mailbox или взять --latest=больше.');
            return self::SUCCESS;
        }

        $this->info("Проверка {$messages->count()} писем (IMAP SEARCH по Message-ID)…");
        $this->newLine();

        // Группируем по mailbox чтобы открывать клиент один раз на ящик.
        $byMailbox = $messages->groupBy('mailbox_id');
        $stats = ['only_in_target' => 0, 'only_in_inbox' => 0, 'in_both' => 0, 'nowhere' => 0, 'error' => 0];
        $rows = [];

        foreach ($byMailbox as $mailboxId => $msgs) {
            $first = $msgs->first();
            $mailbox = $first->mailbox; // lazy load
            if (! $mailbox) {
                foreach ($msgs as $m) {
                    $stats['error']++;
                    $rows[] = [$m->id, '—', '—', '—', '—', 'no mailbox', mb_strimwidth((string) $m->subject, 0, 30, '…')];
                }
                continue;
            }

            $client = null;
            try {
                $client = $connector->imapClient($mailbox);
                foreach ($msgs as $m) {
                    // Yandex IMAP SEARCH HEADER Message-ID не работает —
                    // используем UID FETCH. Проверяем что imap_uid (новый,
                    // после MOVE) реально существует в target папке.
                    // Для INBOX используем тот же uid — он может остаться
                    // как «удалённая но не expunged» копия (если EXPUNGE
                    // не прошёл по Yandex CLIENTBUG).
                    $uid = (int) $m->imap_uid;
                    if ($uid <= 0) {
                        $rows[] = [$m->id, $m->mailbox_id, $m->folder ?? '—', '—', '—', 'no imap_uid', mb_strimwidth((string) $m->subject, 0, 30, '…')];
                        $stats['error']++;
                        continue;
                    }

                    $inTarget = ($m->folder !== null && $m->folder !== 'INBOX')
                        ? $this->uidExistsInFolder($client, (string) $m->folder, $uid)
                        : false;
                    $inInbox = $this->uidExistsInFolder($client, 'INBOX', $uid);

                    $verdict = match (true) {
                        $inTarget && $inInbox => 'in_both',
                        $inTarget && ! $inInbox => 'only_in_target',
                        ! $inTarget && $inInbox => 'only_in_inbox',
                        default => 'nowhere',
                    };
                    $stats[$verdict] = ($stats[$verdict] ?? 0) + 1;

                    $rows[] = [
                        $m->id,
                        $m->mailbox_id,
                        mb_strimwidth((string) ($m->folder ?? '—'), 0, 18, '…'),
                        $inInbox ? 'YES' : '·',
                        $inTarget ? 'YES' : '·',
                        $verdict,
                        mb_strimwidth((string) $m->subject, 0, 40, '…'),
                    ];
                }
            } catch (\Throwable $e) {
                $this->error("Ящик #{$mailboxId}: {$e->getMessage()}");
                Log::warning('mail:verify-physical: imap fail', [
                    'mailbox_id' => $mailboxId,
                    'error' => $e->getMessage(),
                ]);
                foreach ($msgs as $m) {
                    $stats['error']++;
                    $rows[] = [$m->id, $m->mailbox_id, $m->folder ?? '—', '—', '—', 'imap err', mb_strimwidth((string) $m->subject, 0, 30, '…')];
                }
            } finally {
                $client?->disconnect();
            }
        }

        $this->table(
            ['id', 'mbox', 'db_folder', 'inbox?', 'target?', 'verdict', 'subject'],
            $rows,
        );
        $this->newLine();
        $this->info('Сводка:');
        $this->table(
            ['verdict', 'count'],
            collect($stats)->filter(fn ($v) => $v > 0)->map(fn ($v, $k) => [$k, (string) $v])->values()->all(),
        );

        return self::SUCCESS;
    }

    /**
     * SEARCH HEADER Message-ID в указанной папке. Возвращает true если
     * хотя бы один matching UID есть.
     *
     * 2026-05-22: Yandex IMAP SEARCH HEADER Message-ID ВСЕГДА возвращает
     * пустой результат для нашего корпуса (проверено `verify-physical
     * --latest=20` — 20/20 nowhere несмотря на успешные `MailFolderRouter:
     * moved` в логе). Это известный quirk Yandex 360 — search по header
     * Message-ID не индексируется. UID FETCH работает корректно.
     */
    private function existsInFolder(\Webklex\PHPIMAP\Client $client, string $folderPath, string $messageId): bool
    {
        try {
            $folder = $client->getFolderByPath($folderPath, soft_fail: true);
            if (! $folder) {
                return false;
            }
            $client->openFolder($folder->path);
            $resp = $client->getConnection()->search(
                ['HEADER', 'Message-ID', $messageId],
                'UTF-8',
            );
            $data = $resp->validatedData();
            // webklex возвращает array с UIDs или nested array.
            if (! is_array($data)) {
                return false;
            }
            $flat = array_values(array_filter(array_merge(...array_map(
                fn ($v) => is_array($v) ? $v : [$v],
                $data,
            )), fn ($v) => is_numeric($v)));
            return ! empty($flat);
        } catch (\Throwable $e) {
            Log::info('mail:verify-physical: search failed', [
                'folder' => $folderPath,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Проверка по UID — надёжная альтернатива SEARCH HEADER. Если UID
     * существует в указанной папке, FETCH вернёт сообщение. Это работает
     * на Yandex 360 (в отличие от SEARCH HEADER Message-ID).
     */
    private function uidExistsInFolder(\Webklex\PHPIMAP\Client $client, string $folderPath, int $uid): bool
    {
        if ($uid <= 0) {
            return false;
        }
        try {
            $folder = $client->getFolderByPath($folderPath, soft_fail: true);
            if (! $folder) {
                return false;
            }
            $client->openFolder($folder->path);
            $msg = $folder->query()
                ->setFetchOptions(\Webklex\PHPIMAP\IMAP::FT_PEEK)
                ->setFetchBody(false)
                ->setFetchFlags(false)
                ->whereUid($uid)
                ->get()
                ->first();
            return $msg !== null;
        } catch (\Throwable $e) {
            Log::info('mail:verify-physical: UID FETCH failed', [
                'folder' => $folderPath,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
