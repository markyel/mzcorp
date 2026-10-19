<?php

namespace App\Mail;

use App\Models\QuotePartsRfq;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Снабжению: поставщик спрашивает в QuoteParts по позиции из своей номенклатуры (её у него не
 * запрашивали) — по позиции открылся запрос, ответить нужно в нём («Снабжение» → «Запросы через
 * QuoteParts»). Шлётся через SystemNotificationMailer каждому снабженцу.
 */
class QuotePartsItemQuestionMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $questions
     */
    public function __construct(
        public QuotePartsRfq $rfq,
        public string $supplierName,
        public array $questions,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'MyLift · Вопрос поставщика '.$this->supplierName.' по позиции '.$this->itemLabel().' '.$this->rfq->tag);
    }

    private function itemLabel(): string
    {
        $line = $this->rfq->snapshot['suppliers'][0]['lines'][0] ?? [];

        return trim(($line['code'] ?? '').' '.mb_strimwidth((string) ($line['name'] ?? ''), 0, 60, '…'));
    }

    public function content(): Content
    {
        $button = 'display:inline-block;text-decoration:none;padding:9px 16px;border-radius:6px;font-weight:600;background:#D32027;color:#fff';
        $items = implode('', array_map(fn ($q) => '<li style="margin:4px 0">'.nl2br(e($q)).'</li>', $this->questions));
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">'
            .'<p>Поставщик <b>'.e($this->supplierName).'</b> хочет предложить позицию <b>'.e($this->itemLabel()).'</b>, которую у него не запрашивали, '
            .'и сначала спрашивает:</p>'
            .($items !== '' ? '<ul style="margin:8px 0 12px;padding-left:20px">'.$items.'</ul>' : '')
            .'<p>По позиции открыт запрос <b>'.e((string) $this->rfq->tag).'</b>. Ответьте в нём — письмо уйдёт поставщику, дальше переписка и цена, как в обычном запросе.</p>'
            .'<p style="margin:14px 0"><a href="'.e(route('procurement.index')).'" style="'.$button.'">Открыть «Снабжение»</a></p>'
            .'<p style="color:#6b7280;font-size:12px">Письмо получают все снабженцы. Ответить может любой — остальные увидят ответ в запросе.</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}
