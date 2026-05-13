<?php

namespace App\Services\Mail;

use App\Jobs\Mail\AppendToSentFolderJob;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Фактическая отправка draft-сообщения через SMTP ящика (Phase 1.9).
 *
 * Flow:
 *   1. ensureFreshToken — обновляем access_token если протух.
 *   2. buildSmtpTransport — EsmtpFactory + XOAuth2Authenticator (паттерн из
 *      MailForwarder:70-102).
 *   3. MimeBuilder::build — собираем MIME.
 *   4. Mailer::send — реальная отправка.
 *   5. Обновляем draft: is_draft=false, sent_at=now(), message_id=финальный.
 *   6. Dispatch AppendToSentFolderJob — IMAP APPEND асинхронно.
 *
 * После send draft становится обычным outbound EmailMessage, виден в треде
 * у assigned менеджера. Linker не нужен — related_request_id уже стоит.
 */
class OutgoingMailSender
{
    public function __construct(
        private readonly YandexOAuthService $oauth,
        private readonly OutgoingMailMimeBuilder $mimeBuilder,
    ) {
    }

    /**
     * @return array{success: bool, draft: EmailMessage, error?: string}
     */
    public function sendDraft(int $draftId): array
    {
        $draft = EmailMessage::with(['mailbox', 'attachments'])->findOrFail($draftId);

        if (! $draft->is_draft) {
            return ['success' => false, 'draft' => $draft, 'error' => 'not_a_draft'];
        }

        $mailbox = $draft->mailbox;
        if ($mailbox === null) {
            return ['success' => false, 'draft' => $draft, 'error' => 'no_mailbox'];
        }
        if (! $mailbox->canSendOutbound()) {
            return ['success' => false, 'draft' => $draft, 'error' => 'mailbox_cannot_send'];
        }

        try {
            $this->oauth->ensureFreshToken($mailbox);
            $mailbox->refresh();
        } catch (\Throwable $e) {
            Log::warning('OutgoingMailSender: OAuth refresh failed', [
                'mailbox_id' => $mailbox->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'draft' => $draft, 'error' => 'oauth_refresh_failed'];
        }

        // Финальный Message-ID — назначаем ДО build, чтобы и в MIME-заголовке,
        // и в БД оказался один и тот же. Нужно для дедупа при Sent-sync.
        $finalMessageId = $this->mimeBuilder->generateMessageId();
        $draft->message_id = $finalMessageId;

        // Финальный body (user text + подпись + цитата) — то же, что уйдёт
        // клиенту. Сохраним в БД, чтобы в треде CRM показывалось ровно то,
        // что увидел клиент, а не «пустое» draft-тело.
        $finalBody = $this->mimeBuilder->composeFinalBody($draft);

        try {
            $email = $this->mimeBuilder->build($draft, $mailbox);

            $transport = $this->buildSmtpTransport($mailbox);
            $mailer = new Mailer($transport);
            $mailer->send($email);

            $rawMime = $email->toString();
        } catch (\Throwable $e) {
            Log::error('OutgoingMailSender: SMTP send failed', [
                'draft_id' => $draft->id,
                'mailbox_id' => $mailbox->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'draft' => $draft, 'error' => 'smtp_send_failed'];
        }

        DB::transaction(function () use ($draft, $finalMessageId, $finalBody) {
            $draft->update([
                'is_draft' => false,
                'sent_at' => now(),
                'message_id' => $finalMessageId,
                'body_plain' => $finalBody['plain'],
                'body_html' => $finalBody['html'],
            ]);
        });

        // Async IMAP APPEND в Sent — best-effort. Письмо в треде уже видно.
        try {
            AppendToSentFolderJob::dispatch($draft->id, $rawMime);
        } catch (\Throwable $e) {
            Log::warning('OutgoingMailSender: dispatch AppendToSentFolderJob failed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['success' => true, 'draft' => $draft->fresh()];
    }

    private function buildSmtpTransport(\App\Models\Mailbox $mailbox): TransportInterface
    {
        $factory = new EsmtpTransportFactory();
        $dsn = new Dsn(
            scheme: 'smtps',
            host: $mailbox->smtp_host,
            user: $mailbox->smtp_username,
            password: '',
            port: $mailbox->smtp_port,
        );

        $transport = $factory->create($dsn);

        if ($mailbox->isOAuth() && method_exists($transport, 'addAuthenticator')) {
            $transport->addAuthenticator(new XOAuth2Authenticator());
            $transport->setPassword((string) $mailbox->accessToken());
        } elseif ($mailbox->isOAuth()) {
            throw new \RuntimeException(
                'Symfony Mailer too old: addAuthenticator() unavailable — XOAUTH2 SMTP off.'
            );
        } else {
            $transport->setPassword((string) $mailbox->password());
        }

        return $transport;
    }
}
