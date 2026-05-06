<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Mailbox;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Пересылка письма «как есть» на forward_to_email.
 *
 * Foundation §1.5 «action_type=forward»: переслать письмо на нужный адрес
 * (рекламации → claims@..., бухгалтерия → buh@..., и т.п.).
 *
 * Технические детали:
 *   - Используем SMTP того ящика (mailbox), на который письмо пришло —
 *     чтобы From/Reply-To был корректный, и Yandex не банил за рассылку
 *     с чужого домена.
 *   - Для XOAUTH2 SMTP используем кастомную SMTP-аутентификацию через
 *     Symfony Mailer (XOAuth2Authenticator).
 *   - Тело сообщения — оригинальное body_html / body_plain + краткое
 *     служебное сообщение «Переслано MyLift по правилу X».
 */
class MailForwarder
{
    public function __construct(private readonly YandexOAuthService $oauth)
    {
    }

    /**
     * @return bool true — переслано без ошибок.
     */
    public function forward(EmailMessage $message, string $toEmail, string $ruleName = ''): bool
    {
        $mailbox = $message->mailbox;
        if (! $mailbox) {
            return false;
        }

        try {
            // Гарантируем актуальный access_token (для XOAUTH2 SMTP).
            $this->oauth->ensureFreshToken($mailbox);

            $transport = $this->buildSmtpTransport($mailbox);
            $mailer = new Mailer($transport);

            $email = $this->buildForwardEmail($message, $mailbox, $toEmail, $ruleName);

            $mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to forward mail', [
                'mailbox_id' => $mailbox->id,
                'email_message_id' => $message->id,
                'to' => $toEmail,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function buildSmtpTransport(Mailbox $mailbox): TransportInterface
    {
        // Symfony EsmtpTransportFactory + кастомный authenticator для XOAUTH2.
        // Альтернатива: ставим webklex/oauth2-yandex или свой DSN. Здесь —
        // явно конструируем EsmtpTransport с XOAuth2Authenticator.

        $factory = new EsmtpTransportFactory();
        $dsn = new Dsn(
            scheme: 'smtps',
            host: $mailbox->smtp_host,
            user: $mailbox->smtp_username,
            password: '', // Не используется — XOAuth2Authenticator берёт токен из окружения
            port: $mailbox->smtp_port,
        );

        $transport = $factory->create($dsn);

        if ($mailbox->isOAuth() && method_exists($transport, 'addAuthenticator')) {
            $transport->addAuthenticator(new XOAuth2Authenticator());
            // Symfony XOAuth2Authenticator ожидает password = access_token.
            $transport->setPassword((string) $mailbox->accessToken());
        } elseif ($mailbox->isOAuth()) {
            // На случай старой версии Symfony Mailer.
            throw new \RuntimeException(
                'Symfony Mailer too old: addAuthenticator() not available — XOAUTH2 SMTP unavailable.'
            );
        } else {
            // App-password путь.
            $transport->setPassword((string) $mailbox->password());
        }

        return $transport;
    }

    private function buildForwardEmail(EmailMessage $msg, Mailbox $mailbox, string $toEmail, string $ruleName): SymfonyEmail
    {
        $subject = '[MyLift forward] ' . ($msg->subject ?: '(без темы)');

        $intro = "Письмо переслано MyLift CRM";
        if ($ruleName !== '') {
            $intro .= " по правилу «{$ruleName}»";
        }
        $intro .= ".\n\n"
            . "Оригинальный отправитель: " . ($msg->from_name ? "{$msg->from_name} <{$msg->from_email}>" : $msg->from_email) . "\n"
            . "Дата: " . ($msg->sent_at?->toDateTimeString() ?? 'неизвестно') . "\n"
            . "Тема: " . ($msg->subject ?: '(без темы)') . "\n"
            . str_repeat('—', 40) . "\n\n";

        $bodyPlain = $intro . ($msg->body_plain ?: strip_tags((string) $msg->body_html));

        $email = new SymfonyEmail();
        $email
            ->from($mailbox->email)
            ->to($toEmail)
            // Маркер для антициклической защиты в MailRouter (см. isLoopMessage()).
            ->getHeaders()->addTextHeader('X-MyLift-Forwarded', '1');
        $email
            ->subject($subject)
            ->text($bodyPlain);

        if ($msg->body_html) {
            $email->html($this->wrapHtml($msg, $intro));
        }

        return $email;
    }

    private function wrapHtml(EmailMessage $msg, string $intro): string
    {
        $introHtml = '<div style="background:#f5f5f3;padding:12px;border-left:3px solid #D32027;margin-bottom:16px;font-family:sans-serif;font-size:14px;color:#1b1b18;">'
            . nl2br(htmlspecialchars($intro, ENT_QUOTES, 'UTF-8'))
            . '</div>';

        return $introHtml . ($msg->body_html ?: '');
    }
}
