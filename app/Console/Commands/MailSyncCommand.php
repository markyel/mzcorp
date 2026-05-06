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
        $query = Mailbox::query()->where('is_active', true);

        if ($id = $this->option('mailbox')) {
            $query->where('id', $id);
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
