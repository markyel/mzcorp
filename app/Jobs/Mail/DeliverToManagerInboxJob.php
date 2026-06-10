<?php

namespace App\Jobs\Mail;

use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Mail\MailDeliverToManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async IMAP APPEND письма в личный ящик assigned-менеджера.
 *
 * Триггерится из:
 *   - AssignmentService::autoAssign (после первого назначения);
 *   - ReassignService::reassign (после ручного переподчинения).
 *
 * Идемпотентность реализована в `MailDeliverToManagerService` через
 * `email_messages.detected_artifacts.inbox_deliveries[]`. Повторный
 * dispatch на того же user_id — no-op.
 */
class DeliverToManagerInboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;
    public int $timeout = 60;

    /**
     * Первый ретрай через 30с — основной кейс empty_raw_rfc822 это гонка с
     * IMAP-sync (imap_uid источника ещё не проставлен); к +30с он обычно уже
     * есть. Дальше +2м/+5м на случай Yandex-flake. Финальная страховка —
     * 30-мин backfill-крон (mail:backfill-manager-deliveries).
     *
     * @return int[]
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public readonly int $emailMessageId,
        public readonly int $managerId,
    ) {
    }

    public function handle(MailDeliverToManagerService $service): void
    {
        $message = EmailMessage::find($this->emailMessageId);
        if (! $message) {
            return;
        }
        $manager = User::find($this->managerId);
        if (! $manager) {
            return;
        }

        $service->deliver($message, $manager);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('DeliverToManagerInboxJob: final failure', [
            'email_message_id' => $this->emailMessageId,
            'manager_id' => $this->managerId,
            'error' => $e->getMessage(),
        ]);
    }
}
