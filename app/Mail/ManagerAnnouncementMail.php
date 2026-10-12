<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Служебное объявление менеджерам (новый порядок работы, изменения в
 * mzCorp). Тело — готовый HTML фрагмент; шлётся через
 * SystemNotificationMailer (MAIL_MAILER=log на проде).
 */
class ManagerAnnouncementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $subjectLine, public string $bodyHtml) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.55">'
            .$this->bodyHtml.'</div>');
    }
}
