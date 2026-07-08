<?php

namespace App\Mail;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Автору обращения: вопрос решён (админ перевёл тикет в resolved/closed).
 * Дублирует исходный вопрос и последний ответ создателя.
 */
class SupportTicketResolvedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportTicket $ticket,
        public ?SupportTicketMessage $lastAnswer = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'MyLift · Обращение #'.$this->ticket->id.' решено — '.$this->ticket->subject,
        );
    }

    public function content(): Content
    {
        $this->lastAnswer?->loadMissing('author');

        return new Content(
            markdown: 'mail.support.ticket-resolved',
            with: [
                'ticket' => $this->ticket,
                'answer' => $this->lastAnswer,
                'questionBody' => trim((string) $this->ticket->body) ?: null,
                'url' => route('support.show', $this->ticket),
            ],
        );
    }
}
