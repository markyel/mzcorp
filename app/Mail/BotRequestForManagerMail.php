<?php

namespace App\Mail;

use App\Enums\MessengerChannel;
use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Менеджеру: новая заявка из бота Telegram/MAX. У заявки из письма менеджер
 * получает само письмо клиента в личный ящик; у заявки из бота письма нет —
 * вместо него это уведомление (через SystemNotificationMailer, MAIL_MAILER=log).
 */
class BotRequestForManagerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Request $request) {}

    private function channel(): string
    {
        return MessengerChannel::tryFrom((string) $this->request->source)?->label() ?? 'бота';
    }

    public function envelope(): Envelope
    {
        $r = $this->request;
        $who = trim((string) ($r->client_company ?: $r->client_name ?: $r->client_email));

        return new Envelope(subject: 'Новая заявка '.$r->internal_code.' из '.$this->channel().($who !== '' ? ' — '.$who : ''));
    }

    public function content(): Content
    {
        $r = $this->request;
        $url = route('requests.show', $r->id);
        $text = (string) (($r->parsing_meta ?? [])['bot']['text'] ?? '');
        $items = $r->items->where('is_active', true)->sortBy('position')->take(40)
            ->map(fn ($i) => '<li>'.e((string) $i->parsed_name)
                .($i->parsed_article && ! str_contains((string) $i->parsed_name, (string) $i->parsed_article) ? ' · <span style="font-family:monospace">'.e((string) $i->parsed_article).'</span>' : '')
                .' — '.e(rtrim(rtrim(number_format((float) $i->parsed_qty, 2, ',', ' '), '0'), ',')).' '.e((string) ($i->parsed_unit ?: 'шт.'))
                .($i->catalog_item_id ? ' <span style="color:#059669">· в каталоге</span>' : '').'</li>')
            ->implode('');
        // Фото и файлы клиента — вложения «письма»-носителя (BotIntakeStore).
        $files = $r->exists
            ? \App\Models\EmailAttachment::query()
                ->whereIn('email_message_id', \App\Models\EmailMessage::query()->where('related_request_id', $r->id)
                    ->where('message_id', 'bot-'.$r->id.'@mzcorp.ru')->select('id'))
                ->pluck('filename')->all()
            : [];
        $row = fn (string $label, ?string $value) => $value !== null && trim($value) !== ''
            ? '<tr><td style="color:#6b7280;padding:2px 12px 2px 0">'.$label.'</td><td>'.e($value).'</td></tr>' : '';
        $button = 'display:inline-block;text-decoration:none;padding:9px 16px;border-radius:6px;font-weight:600;background:#D32027;color:#fff';

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">'
            .'<p>Новая заявка <b>'.e((string) $r->internal_code).'</b> из бота '.e($this->channel()).' назначена вам.</p>'
            .'<table style="font-size:14px;border-collapse:collapse">'
            .$row('Клиент', $r->client_name)
            .$row('E-mail', $r->client_email.' (подтверждён кодом)')
            .$row('Телефон', $r->client_phone)
            .$row('Организация', $r->organization?->name ?: $r->client_company)
            .'</table>'
            .($items !== '' ? '<p style="margin-top:14px"><b>Позиции</b></p><ul>'.$items.'</ul>' : '')
            .($files !== [] ? '<p><b>Файлы клиента</b> ('.count($files).') — в заявке, вкладка «Файлы»: '
                .e(implode(', ', array_slice($files, 0, 10))).(count($files) > 10 ? ' …' : '').'</p>' : '')
            .($text !== '' ? '<p><b>Что написал клиент</b></p><div style="white-space:pre-line;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:8px 10px">'.e($text).'</div>' : '')
            .'<p style="margin:18px 0"><a href="'.e($url).'" style="'.$button.'">Открыть заявку</a></p>'
            .'<p style="color:#6b7280;font-size:12px">Письма-источника у заявки нет. КП и счёт отправляйте клиенту письмом на '.e((string) $r->client_email)
            .' — бот сам сообщит о них клиенту и приложит PDF. Короткий ответ можно написать клиенту в '.e($this->channel())
            .' из карточки заявки, вкладка «Переписка». Отвечать на это письмо не нужно.</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}
