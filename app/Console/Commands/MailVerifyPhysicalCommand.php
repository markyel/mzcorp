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
            $q->where(function ($w) {
                $w->where('folder', 'like', 'MZ|%')
                  ->orWhere('folder', 'like', 'MZ/%');
            })->orderByDesc('id')->limit($latest);
        }
        if ($mailboxId) {
            $q->where('mailbox_id', $mailboxId);
        } else {
            // Личные ящики менеджеров не имеют MZ/* подпапок — verify
            // не имеет смысла. Чтобы не ловить false-nowhere — фильтруем.
            $personalMailboxIds = \App\Models\Mailbox::query()
                ->where('type', \App\Enums\MailboxType::Personal->value)
                ->pluck('id')
                ->all();
            if (! empty($personalMailboxIds)) {
                $q->whereNotIn('mailbox_id', $personalMailboxIds);
            }
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
                    $rfcId = trim((string) $m->message_id);
                    if ($rfcId === '') {
                        $rows[] = [$m->id, $m->mailbox_id, $m->folder ?? '—', '—', '—', 'no message_id', mb_strimwidth((string) $m->subject, 0, 30, '…')];
                        $stats['error']++;
                        continue;
                    }

                    $inInbox = $this->existsInFolder($client, 'INBOX', $rfcId);
                    $inTarget = ($m->folder !== null && $m->folder !== 'INBOX')
                        ? $this->existsInFolder($client, (string) $m->folder, $rfcId)
                        : $inInbox; // если БД говорит INBOX — target == inbox

                    $verdict = match (true) {
                        $inTarget && $inInbox && $m->folder !== 'INBOX' => 'in_both',
                        $inTarget && ! $inInbox && $m->folder !== 'INBOX' => 'only_in_target',
                        ! $inTarget && $inInbox => 'only_in_inbox',
                        ! $inTarget && ! $inInbox => 'nowhere',
                        default => 'unknown',
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
}
