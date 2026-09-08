<?php

namespace App\Console\Commands;

use App\Jobs\Mail\SyncMailboxFolderJob;
use App\Models\Mailbox;
use Illuminate\Console\Command;

/**
 * Запускает синхронизацию активных ящиков (Inbox + Sent).
 *
 * Применение:
 *   php artisan mail:sync                   — все активные ящики
 *   php artisan mail:sync --mailbox=3       — только конкретный
 *   php artisan mail:sync --sync            — синхронно (без очереди), для отладки
 *   php artisan mail:sync --folder=inbox    — только Inbox / только Sent
 *
 * В scheduler регистрируется без флагов — диспатчит jobs в очередь.
 */
class MailSyncCommand extends Command
{
    protected $signature = 'mail:sync
        {--mailbox= : Sync only this mailbox id}
        {--folder= : Sync only this folder type (inbox|sent)}
        {--sync : Run synchronously instead of dispatching to queue}';

    protected $description = 'Синхронизация почтовых ящиков (Inbox + Sent через IMAP)';

    public function handle(): int
    {
        // syncable() = is_active + (general OR personal с managerial-ролью owner).
        // Личные ящики директора/секретаря/админа исключаются автоматически,
        // даже если авторизация валидна. При смене роли владельца на
        // менеджера/РОПа фильтр тут же подхватит ящик.
        $query = Mailbox::query()->syncable();

        if ($id = $this->option('mailbox')) {
            // Явный --mailbox=ID обходит фильтр (для ручной отладки/diagnose).
            $query = Mailbox::query()->where('id', $id);
        }

        $mailboxes = $query->get();

        if ($mailboxes->isEmpty()) {
            $this->warn('No active mailboxes found.');

            return self::SUCCESS;
        }

        $folderTypes = $this->resolveFolderTypes();

        $count = 0;
        foreach ($mailboxes as $mailbox) {
            foreach ($folderTypes as $folderType) {
                $job = new SyncMailboxFolderJob($mailbox->id, $folderType);

                if ($this->option('sync')) {
                    $this->info("→ Sync inline: mailbox={$mailbox->email} folder={$folderType}");
                    dispatch_sync($job);
                } else {
                    dispatch($job);
                    $this->line("→ Dispatched: mailbox={$mailbox->email} folder={$folderType}");
                }
                $count++;
            }

            // Прочитанность владельца личного ящика ↔ \Seen на сервере
            // (ImapSeenSyncService::pullSeen). Только личные ящики с владельцем.
            if ($mailbox->type === \App\Enums\MailboxType::Personal && $mailbox->owner_user_id
                && in_array('inbox', $folderTypes, true)) {
                // Папки/расположение писем (ImapFolderSyncService) — не чаще раза
                // в folder_sync_interval_minutes: pull по папке на 100k писем идёт
                // ~10 с, и каждые 2 минуты по всем ящикам он забивал mail-sync так,
                // что очередь default (распределение, доставка менеджеру) стояла
                // (инцидент 2026-09-08 14:36–16:10).
                $interval = max(2, (int) config('services.mail.folder_sync_interval_minutes', 10));
                if ($this->option('sync') || \Illuminate\Support\Facades\Cache::add('folder-sync-throttle:' . $mailbox->id, 1, now()->addMinutes($interval))) {
                    $foldersJob = new \App\Jobs\Mail\SyncImapFoldersJob($mailbox->id);
                    $this->option('sync') ? dispatch_sync($foldersJob) : dispatch($foldersJob);
                    $count++;
                }
                // Флаги \Seen — дёшево (FETCH FLAGS по окну 14 дней), каждый цикл.
                $seenJob = new \App\Jobs\Mail\PullImapSeenFlagsJob($mailbox->id);
                $this->option('sync') ? dispatch_sync($seenJob) : dispatch($seenJob);
                $count++;
            }
        }

        $this->info("Total jobs scheduled: {$count}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function resolveFolderTypes(): array
    {
        $folder = $this->option('folder');

        if ($folder === null) {
            return ['inbox', 'sent'];
        }

        if (! in_array($folder, ['inbox', 'sent'], true)) {
            $this->error('--folder must be "inbox" or "sent"');
            exit(self::INVALID);
        }

        return [$folder];
    }
}
