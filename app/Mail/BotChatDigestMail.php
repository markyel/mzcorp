<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Менеджеру: сообщения клиентов в Telegram/MAX, которые он не открыл за
 * несколько минут (BotChatDigestService). Одно письмо на все заявки сразу —
 * не по письму на сообщение.
 */
class BotChatDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{request: string, url: string, channel: string, client: string, messages: list<array{at: string, text: string}>}>  $threads
     */
    public function __construct(public array $threads) {}

    public function envelope(): Envelope
    {
        $count = array_sum(array_map(fn ($t) => count($t['messages']), $this->threads));
        $first = $this->threads[0] ?? null;
        $subject = count($this->threads) === 1 && $first !== null
            ? 'Сообщение клиента в '.$first['channel'].' · '.$first['request'].' — '.$first['client']
            : 'Сообщения клиентов в Telegram и MAX: '.$count.' по '.count($this->threads).' заявкам';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $button = 'display:inline-block;text-decoration:none;padding:7px 14px;border-radius:6px;font-weight:600;background:#D32027;color:#fff;font-size:13px';
        $blocks = '';
        foreach ($this->threads as $t) {
            $lines = '';
            foreach ($t['messages'] as $m) {
                $lines .= '<div style="margin:4px 0"><span style="color:#6b7280;font-size:12px">'.e($m['at']).'</span> '
                    .'<span style="white-space:pre-line">'.e($m['text']).'</span></div>';
            }
            $blocks .= '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin:12px 0">'
                .'<div style="margin-bottom:6px"><b>'.e($t['request']).'</b> · '.e($t['client']).' · <span style="color:#6b7280">'.e($t['channel']).'</span></div>'
                .'<div style="background:#f9fafb;border-radius:6px;padding:6px 10px">'.$lines.'</div>'
                .'<p style="margin:10px 0 0"><a href="'.e($t['url']).'" style="'.$button.'">Ответить в чате</a></p>'
                .'</div>';
        }

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">'
            .'<p>Клиенты написали в мессенджер, а переписку ещё не открывали:</p>'
            .$blocks
            .'<p style="color:#6b7280;font-size:12px">Кнопка открывает заявку сразу с окном переписки — ответ уйдёт клиенту в мессенджер. '
            .'Открыли переписку — сообщения считаются прочитанными, повторного письма о них не будет. Отвечать на это письмо не нужно.</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}
