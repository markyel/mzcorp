<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Mailbox;
use Illuminate\Support\Facades\Log;
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
 *   - Отправка — OutgoingMailSender::sendAdHoc (XOAUTH2 SMTP ящика; при
 *     сбое Яндекса — резервный канал). Своя копия сборки SMTP-транспорта
 *     здесь была дублем и удалена 2026-10-11.
 *   - Тело сообщения — оригинальное body_html / body_plain + краткое
 *     служебное сообщение «Переслано MyLift по правилу X».
 */
class MailForwarder
{
    public function __construct(private readonly OutgoingMailSender $sender)
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
            $email = $this->buildForwardEmail($message, $mailbox, $toEmail, $ruleName);

            // SMTP ящика (свежий OAuth-токен, XOAUTH2) — общий путь
            // OutgoingMailSender: при сбое Яндекса уходит резервным каналом.
            $this->sender->sendAdHoc($mailbox, $email);

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
