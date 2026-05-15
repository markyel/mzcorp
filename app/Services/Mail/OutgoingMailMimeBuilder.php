<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Сборка Symfony Email из EmailMessage-draft (Phase 1.9).
 *
 * - Заголовки In-Reply-To / References + наши кастомные.
 * - Message-ID генерируем явно, сохраняем в EmailMessage для дедупа при
 *   Sent-sync (см. MessagePersister обновление в Commit 4).
 * - Attachments читаются стримом из storage (не грузим в память целиком).
 *
 * Финальный body клиенту строится **здесь**:
 *   <user text → HTML> + <signature> + <quoted original>.
 *
 * В draft.body_plain/body_html лежит ТОЛЬКО то, что менеджер ввёл в textarea.
 * Подпись и quote клеятся при build, чтобы менеджер видел в форме чистый
 * текст, а не сырой HTML.
 */
class OutgoingMailMimeBuilder
{
    public function __construct(private readonly MailQuoteBuilder $quoteBuilder)
    {
    }

    /**
     * Сгенерировать Message-ID, который и пойдёт в MIME, и сохранится в
     * email_messages.message_id (нужно вызвать ДО build()).
     */
    public function generateMessageId(): string
    {
        return Str::uuid()->toString() . '@mzcorp.ru';
    }

    /**
     * Финальный body, который реально уйдёт клиенту и сохранится в треде.
     * Используется и при send (build), и при пост-send update'е draft'а
     * (чтобы в треде CRM показывалось то же, что увидит клиент).
     *
     * @return array{html: string, plain: string}
     */
    public function composeFinalBody(EmailMessage $draft): array
    {
        $userText = (string) ($draft->body_plain ?? '');

        $author = $draft->draft_author_user_id
            ? User::find($draft->draft_author_user_id)
            : null;
        $signature = $this->formatSignature($author);

        $quote = ['html' => '', 'plain' => ''];
        if ($draft->in_reply_to) {
            $replyTo = EmailMessage::query()
                ->where('message_id', $draft->in_reply_to)
                ->where('is_draft', false)
                ->first();
            if ($replyTo) {
                $quote = $this->quoteBuilder->build($replyTo);
            }
        }

        // Footer с internal_code — safety net для linker'а (Level 3 +
        // matchBySubjectCode): если клиент уберёт `[M-2026-NNNN]` из subject
        // или Outlook потеряет In-Reply-To при ответе, код останется в теле,
        // и InboundReplyLinker сматчит обратно к нужной Request.
        $footer = $this->buildRequestCodeFooter($draft);

        $userHtml = $this->plainToHtml($userText);

        $plain = $userText
            . ($signature['plain'] !== '' ? "\n" . $signature['plain'] : '')
            . ($footer['plain'] !== '' ? "\n\n" . $footer['plain'] : '')
            . ($quote['plain'] !== '' ? "\n\n" . $quote['plain'] : '');

        $html = $userHtml
            . ($signature['html'] !== '' ? $signature['html'] : '')
            . ($footer['html'] !== '' ? $footer['html'] : '')
            . ($quote['html'] !== '' ? $quote['html'] : '');

        return ['html' => $html, 'plain' => $plain];
    }

    /**
     * Невзрачный footer с № заявки. Клиент видит мелкий «—\n№ заявки:
     * M-2026-NNNN» в конце письма; для нас это надёжный матчер на случай
     * сломанных headers / удалённого префикса в subject.
     *
     * @return array{html: string, plain: string}
     */
    private function buildRequestCodeFooter(EmailMessage $draft): array
    {
        if (! $draft->related_request_id) {
            return ['html' => '', 'plain' => ''];
        }
        $request = \App\Models\Request::query()->find($draft->related_request_id, ['internal_code']);
        if (! $request || ! $request->internal_code) {
            return ['html' => '', 'plain' => ''];
        }

        $code = $request->internal_code;
        $plain = "—\n№ заявки: {$code}";
        $html = '<div style="margin-top:18px;color:#94a3b8;font-size:12px;font-family:Arial,sans-serif">'
            . '— <br>№ заявки: <span style="font-family:monospace">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</div>';

        return ['html' => $html, 'plain' => $plain];
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

        $finalBody = $this->composeFinalBody($draft);
        if ($finalBody['plain'] !== '') {
            $email->text($finalBody['plain']);
        }
        if ($finalBody['html'] !== '') {
            $email->html($finalBody['html']);
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
        // временный) либо генерим новый. Sender проставит его в БД до send.
        $messageId = $draft->message_id;
        if (! $messageId || str_starts_with($messageId, 'draft.')) {
            $messageId = $this->generateMessageId();
        }
        $headers->addIdHeader('Message-ID', $messageId);

        // Анти-loop маркеры для нашего же Sent-sync (Commit 4 — дедуп
        // по этому заголовку перед созданием новой EmailMessage). НЕ
        // X-MyLift-Forwarded, чтобы MailRouter::isLoopMessage не дропнул.
        $headers->addTextHeader('X-MyLift-Reply', '1');
        $authorId = (string) ($draft->headers['X-MyLift-Author-User-Id'] ?? '');
        if ($authorId !== '') {
            $headers->addTextHeader('X-MyLift-Author-User-Id', $authorId);
        }

        // Attachments.
        foreach ($draft->attachments as $attachment) {
            $diskPath = Storage::disk($attachment->disk)->path($attachment->file_path);
            $email->addPart(new \Symfony\Component\Mime\Part\DataPart(
                new \Symfony\Component\Mime\Part\File($diskPath),
                $attachment->filename,
                $attachment->mime_type ?: 'application/octet-stream',
            ));
        }

        return $email;
    }

    /**
     * Plain user text → безопасный HTML. Двойной перенос → <p>, одиночный → <br>.
     * Не верим HTML-разметке в input'е менеджера — пропускаем через
     * htmlspecialchars (XSS guard в случае если в textarea вставили <script>).
     */
    private function plainToHtml(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $paragraphs = preg_split('/\r?\n\s*\r?\n/', $text) ?: [];
        $out = [];
        foreach ($paragraphs as $p) {
            $escaped = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
            $escaped = nl2br($escaped);
            $out[] = '<p>' . $escaped . '</p>';
        }
        return implode("\n", $out);
    }

    /**
     * @return array{html: string, plain: string}
     */
    private function formatSignature(?User $author): array
    {
        $raw = trim((string) ($author?->email_signature ?? ''));
        if ($raw === '') {
            return ['html' => '', 'plain' => ''];
        }
        $plain = "\n-- \n" . $raw;
        $html = '<p style="color:#666;">-- <br>' . nl2br(htmlspecialchars($raw, ENT_QUOTES, 'UTF-8')) . '</p>';
        return ['html' => $html, 'plain' => $plain];
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
