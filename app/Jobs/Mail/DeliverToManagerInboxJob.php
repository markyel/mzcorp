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
class DeliverToManagerInboxJob implements ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    /**
     * Дедуп в очереди: шесть мест диспатча (роутер, персистер, парсер,
     * переподчинение, реанимация) могли поставить одно и то же письмо одному
     * менеджеру дважды → два APPEND, дубль в ящике. Лок снимается при старте
     * обработки, поэтому retry/backoff не блокируются.
     */
    public function uniqueId(): string
    {
        return sprintf('deliver:%d:%d', $this->emailMessageId, $this->managerId);
    }

    public function uniqueFor(): int
    {
        return 10 * 60;
    }

    /**
     * Доставка в личный ящик, затем перенос в подпапку MZ|<Фамилия> общего.
     * Порядок обязателен: Route делает UID MOVE и инвалидирует UID, по которому
     * Deliver re-fetch'ит RFC822. Раньше держался на FIFO очереди (не строгом).
     * Если Deliver исчерпал retry, Route всё равно запускается из catch —
     * секретарь должен видеть распределение независимо от личного ящика.
     */
    public static function chainWithRouting(int $emailMessageId, int $managerId): void
    {
        \Illuminate\Support\Facades\Bus::chain([
            new self($emailMessageId, $managerId),
            new RouteMailToManagerJob($emailMessageId, $managerId),
        ])->catch(function (\Throwable $e) use ($emailMessageId, $managerId) {
            Log::warning('DeliverToManagerInboxJob chain failed — dispatching routing anyway', [
                'email_message_id' => $emailMessageId,
                'manager_id' => $managerId,
                'error' => $e->getMessage(),
            ]);
            RouteMailToManagerJob::dispatch($emailMessageId, $managerId);
        })->dispatch();
    }
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
