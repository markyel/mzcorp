<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Email acting-менеджеру: по ДЕЛЕГИРОВАННОЙ ему заявке новое сообщение.
 * Рендерится и шлётся через SystemNotificationMailer (per-mailbox SMTP,
 * т.к. MAIL_MAILER=log). Ссылка на заявку — абсолютная (APP_URL=mzcorp.ru).
 */
class DelegatedRequestActivityMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $requestId,
        public string $internalCode,
        public ?string $subject,
        public ?string $clientName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'MyLift · По делегированной вам заявке ' . $this->internalCode . ' — новое сообщение',
        );
    }

    public function content(): Content
    {
        $url = route('requests.show', $this->requestId);
        $code = e($this->internalCode);
        $client = e((string) ($this->clientName ?: '—'));
        $subj = e(Str::limit((string) $this->subject, 140));

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">'
            . '<p>По <b>делегированной вам</b> заявке <b>' . $code . '</b> новое сообщение от клиента.</p>'
            . '<p style="color:#4b5563;margin:8px 0">' . $client . ' · ' . $subj . '</p>'
            . '<p style="margin:16px 0"><a href="' . e($url) . '" '
            . 'style="display:inline-block;background:#0284c7;color:#fff;text-decoration:none;'
            . 'padding:9px 16px;border-radius:6px;font-weight:600">Открыть заявку ' . $code . '</a></p>'
            . '<p style="color:#9ca3af;font-size:12px;margin-top:12px">' . e($url) . '</p>'
            . '</div>';

        return new Content(htmlString: $html);
    }
}
