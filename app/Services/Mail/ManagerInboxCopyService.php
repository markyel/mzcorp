<?php

namespace App\Services\Mail;

use App\Enums\MailboxType;
use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;

/**
 * Копия служебного письма менеджеру во «Входящих» его почты в mzCorp — сразу,
 * не дожидаясь Яндекса: 12.10.2026 хранилище Яндекса отвечало «Failed to store
 * message», и отправленное уведомление в ящик менеджера не попадало. Когда
 * Яндекс доставит письмо, sync найдёт эту строку по (ящик, INBOX, Message-ID)
 * и только проставит imap_uid — дубля не будет (как в MailDeliverToManagerService).
 * Копия видна сигналу новой почты — тост и уведомление ОС придут сами.
 */
class ManagerInboxCopyService
{
    /** @param  array<string, mixed>  $artifacts  пометки письма (detected_artifacts) */
    public function store(User $manager, Mailable $mail, string $messageId, array $artifacts = []): void
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
                    'detected_artifacts' => ['server_copy_pending' => true] + $artifacts,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('ManagerInboxCopyService: local copy failed', ['manager_id' => $manager->id, 'error' => $e->getMessage()]);
        }
    }
}
