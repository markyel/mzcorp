<?php

namespace App\Services\Mail;

use App\Jobs\Mail\AppendToSentFolderJob;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Cache;
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
    /** Сколько держим замок отправки черновика: SMTP с вложениями идёт до ~40 с. */
    private const SEND_LOCK_SECONDS = 300;

    public function __construct(
        private readonly YandexOAuthService $oauth,
        private readonly OutgoingMailMimeBuilder $mimeBuilder,
        private readonly OutgoingSendRetryService $retry,
        private readonly MailFallbackSettings $fallback,
    ) {
    }

    /**
     * $queueOnTransientFailure — письмо, написанное человеком: если SMTP
     * недоступен (сеть, 4xx), не отказываем, а ставим в очередь повторов
     * (OutgoingSendRetryService) и возвращаем queued=true + next_attempt_at.
     * Автоматические рассылки зовут без флага — у них своя логика сбоя.
     *
     * $viaFallback — отправить через резервный канал (services.mail_outbound.
     * fallback: Unisender Go / Postbox) вместо SMTP ящика. Решает очередь
     * повторов (OutgoingSendRetryService::shouldUseFallback). From и цепочка
     * писем те же, копию в «Отправленные» кладёт AppendToSentFolderJob.
     *
     * @return array{success: bool, draft: EmailMessage, error?: string, error_message?: string, queued?: bool, next_attempt_at?: \Carbon\CarbonInterface}
     */
    public function sendDraft(int $draftId, bool $queueOnTransientFailure = false, bool $viaFallback = false): array
    {
        // Один черновик — одна отправка за раз: «Отправить» автора и повтор из
        // очереди не должны встретиться и отправить клиенту письмо дважды.
        $lock = Cache::lock('outgoing-draft-send:'.$draftId, self::SEND_LOCK_SECONDS);
        if (! $lock->get()) {
            return ['success' => false, 'draft' => EmailMessage::findOrFail($draftId), 'error' => 'send_in_progress'];
        }

        try {
            return $this->sendDraftLocked($draftId, $queueOnTransientFailure, $viaFallback && $this->retry->fallbackEnabled());
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{success: bool, draft: EmailMessage, error?: string, error_message?: string, queued?: bool, next_attempt_at?: \Carbon\CarbonInterface}
     */
    private function sendDraftLocked(int $draftId, bool $queueOnTransientFailure, bool $viaFallback): array
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

        // Резервному каналу OAuth Яндекса не нужен (и при сбое Яндекса
        // обновление токена само может не пройти).
        if (! $viaFallback) {
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

            if ($viaFallback) {
                // Служебные заголовки провайдеру — только в отправляемую копию,
                // в «Отправленные» (rawMime) они не попадают.
                $outgoing = clone $email;
                foreach ($this->fallback->headers() as $name => $value) {
                    $outgoing->getHeaders()->addTextHeader($name, $value);
                }
                (new Mailer($this->fallback->transport()))->send($outgoing);
            } else {
                $transport = $this->buildSmtpTransport($mailbox);
                $mailer = new Mailer($transport);
                $mailer->send($email);
            }

            $rawMime = $email->toString();
        } catch (\Throwable $e) {
            Log::error('OutgoingMailSender: SMTP send failed', [
                'draft_id' => $draft->id,
                'mailbox_id' => $mailbox->id,
                'via' => $viaFallback ? 'fallback' : 'mailbox',
                'error' => $e->getMessage(),
            ]);

            if ($queueOnTransientFailure && $this->retry->isTransient($e)) {
                // Финальные message_id/тело на инстансе не сохраняем: повтор
                // соберёт письмо заново из черновика.
                $draft->refresh();
                $next = $this->retry->schedule($draft, $e->getMessage());
                if ($next !== null) {
                    return [
                        'success' => false,
                        'draft' => $draft,
                        'error' => 'smtp_send_queued',
                        'error_message' => $e->getMessage(),
                        'queued' => true,
                        'next_attempt_at' => $next,
                    ];
                }
            }

            return ['success' => false, 'draft' => $draft, 'error' => 'smtp_send_failed', 'error_message' => $e->getMessage()];
        }

        if ($viaFallback) {
            Log::warning('OutgoingMailSender: sent via fallback channel', [
                'draft_id' => $draft->id,
                'mailbox_id' => $mailbox->id,
                'channel' => $this->fallback->name(),
            ]);
        }

        DB::transaction(function () use ($draft, $finalMessageId, $finalBody, $viaFallback) {
            // update() сохраняет и detected_artifacts.marketing_block_id, который
            // MarketingBlockService зафиксировал на инстансе в composeFinalBody.
            $draft->update([
                'is_draft' => false,
                'sent_at' => now(),
                'message_id' => $finalMessageId,
                'body_plain' => $finalBody['plain'],
                'body_html' => $finalBody['html'],
                // Ушло (возможно, с повтора) — следы очереди не нужны.
                'send_status' => null,
                'send_next_attempt_at' => null,
                'send_last_error' => null,
                'sent_via' => $viaFallback ? mb_substr($this->fallback->name(), 0, 60) : null,
            ]);
        });

        // Счётчик показов рекламного блока (non-fatal).
        $blockId = $draft->detected_artifacts[\App\Services\Marketing\MarketingBlockService::ARTIFACT_BLOCK_ID] ?? null;
        if ($blockId) {
            try {
                \App\Models\MarketingBlock::query()->whereKey($blockId)->update([
                    'impressions_count' => DB::raw('impressions_count + 1'),
                    'last_used_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('OutgoingMailSender: marketing impressions increment failed', [
                    'draft_id' => $draft->id,
                    'block_id' => $blockId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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

    /**
     * Отправить готовое Symfony Email с ящика мимо EmailMessage/драфта
     * (тестовое письмо рекламного блока и т.п.). Обновляет OAuth-токен,
     * бросает исключение при сбое — вызывающий решает, что показать.
     */
    public function sendAdHoc(\App\Models\Mailbox $mailbox, \Symfony\Component\Mime\Email $email): void
    {
        if (! $mailbox->canSendOutbound()) {
            throw new \RuntimeException("Ящик {$mailbox->email} не может отправлять письма (нет OAuth/пароля или неактивен).");
        }
        $this->oauth->ensureFreshToken($mailbox);
        $mailbox->refresh();

        (new Mailer($this->buildSmtpTransport($mailbox)))->send($email);
    }

    public function buildSmtpTransport(\App\Models\Mailbox $mailbox): TransportInterface
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
