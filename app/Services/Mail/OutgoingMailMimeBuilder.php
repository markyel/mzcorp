<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Mailbox;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Symfony\Component\Mime\Header\UnstructuredHeader;

/**
 * Сборка Symfony Email из EmailMessage-draft (Phase 1.9).
 *
 * - Заголовки In-Reply-To / References + наши кастомные.
 * - Message-ID генерируем явно, сохраняем в EmailMessage для дедупа при
 *   Sent-sync (см. MessagePersister обновление в Commit 4).
 * - Attachments читаются стримом из storage (не грузим в память целиком).
 */
class OutgoingMailMimeBuilder
{
    /**
     * Сгенерировать Message-ID, который и пойдёт в MIME, и сохранится в
     * email_messages.message_id (нужно вызвать ДО build()).
     */
    public function generateMessageId(): string
    {
        return Str::uuid()->toString() . '@mzcorp.ru';
    }

    public function build(EmailMessage $draft, Mailbox $fromMailbox): SymfonyEmail
    {
        $email = new SymfonyEmail();

        $fromName = $draft->from_name ?: $fromMailbox->name ?: '';
        $email->from(new Address($fromMailbox->email, $fromName));

        foreach ((array) ($draft->to_recipients ?? []) as $rcpt) {
            $email->addTo($this->toAddress($rcpt));
        }
        foreach ((array) ($draft->cc_recipients ?? []) as $rcpt) {
            $email->addCc($this->toAddress($rcpt));
        }

        $email->subject((string) ($draft->subject ?: ''));

        if ($draft->body_plain !== null && $draft->body_plain !== '') {
            $email->text($draft->body_plain);
        }
        if ($draft->body_html !== null && $draft->body_html !== '') {
            $email->html($draft->body_html);
        }

        // Threading headers (RFC 5322 §3.6.4).
        $headers = $email->getHeaders();
        if ($draft->in_reply_to) {
            $headers->addIdHeader('In-Reply-To', $draft->in_reply_to);
        }
        $refs = (array) ($draft->references_header ?? []);
        if ($refs !== []) {
            // Symfony addIdHeader умеет принимать массив.
            $headers->addIdHeader('References', $refs);
        }

        // Message-ID: используем уже сохранённый в draft (если он не draft.*
        // временный) либо генерим новый. Возвращаем итоговый через header,
        // sender проставит его в БД до отправки.
        $messageId = $draft->message_id;
        if (! $messageId || str_starts_with($messageId, 'draft.')) {
            $messageId = $this->generateMessageId();
        }
        $headers->addIdHeader('Message-ID', $messageId);

        // Анти-loop маркеры для нашего же Sent-sync (см. Commit 4 — дедуп
        // по этому заголовку перед созданием новой EmailMessage). НЕ
        // X-MyLift-Forwarded, чтобы MailRouter::isLoopMessage не дропнул.
        $headers->addTextHeader('X-MyLift-Reply', '1');
        $authorId = (string) ($draft->headers['X-MyLift-Author-User-Id'] ?? '');
        if ($authorId !== '') {
            $headers->addTextHeader('X-MyLift-Author-User-Id', $authorId);
        }

        // Attachments.
        foreach ($draft->attachments as $attachment) {
            $stream = Storage::disk($attachment->disk)->readStream($attachment->file_path);
            if ($stream === false || $stream === null) {
                continue;
            }
            $email->addPart(new \Symfony\Component\Mime\Part\DataPart(
                new \Symfony\Component\Mime\Part\File(
                    Storage::disk($attachment->disk)->path($attachment->file_path)
                ),
                $attachment->filename,
                $attachment->mime_type ?: 'application/octet-stream',
            ));
        }

        return $email;
    }

    /**
     * @param  array{email: string, name?: string}|string  $rcpt
     */
    private function toAddress(mixed $rcpt): Address
    {
        if (is_string($rcpt)) {
            return new Address($rcpt);
        }
        $email = (string) ($rcpt['email'] ?? '');
        $name = (string) ($rcpt['name'] ?? '');

        return $name !== '' ? new Address($email, $name) : new Address($email);
    }
}
