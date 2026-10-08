<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\OutboundQuote;
use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Менеджеру: в его КП/счёте покупателю «закупка + наценка» цена выше
 * режимной. Шлётся через SystemNotificationMailer. См. CostPlusPriceGuard.
 */
class CostPlusOverpriceMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{sku: string, name: string, qty: float, price: float, expected: float, purchase: float}>  $lines
     */
    public function __construct(
        public OutboundQuote $quote,
        public Request $request,
        public Organization $organization,
        public array $lines,
        public float $markup,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('MyLift · Цена выше «закупка + %s%%» в %s по заявке %s (%s)',
                $this->num($this->markup, 0), $this->documentWord() === 'счёт' ? 'счёте' : 'КП', $this->request->internal_code, $this->organization->name),
        );
    }

    public function content(): Content
    {
        $money = fn (float $v) => number_format($v, 2, ',', "\u{00A0}").' ₽';
        $overTotal = array_sum(array_map(fn ($l) => ($l['price'] - $l['expected']) * $l['qty'], $this->lines));
        $cell = 'padding:6px 10px;border-bottom:1px solid #e5e7eb;';
        $num = $cell.'text-align:right;white-space:nowrap;';

        $rows = '';
        foreach ($this->lines as $l) {
            $rows .= '<tr>'
                .'<td style="'.$cell.'font-family:monospace">'.e($l['sku']).'</td>'
                .'<td style="'.$cell.'">'.e(mb_strimwidth($l['name'], 0, 70, '…')).'</td>'
                .'<td style="'.$num.'">'.e($this->num($l['qty'], 3)).'</td>'
                .'<td style="'.$num.'color:#b91c1c"><b>'.e($money($l['price'])).'</b></td>'
                .'<td style="'.$num.'">'.e($money($l['expected'])).'</td>'
                .'</tr>';
        }

        $doc = trim(('№'.$this->quote->document_number).($this->quote->document_date ? ' от '.$this->quote->document_date->format('d.m.Y') : ''), '№ ');
        $url = route('requests.show', $this->request->id);
        $button = 'display:inline-block;text-decoration:none;padding:9px 16px;border-radius:6px;font-weight:600;background:#D32027;color:#fff';

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">'
            // «КП» — среднего рода: «ушло КП», «исправленное КП»; «счёт» — мужского.
            .'<p>По заявке <b>'.e($this->request->internal_code).'</b> для <b>'.e($this->organization->name).'</b> '
            .($this->isInvoice() ? 'ушёл' : 'ушло').' '.e($this->documentWord()).' '.e($doc).'. У этого покупателя цена — <b>закупка + '.e($this->num($this->markup, 0)).'%</b>, '
            .'без скидок от каталога. В документе цена выше:</p>'
            .'<table style="border-collapse:collapse;font-size:13px;margin:8px 0 12px">'
            .'<tr style="color:#6b7280;font-size:12px"><th style="'.$cell.'text-align:left">Артикул</th><th style="'.$cell.'text-align:left">Позиция</th>'
            .'<th style="'.$num.'">Кол-во</th><th style="'.$num.'">В документе</th><th style="'.$num.'">Должно быть</th></tr>'
            .$rows
            .'</table>'
            .'<p>Разница по документу: <b>'.e($money($overTotal)).'</b>.</p>'
            .'<p>Пересчитайте цены и вышлите клиенту '.($this->isInvoice() ? 'исправленный' : 'исправленное').' '
            .e($this->documentWord()).', пока он не ответил.</p>'
            .'<p style="margin:14px 0"><a href="'.e($url).'" style="'.$button.'">Открыть заявку</a></p>'
            .'<p style="color:#6b7280;font-size:12px">Цена «должно быть» — закупочная из каталога на сегодня плюс '
            .e($this->num($this->markup, 0)).'%. Если закупка изменилась, а каталог ещё нет, — сверьтесь с 1С.</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }

    private function documentWord(): string
    {
        return $this->isInvoice() ? 'счёт' : 'КП';
    }

    private function isInvoice(): bool
    {
        return $this->quote->document_type === \App\Enums\DetectorType::OutboundInvoice;
    }

    private function num(float $v, int $decimals): string
    {
        $s = number_format($v, $decimals, ',', "\u{00A0}");

        return $decimals > 0 ? rtrim(rtrim($s, '0'), ',') : $s;
    }
}
