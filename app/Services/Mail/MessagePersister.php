<?php

namespace App\Services\Mail;

use App\Enums\MailDirection;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Message;

/**
 * Сохраняет распарсенные webklex Message в наш EmailMessage + EmailAttachment.
 *
 * Идемпотентность по Foundation §1: уникальный ключ
 * (mailbox_id, folder, message_id). Один и тот же физический Message-ID может
 * лежать в Inbox получателя и в Sent отправителя — это разные записи.
 */
class MessagePersister
{
    public function __construct(private readonly string $attachmentDisk = 'local')
    {
    }

    /**
     * @return EmailMessage|null  null = дубликат (пропущен), иначе — сохранённая запись.
     */
    public function persist(Message $msg, Mailbox $mailbox, string $folder, MailDirection $direction): ?EmailMessage
    {
        $messageId = $this->extractMessageId($msg);

        if ($messageId === null) {
            // Без Message-ID полноценная дедупликация невозможна —
            // ставим suffix по UID, чтобы не пропускать письмо.
            $messageId = sprintf('uid-%d-%s', $msg->getUid(), Str::random(16));
        }

        return DB::transaction(function () use ($msg, $mailbox, $folder, $direction, $messageId) {
            $existing = EmailMessage::where('mailbox_id', $mailbox->id)
                ->where('folder', $folder)
                ->where('message_id', $messageId)
                ->first();

            if ($existing) {
                return null;
            }

            $email = EmailMessage::create([
                'mailbox_id' => $mailbox->id,
                'folder' => $folder,
                'direction' => $direction->value,
                'imap_uid' => $msg->getUid(),
                'message_id' => $messageId,
                'in_reply_to' => $this->extractHeader($msg, 'in_reply_to'),
                'references_header' => $this->extractReferences($msg),
                'subject' => $this->truncate((string) $msg->getSubject(), 998),
                'from_email' => $this->extractFromEmail($msg),
                'from_name' => $this->extractFromName($msg),
                'to_recipients' => $this->extractAddressList($msg, 'to'),
                'cc_recipients' => $this->extractAddressList($msg, 'cc'),
                'sent_at' => $msg->getDate()?->toDate(),
                'body_plain' => (string) $msg->getTextBody(),
                'body_html' => (string) $msg->getHTMLBody(),
                'raw_source' => $msg->getRawBody(),
                'headers' => $this->extractAllHeaders($msg),
                'imap_flags' => $msg->getFlags()->toArray(),
            ]);

            foreach ($msg->getAttachments() as $att) {
                $this->persistAttachment($att, $email);
            }

            return $email;
        });
    }

    private function persistAttachment(Attachment $att, EmailMessage $email): void
    {
        $filename = $att->getName() ?? ('attachment-' . Str::random(8));
        $relativePath = sprintf(
            'mail/%d/%s/%s',
            $email->mailbox_id,
            $email->id,
            Str::random(8) . '_' . $this->safeFilename($filename),
        );

        Storage::disk($this->attachmentDisk)->put($relativePath, $att->getContent());

        EmailAttachment::create([
            'email_message_id' => $email->id,
            'filename' => $filename,
            'mime_type' => $att->getMimeType(),
            'size_bytes' => $att->getSize(),
            'content_id' => $att->getContentId(),
            'file_path' => $relativePath,
            'disk' => $this->attachmentDisk,
            'is_inline' => $att->getDisposition() === 'inline',
        ]);
    }

    private function extractMessageId(Message $msg): ?string
    {
        $raw = (string) $msg->getMessageId();
        $raw = trim($raw, " \t\n\r\0\x0B<>");

        return $raw !== '' ? $raw : null;
    }

    private function extractHeader(Message $msg, string $name): ?string
    {
        $value = $msg->getHeader()->get($name);
        if ($value === null || $value === '') {
            return null;
        }

        return is_object($value) && method_exists($value, '__toString')
            ? (string) $value
            : (is_scalar($value) ? (string) $value : null);
    }

    /**
     * @return array<int, string>|null
     */
    private function extractReferences(Message $msg): ?array
    {
        $raw = $this->extractHeader($msg, 'references');
        if ($raw === null) {
            return null;
        }

        // References — это разделённый пробелами список <message-ids>.
        $ids = [];
        foreach (preg_split('/\s+/', $raw) as $id) {
            $id = trim($id, "<> \t\r\n");
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids ?: null;
    }

    private function extractFromEmail(Message $msg): string
    {
        $from = $msg->getFrom()->first();

        return $from->mail ?? '';
    }

    private function extractFromName(Message $msg): ?string
    {
        $from = $msg->getFrom()->first();

        return $from->personal ?? null;
    }

    /**
     * @return array<int, array{email: string, name: ?string}>
     */
    private function extractAddressList(Message $msg, string $field): array
    {
        $list = match ($field) {
            'to' => $msg->getTo(),
            'cc' => $msg->getCc(),
            default => null,
        };

        if (! $list) {
            return [];
        }

        return $list->map(fn ($addr) => [
            'email' => $addr->mail ?? '',
            'name' => $addr->personal ?? null,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function extractAllHeaders(Message $msg): array
    {
        $attrs = $msg->getHeader()->getAttributes();

        // Приводим объекты Carbon/Address к скаляру/массиву для jsonb.
        return collect($attrs)->map(function ($value) {
            if (is_object($value) && method_exists($value, '__toString')) {
                return (string) $value;
            }
            if (is_array($value) || is_scalar($value) || $value === null) {
                return $value;
            }

            return null;
        })->all();
    }

    private function truncate(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._\-]/', '_', $name) ?? 'file';

        return mb_substr($name, 0, 80);
    }
}
