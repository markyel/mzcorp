<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Клиенту бота заявок: код подтверждения e-mail. Шлётся через
 * SystemNotificationMailer (MAIL_MAILER=log на проде).
 */
class BotVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code, public string $messenger) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Код подтверждения: '.$this->code.' — MyZip');
    }

    public function content(): Content
    {
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">'
            .'<p>Здравствуйте!</p>'
            .'<p>Ваш код подтверждения для бота MyZip в '.e($this->messenger).':</p>'
            .'<p style="font-size:28px;font-weight:700;letter-spacing:4px;margin:16px 0">'.e($this->code).'</p>'
            .'<p>Отправьте его боту в ответ. Код действует 15 минут.</p>'
            .'<p style="color:#6b7280;font-size:12px">Если вы не запрашивали код, просто проигнорируйте это письмо.</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}
