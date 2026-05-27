<?php

namespace App\Services\Mail;

use App\Enums\MailboxType;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\IMAP;

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

        // Письмо лежит в личном ящике ДРУГОГО менеджера — не дублируем.
        //
        // Foundation §1.5: личный ящик принадлежит конкретному менеджеру. Если
        // клиент написал лично менеджеру A, а linker привязал письмо к чужой
        // Request (assigned=B), мы НЕ APPEND'им копию в личный inbox B.
        //
        // Контекст:
        //  - Менеджер A видит письмо в своём Yandex (как и положено — клиент
        //    обратился к нему лично).
        //  - Менеджер B видит письмо в треде заявки в MyLift UI — Detail::mount
        //    собирает все email_messages по related_request_id независимо от
        //    исходного ящика.
        //  - В обратную сторону (shared → personal B) — APPEND продолжает
        //    работать, это исходный кейс M-2026-1928 / коммит 19e2957.
        //
        // Без этого guard'а коммит 19e2957 множил дубли: reply клиента, попавший
        // в личный ящик A (например, через Outlook autocomplete или
        // переподчинение sales-команды), копировался в личный inbox B как
        // «доставка assigned-менеджеру», и оба менеджера видели одно и то же
        // письмо в своих ящиках. См. MEMORY «Большая волна 2026-05-27».
        $originMailbox = $message->mailbox;
        if ($originMailbox
            && $originMailbox->type === MailboxType::Personal
            && $originMailbox->owner_user_id
            && (int) $originMailbox->owner_user_id !== (int) $manager->id) {
            Log::info('MailDeliverToManagerService: skip — origin in another personal mailbox', [
                'email_message_id' => $message->id,
                'origin_mailbox_id' => $originMailbox->id,
                'origin_owner_user_id' => $originMailbox->owner_user_id,
                'target_manager_id' => $manager->id,
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

        // Re-fetch ПОЛНЫЙ RFC822 (headers + body) из source-ящика. Наш
        // `raw_source` хранит только body через `Message::getRawBody()` —
        // если APPEND'ить body без top-level headers, Yandex не знает что
        // это multipart и показывает MIME boundary как plain-text. Кейс
        // LZ-REQ-1315: «Без отправителя/темы» + attachment как base64-простыня.
        $raw = $this->fetchFullRfc822($message);
        if ($raw === '') {
            Log::warning('MailDeliverToManagerService: cannot reconstruct RFC822, skip APPEND', [
                'email_message_id' => $message->id,
                'manager_id' => $manager->id,
                'has_raw_source' => $message->raw_source !== null && $message->raw_source !== '',
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

        // Pre-create запись в email_messages — чтобы будущий IMAP-sync
        // личного ящика НЕ создавал новый row через MessagePersister
        // (он найдёт existing по uniq (mailbox_id, folder, message_id)).
        // MailRouter таким образом не вызывается, gpt-4o categorize не
        // дёргается, дубля Request не возникает. Sync ТОЛЬКО заполнит
        // imap_uid + imap_flags + raw_source у этой записи.
        if ($message->message_id) {
            $alreadyPreCreated = \App\Models\EmailMessage::query()
                ->where('mailbox_id', $managerMailbox->id)
                ->where('folder', 'INBOX')
                ->where('message_id', $message->message_id)
                ->exists();
            if (! $alreadyPreCreated) {
                \App\Models\EmailMessage::create([
                    'mailbox_id' => $managerMailbox->id,
                    'folder' => 'INBOX',
                    'direction' => \App\Enums\MailDirection::Inbound->value,
                    'imap_uid' => null,
                    'message_id' => $message->message_id,
                    'in_reply_to' => $message->in_reply_to,
                    'references_header' => $message->references_header,
                    'subject' => $message->subject,
                    'from_email' => $message->from_email,
                    'from_name' => $message->from_name,
                    'to_recipients' => $message->to_recipients,
                    'cc_recipients' => $message->cc_recipients,
                    'sent_at' => $message->sent_at,
                    'body_plain' => $message->body_plain,
                    'body_html' => $message->body_html,
                    'raw_source' => null,
                    'headers' => $message->headers,
                    'related_request_id' => $message->related_request_id,
                    'category' => $message->category,
                    'category_confidence' => $message->category_confidence,
                    'category_intent' => $message->category_intent,
                    'category_reasoning' => 'Cross-mailbox copy of msg#' . $message->id,
                    'categorized_at' => $message->categorized_at ?: now(),
                    'detected_artifacts' => [
                        'cross_mailbox_copy_of' => $message->id,
                    ],
                ]);
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

    /**
     * Подтянуть полный RFC822-исходник письма из source-ящика по UID.
     *
     * Webklex `Message::getRawBody()` возвращает только тело, а нам для APPEND
     * нужно `headers . CRLF CRLF . body`. Идём в source-ящик, FETCH'им письмо
     * по UID, склеиваем `$msg->getHeader()->raw` + `$msg->getRawBody()`.
     *
     * FT_PEEK — без \Seen побочного эффекта.
     */
    private function fetchFullRfc822(EmailMessage $message): string
    {
        $sourceMailbox = $message->mailbox;
        if (! $sourceMailbox || ! $message->imap_uid || ! $message->folder) {
            return '';
        }

        $client = null;
        try {
            $client = $this->connector->imapClient($sourceMailbox);
            $folder = $client->getFolderByPath((string) $message->folder, soft_fail: true);
            if (! $folder) {
                return '';
            }
            $msgs = $folder->query()
                ->setFetchOptions(IMAP::FT_PEEK)
                ->setFetchBody(true)
                ->setFetchFlags(false)
                ->whereUid($message->imap_uid)
                ->get();
            $msg = $msgs->first();
            if (! $msg) {
                return '';
            }

            $headerRaw = (string) ($msg->getHeader()?->raw ?? '');
            $bodyRaw = (string) $msg->getRawBody();

            if ($headerRaw === '') {
                return '';
            }

            // RFC822 разделитель — пустая строка между headers и body.
            // Headers webklex обычно отдаёт с CRLF; нормализуем хвост.
            $headerRaw = rtrim($headerRaw, "\r\n") . "\r\n\r\n";

            return $headerRaw . $bodyRaw;
        } catch (\Throwable $e) {
            Log::warning('MailDeliverToManagerService: re-fetch failed', [
                'email_message_id' => $message->id,
                'source_mailbox_id' => $sourceMailbox->id,
                'imap_uid' => $message->imap_uid,
                'error' => $e->getMessage(),
            ]);

            return '';
        } finally {
            try {
                $client?->disconnect();
            } catch (\Throwable) {
                // ignore
            }
        }
    }
}
