<?php

namespace App\Jobs\Mail;

use App\Models\Mailbox;
use App\Models\MailboxFolder;
use App\Services\Mail\ImapFolderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Push-операция с папками на IMAP-сервере по действию пользователя в mzCorp:
 *  create — {folder_id}
 *  rename — {old_path, new_path}
 *  delete — {path, child_renames: [[old,new]...], uids: [...]}
 *  move   — {from_path, uids: [...], to_path, mailbox_folder_id: ?int}
 * См. ImapFolderSyncService.
 */
class PushImapFolderOpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var array<int,int> */
    public array $backoff = [10, 60, 300];

    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly int $mailboxId,
        public readonly string $op,
        public readonly array $payload,
    ) {
        $this->onQueue('mail-sync');
    }

    public function handle(ImapFolderSyncService $svc): void
    {
        $mailbox = Mailbox::query()->find($this->mailboxId);
        if (! $mailbox || ! $svc->isServerSynced($mailbox)) {
            return;
        }
        try {
            match ($this->op) {
                'create' => $this->create($svc, $mailbox),
                'rename' => $svc->renameOnServer($mailbox, (string) $this->payload['old_path'], (string) $this->payload['new_path']),
                'delete' => $svc->deleteOnServer(
                    $mailbox,
                    (string) $this->payload['path'],
                    (array) ($this->payload['child_renames'] ?? []),
                    (array) ($this->payload['uids'] ?? []),
                ),
                'move' => $svc->moveOnServer(
                    $mailbox,
                    (string) $this->payload['from_path'],
                    (array) ($this->payload['uids'] ?? []),
                    (string) $this->payload['to_path'],
                    isset($this->payload['mailbox_folder_id']) ? (int) $this->payload['mailbox_folder_id'] : null,
                ),
                default => throw new \InvalidArgumentException("Unknown op {$this->op}"),
            };
        } catch (\Throwable $e) {
            Log::warning('PushImapFolderOpJob: failed', [
                'mailbox_id' => $this->mailboxId,
                'op' => $this->op,
                'payload' => $this->payload,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function create(ImapFolderSyncService $svc, Mailbox $mailbox): void
    {
        $folder = MailboxFolder::query()->where('mailbox_id', $mailbox->id)->find((int) ($this->payload['folder_id'] ?? 0));
        if (! $folder) {
            return; // уже удалена
        }
        $svc->createOnServer($mailbox, $folder);
    }
}
