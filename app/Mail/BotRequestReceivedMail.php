<?php

namespace App\Mail;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Клиенту бота: заявка принята. У заявки из бота нет письма-источника, а
 * обычное «заявка принята» уходит ответом в ветку письма — поэтому отдельное
 * письмо. Менеджер дальше пишет клиенту на этот же адрес (КП, счёт).
 */
class BotRequestReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Request $request, public string $messenger) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Заявка '.$this->request->internal_code.' принята — MyZip');
    }

    public function content(): Content
    {
        $r = $this->request;
        $items = $r->items->where('is_active', true)->sortBy('position')->take(30)
            ->map(fn ($i) => '<li>'.e((string) $i->parsed_name)
                .($i->parsed_article && ! str_contains((string) $i->parsed_name, (string) $i->parsed_article) ? ' · '.e((string) $i->parsed_article) : '')
                .' — '.e(rtrim(rtrim(number_format((float) $i->parsed_qty, 2, ',', ' '), '0'), ',')).' '.e((string) ($i->parsed_unit ?: 'шт.')).'</li>')
            ->implode('');
        $name = trim((string) $r->client_name);

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">'
            .'<p>Здравствуйте'.($name !== '' ? ', '.e($name) : '').'!</p>'
            .'<p>Ваша заявка из '.e($this->messenger).' принята, номер <b>'.e((string) $r->internal_code).'</b>.</p>'
            .($items !== '' ? '<p>Позиции:</p><ul>'.$items.'</ul>' : '')
            .'<p>Менеджер подготовит предложение и пришлёт его на этот адрес. '
            .'На вопросы по заявке можно отвечать на это письмо или писать в бот.</p>'
            .'<p>С уважением,<br>MyZip — запчасти для лифтов и эскалаторов</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}
