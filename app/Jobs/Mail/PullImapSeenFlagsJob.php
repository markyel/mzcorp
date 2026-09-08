<?php

namespace App\Jobs\Mail;

use App\Models\Mailbox;
use App\Services\Mail\ImapSeenSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Подтянуть \Seen с сервера для свежих писем INBOX личного ящика → прочитанность
 * владельца в mzCorp. Запускается из mail:sync вместе с синком папок.
 */
class PullImapSeenFlagsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $mailboxId)
    {
        $this->onQueue('mail-sync');
    }

    public function uniqueId(): string
    {
        return 'seen-pull:' . $this->mailboxId;
    }

    public function uniqueFor(): int
    {
        return 5 * 60;
    }

    public function handle(ImapSeenSyncService $svc): void
    {
        $mailbox = Mailbox::query()->with('owner')->find($this->mailboxId);
        if (! $mailbox || ! $mailbox->is_active) {
            return;
        }
        try {
            $svc->pullSeen($mailbox);
        } catch (\Throwable $e) {
            // Non-fatal: следующий цикл mail:sync повторит.
            Log::warning('PullImapSeenFlagsJob: failed (non-fatal)', [
                'mailbox_id' => $this->mailboxId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
