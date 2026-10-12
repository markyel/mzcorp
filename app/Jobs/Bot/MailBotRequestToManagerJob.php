<?php

namespace App\Jobs\Bot;

use App\Enums\MailboxType;
use App\Enums\MailDirection;
use App\Mail\BotRequestForManagerMail;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Request;
use App\Models\User;
use App\Services\Mail\SystemNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Письмо менеджеру о заявке из бота при назначении (AssignmentService,
 * ReassignService) — вместо копии письма клиента, которой у такой заявки нет.
 * Одному менеджеру по одной заявке — один раз.
 */
class MailBotRequestToManagerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $requestId, public readonly int $managerId)
    {
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SystemNotificationMailer $mailer): void
    {
        $request = Request::query()->with(['items', 'organization:id,name'])->find($this->requestId);
        $manager = User::find($this->managerId);
        if ($request === null || $manager === null || trim((string) $manager->email) === ''
            || (int) $request->assigned_user_id !== (int) $manager->id) {
            return;
        }
        $key = 'bot:request-mail:'.$request->id.':'.$manager->id;
        if (Cache::has($key)) {
            return;
        }

        $mail = new BotRequestForManagerMail($request);
        $messageId = 'botreq-'.$request->id.'-'.$manager->id.'-'.now()->timestamp.'@mzcorp.ru';
        if (! $mailer->sendMailable((string) $manager->email, $mail, null, $messageId)) {
            Log::warning('MailBotRequestToManagerJob: not sent', ['request_id' => $request->id, 'manager_id' => $manager->id]);
            throw new \RuntimeException('bot request mail to manager not sent');
        }
        Cache::put($key, 1, now()->addDays(30));

        $this->localCopy($manager, $mail, $messageId);
    }

    /**
     * Копия письма во «Входящих» менеджера в почте mzCorp — сразу, не дожидаясь
     * Яндекса: 12.10.2026 хранилище Яндекса отвечало «Failed to store message»,
     * и отправленное уведомление в ящик менеджера не попадало. Когда Яндекс
     * доставит письмо, sync найдёт эту строку по (ящик, INBOX, Message-ID) и
     * только проставит imap_uid — дубля не будет (как в MailDeliverToManagerService).
     */
    private function localCopy(User $manager, BotRequestForManagerMail $mail, string $messageId): void
    {
        $mailbox = Mailbox::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $manager->email))])
            ->where('type', MailboxType::Personal)
            ->first();
        if ($mailbox === null) {
            return;
        }

        try {
            $html = $mail->render();
            EmailMessage::query()->firstOrCreate(
                ['mailbox_id' => $mailbox->id, 'folder' => 'INBOX', 'message_id' => $messageId],
                [
                    'direction' => MailDirection::Inbound->value,
                    'subject' => $mail->envelope()->subject,
                    'from_email' => (string) config('services.mail_outbound.shared_email', 'mail@myzip.ru'),
                    'from_name' => 'MyLift CRM',
                    'to_recipients' => [['name' => $manager->name, 'email' => (string) $manager->email]],
                    'sent_at' => now(),
                    'body_html' => $html,
                    'body_plain' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</li>', '</tr>'], "\n", $html)))),
                    'headers' => ['x_mylift_system_notification' => '1'],
                    'category' => 'irrelevant',
                    'categorized_at' => now(),
                    'detected_artifacts' => ['server_copy_pending' => true, 'bot_request_notice' => true],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('MailBotRequestToManagerJob: local copy failed', ['manager_id' => $manager->id, 'error' => $e->getMessage()]);
        }
    }
}
