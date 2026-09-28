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
            subject: 'MyLift · Подтвердите реквизиты в '.$this->documentWord().($code ? ' по заявке '.$code : '')
                .': '.$this->pending->organization?->name.' → '.$this->pending->contact?->email,
        );
    }

    public function content(): Content
    {
        $p = $this->pending;
        $org = $p->organization;
        $url = route('clients.link-requests.show', $p->id);

        $code = $p->request?->internal_code;
        $orgName = '<b>'.e((string) $org?->name).'</b>'.($org?->inn ? ' (ИНН '.e($org->inn).')' : '');
        $email = '<b>'.e((string) $p->contact?->email).'</b>';
        $known = collect($p->known_emails ?? [])->take(3)->map(fn ($e) => e($e))->implode(', ');
        $doc = $p->documentLabel();
        $requestUrl = $p->request_id ? route('requests.show', $p->request_id) : null;
        $button = 'display:inline-block;text-decoration:none;padding:9px 16px;border-radius:6px;font-weight:600;';

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">'
            .'<p>'.($code ? 'По заявке <b>'.e($code).'</b> з' : 'З').'аказчику на адрес '.$email.' '
            .e($p->documentSentPhrase()).' для '.$orgName.'.</p>'
            .'<p>Эти реквизиты уже привязаны к другим адресам'
            .($known !== '' ? ' ('.$known.(count($p->known_emails ?? []) > 3 ? ' и др.' : '').')' : '')
            .', а к адресу '.$email.' — нет. Возможно, реквизиты в документе указаны ошибочно.</p>'
            .'<p><b>Автоматической привязки контрагента к e-mail заказчика не произошло.</b></p>'

            .'<p style="margin-top:20px"><b>Если реквизиты верные</b> — обязательно подтвердите привязку. '
            .'Без подтверждения реквизиты '.$orgName.' к адресу '.$email.' не привяжутся.</p>'
            .'<p style="margin:12px 0 20px"><a href="'.e($url).'" style="'.$button.'background:#D32027;color:#fff">'
            .'Подтвердить привязку</a></p>'

            .'<p><b>Если это ошибка</b> — зайдите в заявку'.($code ? ' '.e($code) : '')
            .' и вышлите заказчику '.e($doc).' с корректными реквизитами.</p>'
            .($requestUrl !== null
                ? '<p style="margin:12px 0 20px"><a href="'.e($requestUrl).'" style="'.$button
                    .'background:#fff;color:#1f2937;border:1px solid #d1d5db">Открыть заявку</a></p>'
                : '')
            .'<p style="color:#6b7280;font-size:12px">На странице подтверждения ошибку можно отметить, '
            .'тогда система больше не будет предлагать эту привязку.</p>'
            .'<p style="color:#9ca3af;font-size:12px;margin-top:12px">'.e($url).'</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }

    private function documentWord(): string
    {
        return $this->pending->document_type === 'outbound_invoice' ? 'счёте' : 'КП';
    }
}
