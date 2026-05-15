<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Доставка оригинала письма в личный IMAP-ящик assigned-менеджера.
 *
 * Через IMAP APPEND — копия .eml кладётся в INBOX личного ящика менеджера
 * (`man1@myzip.ru` и т.п.). Headers (From/To/Reply-To/Date) остаются
 * оригинальными, в Yandex web UI менеджер видит письмо «как будто пришло
 * напрямую». Reply из его ящика идёт клиенту естественно.
 *
 * Отличается от `MailFolderRouter::routeToManager` тем, что:
 *  - MailFolderRouter копирует в подпапку `MZ|<Фамилия>` ОБЩЕГО ящика
 *    (mail@myzip.ru) — для секретаря, чтобы видеть распределение.
 *  - MailDeliverToManager APPEND'ит в INBOX ЛИЧНОГО ящика менеджера.
 *
 * Оба механизма работают параллельно: общий ящик показывает «карту»
 * распределения секретарю, личный — рабочий поток менеджеру.
 *
 * Идемпотентность через `email_messages.detected_artifacts.inbox_deliveries[]`
 * — массив `{user_id, mailbox_id, delivered_at}`. Перед APPEND проверяем,
 * не доставляли ли уже этому пользователю.
 *
 * Skip-кейсы:
 *   - письмо уже в ящике менеджера (`message.mailbox_id == manager_mailbox.id`);
 *   - у менеджера нет личного Mailbox с тем же email (нет OAuth);
 *   - `raw_source` пуст (письмо без оригинального .eml — старые
 *     импортированные);
 *   - уже доставляли этому user_id (idempotent re-dispatch).
 */
class MailDeliverToManagerService
{
    public function __construct(private readonly MailboxConnector $connector)
    {
    }

    public function deliver(EmailMessage $message, User $manager): bool
    {
        $managerEmail = mb_strtolower(trim((string) $manager->email));
        if ($managerEmail === '') {
            Log::info('MailDeliverToManagerService: manager email empty, skip', [
                'email_message_id' => $message->id,
                'manager_id' => $manager->id,
            ]);

            return false;
        }

        $managerMailbox = Mailbox::query()
            ->whereRaw('LOWER(email) = ?', [$managerEmail])
            ->first();
        if (! $managerMailbox) {
            Log::info('MailDeliverToManagerService: no personal mailbox for manager, skip', [
                'email_message_id' => $message->id,
                'manager_id' => $manager->id,
                'manager_email' => $managerEmail,
            ]);

            return false;
        }

        // Письмо уже в ящике менеджера (клиент написал ему напрямую).
        if ((int) $message->mailbox_id === (int) $managerMailbox->id) {
            Log::info('MailDeliverToManagerService: already in manager mailbox, skip', [
                'email_message_id' => $message->id,
                'manager_id' => $manager->id,
                'mailbox_id' => $managerMailbox->id,
            ]);

            return false;
        }

        // Идемпотентность — уже APPEND'или этому user'у?
        $artifacts = (array) ($message->detected_artifacts ?? []);
        $deliveries = (array) ($artifacts['inbox_deliveries'] ?? []);
        foreach ($deliveries as $d) {
            if ((int) ($d['user_id'] ?? 0) === (int) $manager->id) {
                Log::info('MailDeliverToManagerService: already delivered, skip', [
                    'email_message_id' => $message->id,
                    'manager_id' => $manager->id,
                ]);

                return false;
            }
        }

        $raw = (string) $message->raw_source;
        if ($raw === '') {
            Log::warning('MailDeliverToManagerService: empty raw_source, skip APPEND', [
                'email_message_id' => $message->id,
                'manager_id' => $manager->id,
            ]);

            return false;
        }

        // IMAP APPEND в INBOX личного ящика менеджера.
        $client = $this->connector->imapClient($managerMailbox);
        try {
            $inbox = $client->getFolderByPath('INBOX', soft_fail: true);
            if (! $inbox) {
                throw new \RuntimeException("INBOX not found in mailbox {$managerMailbox->id}");
            }
            $internalDate = $message->sent_at ?: now();
            // Без \Seen — менеджер должен увидеть письмо как новое.
            $inbox->appendMessage($raw, [], $internalDate);
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
                // ignore
            }
        }

        // Audit: фиксируем доставку, чтобы повторный dispatch не задвоил.
        $deliveries[] = [
            'user_id' => $manager->id,
            'mailbox_id' => $managerMailbox->id,
            'delivered_at' => now()->toIso8601String(),
        ];
        $artifacts['inbox_deliveries'] = $deliveries;
        $message->forceFill(['detected_artifacts' => $artifacts])->save();

        Log::info('MailDeliverToManagerService: delivered', [
            'email_message_id' => $message->id,
            'manager_id' => $manager->id,
            'manager_mailbox_id' => $managerMailbox->id,
            'subject' => mb_substr((string) $message->subject, 0, 80),
        ]);

        return true;
    }
}
