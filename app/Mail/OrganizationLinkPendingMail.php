<?php

namespace App\Mail;

use App\Models\OrganizationLinkRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Менеджеру: в выданном КП/счёте реквизиты контрагента, который привязан
 * к другим адресам, — возможно, ошибка. Связь не создана, нужна кнопка.
 * Шлётся через SystemNotificationMailer (MAIL_MAILER=log на проде).
 */
class OrganizationLinkPendingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public OrganizationLinkRequest $pending) {}

    public function envelope(): Envelope
    {
        $code = $this->pending->request?->internal_code;

        return new Envelope(
            subject: 'MyLift · Проверьте реквизиты в '.$this->documentWord().($code ? ' по заявке '.$code : ''),
        );
    }

    public function content(): Content
    {
        $p = $this->pending;
        $org = $p->organization;
        $url = route('clients.link-requests.show', $p->id);

        $code = $p->request?->internal_code;
        $what = $p->documentLabel().($p->document_number ? ' № '.$p->document_number : '');
        $requisites = e((string) $org?->name).($org?->inn ? ', ИНН '.e($org->inn) : '');
        $email = e((string) $p->contact?->email);
        $known = collect($p->known_emails ?? [])->take(3)->map(fn ($e) => e($e))->implode(', ');

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">'
            .'<p>'.($code ? 'По заявке <b>'.e($code).'</b> ' : '').'Вы выдали '.e($what).' с реквизитами <b>'.$requisites.'</b>.</p>'
            .'<p>Данное действие может быть ошибкой: эти реквизиты уже привязаны к другим адресам'
            .($known !== '' ? ' ('.$known.(count($p->known_emails ?? []) > 3 ? ' и др.' : '').')' : '')
            .', а к адресу заказчика <b>'.$email.'</b> — нет.</p>'
            .'<p><b>Автоматической привязки контрагента к e-mail заказчика не произошло.</b></p>'
            .'<p>Если это не ошибка, подтвердите:</p>'
            .'<p style="margin:16px 0"><a href="'.e($url).'" '
            .'style="display:inline-block;background:#D32027;color:#fff;text-decoration:none;'
            .'padding:9px 16px;border-radius:6px;font-weight:600">Подтвердить привязку</a></p>'
            .'<p style="color:#6b7280;font-size:12px">На той же странице можно отметить, что это ошибка — тогда система больше не будет предлагать эту привязку.</p>'
            .'<p style="color:#9ca3af;font-size:12px;margin-top:12px">'.e($url).'</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }

    private function documentWord(): string
    {
        return $this->pending->document_type === 'outbound_invoice' ? 'счёте' : 'КП';
    }
}
