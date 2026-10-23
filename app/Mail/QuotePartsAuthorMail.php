<?php

namespace App\Mail;

use App\Models\QuotePartsRfq;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Автору запроса через QuoteParts — когда нужна его реакция: поставщик просит уточнить (question)
 * или цены собраны и можно выбирать предложение (ready). Ссылка — туда, откуда запрос отправлен:
 * из заявки — во вкладку «Поставщики» заявки, из «Снабжения» — в раздел QuoteParts
 * (QuotePartsRfq::authorLink). Шлётся через SystemNotificationMailer.
 */
class QuotePartsAuthorMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  'question'|'ready'  $kind
     * @param  list<string>  $questions
     * @param  list<array<string, mixed>>  $positions  QuotePartsRfqReadiness::positions()
     */
    public function __construct(
        public QuotePartsRfq $rfq,
        public string $kind,
        public ?string $supplierName = null,
        public array $questions = [],
        public array $positions = [],
    ) {}

    /** @param  list<string>  $questions */
    public static function question(QuotePartsRfq $rfq, ?string $supplierName, array $questions): self
    {
        return new self($rfq, 'question', $supplierName, $questions);
    }

    /** @param  array<string, array<string, mixed>>  $positions */
    public static function ready(QuotePartsRfq $rfq, array $positions): self
    {
        return new self($rfq, 'ready', positions: array_values($positions));
    }

    public function envelope(): Envelope
    {
        $where = trim(($this->rfq->request?->internal_code ? $this->rfq->request->internal_code.' · ' : '').$this->rfq->tag);

        return new Envelope(subject: $this->kind === 'question'
            ? 'MyLift · Поставщик '.($this->supplierName ?: '').' просит уточнить · '.$where
            : 'MyLift · Цены собраны, можно выбирать · '.$where);
    }

    public function content(): Content
    {
        $button = 'display:inline-block;text-decoration:none;padding:9px 16px;border-radius:6px;font-weight:600;background:#D32027;color:#fff';
        $muted = 'color:#6b7280;font-size:12px';
        $code = $this->rfq->request?->internal_code;
        $about = $code ? 'по заявке <b>'.e($code).'</b> (запрос <b>'.e((string) $this->rfq->tag).'</b>)' : 'по запросу <b>'.e((string) $this->rfq->tag).'</b>';
        $link = $this->rfq->authorLink($this->kind === 'question' ? 'question' : 'offers');
        $where = $this->rfq->request_id ? 'Открыть заявку — «Поставщики»' : ($this->kind === 'question' ? 'Открыть «Вопросы поставщиков»' : 'Открыть «Актуализацию цен»');

        if ($this->kind === 'question') {
            $items = implode('', array_map(fn ($q) => '<li style="margin:4px 0">'.nl2br(e($q)).'</li>', $this->questions));
            $body = '<p>Поставщик <b>'.e($this->supplierName ?: 'поставщик').'</b> '.$about.' просит уточнить:</p>'
                .($items !== '' ? '<ul style="margin:8px 0 12px;padding-left:20px">'.$items.'</ul>' : '')
                .'<p>Пока нет ответа, цену он не даст. Ответьте в запросе — письмо уйдёт поставщику.</p>';
        } else {
            $rows = '';
            foreach ($this->positions as $p) {
                $best = $p['best'] ?? null;
                $rows .= '<tr>'
                    .'<td style="padding:4px 10px 4px 0;white-space:nowrap">'.e((string) ($p['code'] ?? '')).'</td>'
                    .'<td style="padding:4px 10px 4px 0">'.e(mb_strimwidth((string) ($p['name'] ?? ''), 0, 70, '…')).'</td>'
                    .'<td style="padding:4px 10px 4px 0;white-space:nowrap">цен: '.count($p['offered'] ?? []).' из '.count($p['asked'] ?? []).'</td>'
                    .'<td style="padding:4px 0;white-space:nowrap">'.($best ? 'от '.number_format((float) $best['price'], 2, ',', ' ').' '.e($best['currency']) : '—').'</td>'
                    .'</tr>';
            }
            $body = '<p>Поставщики дали цены '.$about.' — можно выбирать предложение.</p>'
                .($rows !== '' ? '<table style="border-collapse:collapse;font-size:13px;margin:8px 0 12px">'.$rows.'</table>' : '');
        }

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">'
            .$body
            .'<p style="margin:14px 0"><a href="'.e($link).'" style="'.$button.'">'.e($where).'</a></p>'
            .'<p style="'.$muted.'">Письмо получает автор запроса.</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}
