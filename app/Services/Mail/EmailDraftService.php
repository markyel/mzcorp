<?php

namespace App\Services\Mail;

use App\Enums\MailDirection;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CRUD над draft-сообщениями (Phase 1.9 UI-переписка).
 *
 * Draft = email_messages.is_draft = true с pre-filled полями. При send
 * OutgoingMailSender переписывает message_id на финальный и снимает флаг.
 * Видимость драфта — только автору + privileged (scope EmailMessage::visibleTo).
 */
class EmailDraftService
{
    public function __construct(
        private readonly OutgoingMailboxResolver $resolver,
        private readonly WebFormSubmissionParser $webForm,
        private readonly ForwardedRequestParser $forwarded,
    ) {}

    /**
     * Создать reply-draft из существующего письма треда.
     */
    public function createReply(
        Request $request,
        EmailMessage $replyTo,
        User $author,
        bool $replyAll = false,
    ): EmailMessage {
        $resolved = $this->resolver->resolve($request);
        $mailbox = $resolved['mailbox'];

        $recipients = $this->computeRecipients($replyTo, $mailbox, $replyAll);

        // Якорь пришёл с технического ящика, а не от клиента: либо релей
        // веб-формы (order@myzip.ru), либо форвардер пересланной заявки
        // (noreply@myzip.ru). computeRecipients подставил бы в «Кому» этот
        // технический адрес. Реальный клиент уже сохранён в Request.client_email
        // (WebFormSubmissionParser / ForwardedRequestParser) — отвечаем туда.
        // Срабатывает, только пока клиент сам не написал с реального адреса
        // (тогда якорь — его письмо, from_email == client_email, гард не нужен).
        if ($replyTo->direction === MailDirection::Inbound
            && ($this->webForm->isWebFormSubmission($replyTo) || $this->forwarded->isForwarded($replyTo))
            && $request->client_email
            && mb_strtolower($request->client_email) !== mb_strtolower((string) $replyTo->from_email)
        ) {
            $recipients['to'] = [[
                'email' => $request->client_email,
                'name' => (string) ($request->client_name ?? ''),
            ]];
        }

        $references = $this->mergeReferences(
            (array) ($replyTo->references_header ?? []),
            $replyTo->message_id,
        );

        // body_plain/body_html стартуют пустыми. Подпись и quote оригинала
        // приклеиваются в OutgoingMailMimeBuilder при send (чтобы менеджер
        // видел в textarea только своё письмо, а не сырой HTML цитаты).
        return $this->createDraft([
            'request' => $request,
            'mailbox' => $mailbox,
            'author' => $author,
            'subject' => $this->normalizeReplySubject($replyTo->subject),
            'to' => $recipients['to'],
            'cc' => $recipients['cc'],
            'inReplyTo' => $replyTo->message_id,
            'references' => $references,
            'bodyHtml' => '',
            'bodyPlain' => '',
        ]);
    }

    /**
     * Создать compose-draft — новое письмо клиенту в рамках заявки.
     */
    public function createCompose(Request $request, User $author): EmailMessage
    {
        $resolved = $this->resolver->resolve($request);
        $mailbox = $resolved['mailbox'];

        $to = $request->client_email
            ? [['email' => $request->client_email, 'name' => $request->client_name ?: '']]
            : [];

        $subject = $request->subject
            ? '['.$request->internal_code.'] '.$request->subject
            : '['.$request->internal_code.']';

        return $this->createDraft([
            'request' => $request,
            'mailbox' => $mailbox,
            'author' => $author,
            'subject' => $subject,
            'to' => $to,
            'cc' => [],
            'inReplyTo' => null,
            'references' => [],
            'bodyHtml' => '',
            'bodyPlain' => '',
        ]);
    }

    /**
     * Частичный апдейт draft'а (auto-save из Livewire).
     *
     * @param  array{subject?: string, to_recipients?: array, cc_recipients?: array, body_html?: string, body_plain?: string}  $data
     */
    public function update(EmailMessage $draft, array $data): void
    {
        if (! $draft->is_draft) {
            throw new \LogicException('Cannot update non-draft EmailMessage');
        }

        $allowed = array_intersect_key($data, array_flip([
            'subject', 'to_recipients', 'cc_recipients', 'body_html', 'body_plain',
        ]));
        if ($allowed === []) {
            return;
        }

        $allowed['last_edited_at'] = now();

        $draft->update($allowed);
    }

    /**
     * Удалить draft + физические файлы вложений.
     */
    public function delete(EmailMessage $draft): void
    {
        if (! $draft->is_draft) {
            throw new \LogicException('Cannot delete non-draft EmailMessage via this method');
        }

        DB::transaction(function () use ($draft) {
            foreach ($draft->attachments as $attachment) {
                $this->deleteAttachmentFile($attachment);
                $attachment->delete();
            }
            $draft->delete();
        });
    }

    /**
     * Удалить одно вложение из draft'а.
     */
    public function removeAttachment(EmailMessage $draft, EmailAttachment $attachment): void
    {
        if (! $draft->is_draft) {
            throw new \LogicException('Cannot modify attachments of non-draft EmailMessage');
        }
        if ($attachment->email_message_id !== $draft->id) {
            throw new \LogicException('Attachment does not belong to this draft');
        }

        $this->deleteAttachmentFile($attachment);
        $attachment->delete();
    }

    /* ----------------------- internals ----------------------- */

    /**
     * @param  array{request: Request, mailbox: ?Mailbox, author: User, subject: string, to: array, cc: array, inReplyTo: ?string, references: array, bodyHtml: string, bodyPlain: string}  $params
     */
    private function createDraft(array $params): EmailMessage
    {
        $mailbox = $params['mailbox'];

        // Mailbox может быть null если resolver не нашёл подходящего —
        // создаём draft всё равно, при send ругнётся. Это нормально для
        // UX: пользователь видит черновик и понимает, что нужно подключить
        // ящик / попросить РОПа.
        $mailboxId = $mailbox?->id;
        $fromEmail = $mailbox?->email ?? '';

        // Временный message_id (UUID). Финальный пишется в OutgoingMailSender
        // при build MIME. Сейчас нужен только потому что колонка NOT NULL.
        $tempMessageId = 'draft.'.Str::uuid()->toString().'@mzcorp.ru';

        return EmailMessage::create([
            'mailbox_id' => $mailboxId,
            'folder' => 'Sent',
            'direction' => MailDirection::Outbound,
            'imap_uid' => null,
            'message_id' => $tempMessageId,
            'in_reply_to' => $params['inReplyTo'],
            'references_header' => $params['references'] ?: null,
            'subject' => mb_substr($params['subject'], 0, 998),
            'from_email' => $fromEmail,
            'from_name' => $params['author']->name,
            'to_recipients' => $params['to'],
            'cc_recipients' => $params['cc'] ?: null,
            'sent_at' => null,
            'body_plain' => $params['bodyPlain'],
            'body_html' => $params['bodyHtml'],
            'headers' => [
                'X-MyLift-Author-User-Id' => (string) $params['author']->id,
            ],
            'related_request_id' => $params['request']->id,
            'is_draft' => true,
            'draft_author_user_id' => $params['author']->id,
            'last_edited_at' => now(),
        ]);
    }

    /**
     * @return array{to: array<int, array{email: string, name: string}>, cc: array<int, array{email: string, name: string}>}
     */
    private function computeRecipients(EmailMessage $replyTo, ?Mailbox $fromMailbox, bool $replyAll): array
    {
        $excludeEmails = $this->ourEmails($fromMailbox);

        $to = [[
            'email' => $replyTo->from_email,
            'name' => (string) ($replyTo->from_name ?? ''),
        ]];

        $cc = [];
        if ($replyAll) {
            foreach ((array) ($replyTo->to_recipients ?? []) as $rcpt) {
                $cc[] = $rcpt;
            }
            foreach ((array) ($replyTo->cc_recipients ?? []) as $rcpt) {
                $cc[] = $rcpt;
            }
        }

        $to = $this->dedupRecipients($to, $excludeEmails);
        $cc = $this->dedupRecipients($cc, $excludeEmails, alreadyIn: $to);

        return ['to' => $to, 'cc' => $cc];
    }

    /**
     * @return array<int, string> lowercase emails to exclude
     */
    private function ourEmails(?Mailbox $fromMailbox): array
    {
        $configured = (array) config('services.mail_outbound.our_emails', []);
        if ($configured === []) {
            $configured = Mailbox::query()->pluck('email')->all();
        }
        $result = array_map('mb_strtolower', array_map('strval', $configured));
        if ($fromMailbox !== null) {
            $result[] = mb_strtolower($fromMailbox->email);
        }

        return array_values(array_unique(array_filter($result)));
    }

    /**
     * @param  array<int, array{email: string, name: string}>  $recipients
     * @param  array<int, string>  $exclude  lowercase
     * @param  array<int, array{email: string, name: string}>  $alreadyIn  чтобы не дублировать To в Cc
     * @return array<int, array{email: string, name: string}>
     */
    private function dedupRecipients(array $recipients, array $exclude, array $alreadyIn = []): array
    {
        $seen = [];
        foreach ($alreadyIn as $r) {
            $seen[mb_strtolower((string) ($r['email'] ?? ''))] = true;
        }

        $out = [];
        foreach ($recipients as $r) {
            $email = mb_strtolower((string) ($r['email'] ?? ''));
            if ($email === '' || in_array($email, $exclude, true) || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $out[] = [
                'email' => (string) $r['email'],
                'name' => (string) ($r['name'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $existing
     * @return array<int, string>
     */
    private function mergeReferences(array $existing, string $replyToMessageId): array
    {
        // Санитизация: References входящих от некоторых клиентов (mail.ru)
        // разделены запятыми — токены приходят с хвостовой ',' или вовсе
        // одиночной ','. Битый ID роняет отправку в Symfony Address
        // («Email "," does not comply with addr-spec»). Дубль-защита есть и
        // в OutgoingMailMimeBuilder::sanitizeMessageIds на build.
        $clean = [];
        foreach (array_merge($existing, [$replyToMessageId]) as $id) {
            $id = trim((string) $id, " \t\r\n<>,;");
            if ($id !== '' && str_contains($id, '@') && ! preg_match('/\s/', $id)) {
                $clean[] = $id;
            }
        }
        $merged = array_values(array_unique($clean));

        // Защита от overflow по RFC 5322 998 char/header.
        $joinedLen = strlen(implode(' ', $merged));
        while ($joinedLen > 900 && count($merged) > 2) {
            // Срезаем самые старые (RFC 2822 §3.6.4 рекомендует сохранять
            // первый и последние).
            array_splice($merged, 1, 1);
            $joinedLen = strlen(implode(' ', $merged));
        }

        return $merged;
    }

    private function normalizeReplySubject(?string $subject): string
    {
        $s = trim((string) $subject);
        if ($s === '') {
            return 'Re:';
        }
        if (preg_match('/^re:\s*/i', $s)) {
            return $s;
        }

        return 'Re: '.$s;
    }

    private function deleteAttachmentFile(EmailAttachment $attachment): void
    {
        try {
            Storage::disk($attachment->disk)->delete($attachment->file_path);
        } catch (\Throwable) {
            // best-effort: оставляем orphan-файл, БД будет источником истины
        }
    }
}
