<?php

namespace App\Jobs\Mail;

use App\Models\Mailbox;
use App\Services\Mail\ImapFolderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pull-синхронизация пользовательских папок и расположения писем с IMAP
 * (личные ящики с владельцем). Запускается из mail:sync. См. ImapFolderSyncService.
 */
class SyncImapFoldersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $mailboxId)
    {
        $this->onQueue('mail-sync');
    }

    public function uniqueId(): string
    {
        return 'folder-sync:' . $this->mailboxId;
    }

    public function uniqueFor(): int
    {
        return 5 * 60;
    }

    public function handle(ImapFolderSyncService $svc): void
    {
        $mailbox = Mailbox::query()->find($this->mailboxId);
        if (! $mailbox || ! $mailbox->is_active) {
            return;
        }
        try {
            $svc->pull($mailbox);
        } catch (\Throwable $e) {
            // Non-fatal: следующий цикл mail:sync повторит.
            Log::warning('SyncImapFoldersJob: failed (non-fatal)', [
                'mailbox_id' => $this->mailboxId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
