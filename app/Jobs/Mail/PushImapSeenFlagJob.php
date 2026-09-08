<?php

namespace App\Jobs\Mail;

use App\Models\Mailbox;
use App\Services\Mail\ImapSeenSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Поставить/снять \Seen на IMAP-сервере для писем владельца личного ящика
 * (владелец прочитал / пометил непрочитанным в mzCorp). См. ImapSeenSyncService.
 */
class PushImapSeenFlagJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int,int> */
    public array $backoff = [10, 60, 300];

    /**
     * @param  list<int>  $uids
     */
    public function __construct(
        public readonly int $mailboxId,
        public readonly string $folderPath,
        public readonly array $uids,
        public readonly bool $seen,
    ) {
        $this->onQueue('mail-sync');
    }

    public function handle(ImapSeenSyncService $svc): void
    {
        $mailbox = Mailbox::query()->find($this->mailboxId);
        if (! $mailbox || ! $mailbox->is_active) {
            return;
        }
        try {
            $svc->storeSeen($mailbox, $this->folderPath, $this->uids, $this->seen);
        } catch (\Throwable $e) {
            Log::warning('PushImapSeenFlagJob: STORE \Seen failed', [
                'mailbox_id' => $this->mailboxId,
                'folder' => $this->folderPath,
                'uids' => count($this->uids),
                'seen' => $this->seen,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
