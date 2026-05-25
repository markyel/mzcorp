<?php

namespace App\Jobs\Mail;

use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Mail\MailReassignArchiverService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async UID MOVE копии письма из INBOX личного ящика бывшего менеджера
 * в подпапку `MZ/Reassigned` + STORE `\Seen`.
 *
 * Триггерится из `ReassignService::reassign` при смене assigned_user_id.
 *
 * Идемпотентность реализована в `MailReassignArchiverService` через
 * `email_messages.detected_artifacts.inbox_deliveries[i].archived_at`.
 *
 * Backoff [60, 300, 900] — типичные Yandex IMAP-flake'и проходят со
 * 2-3 ретрая. Если копия ещё не подобрана sync'ом (imap_uid=null) —
 * сервис вернёт false без throw; в этом случае job НЕ ретраится
 * (это не транзиентная ошибка). Backfill-CLI добивает оставшиеся.
 */
class ArchiveFromOldManagerInboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    /** @return int[] */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly int $emailMessageId,
        public readonly int $oldManagerId,
    ) {
    }

    public function handle(MailReassignArchiverService $service): void
    {
        $message = EmailMessage::find($this->emailMessageId);
        if (! $message) {
            return;
        }
        $oldManager = User::find($this->oldManagerId);
        if (! $oldManager) {
            return;
        }

        $service->archive($message, $oldManager);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ArchiveFromOldManagerInboxJob: final failure', [
            'email_message_id' => $this->emailMessageId,
            'old_manager_id' => $this->oldManagerId,
            'error' => $e->getMessage(),
        ]);
    }
}
