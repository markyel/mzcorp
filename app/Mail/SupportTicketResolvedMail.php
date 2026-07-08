<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Автору обращения: вопрос решён (админ перевёл тикет в resolved/closed).
 * Дублирует исходный вопрос и ответы создателя — все ещё не отправленные
 * почтой (дайджест-логика, см. SupportTicketService::changeStatus), либо
 * последний ответ, если все уже уходили.
 *
 * @property Collection<int, \App\Models\SupportTicketMessage> $answers
 */
class SupportTicketResolvedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportTicket $ticket,
        public Collection $answers,
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
        $this->answers->each(fn ($m) => $m->loadMissing('author'));

        return new Content(
            markdown: 'mail.support.ticket-resolved',
            with: [
                'ticket' => $this->ticket,
                'answers' => $this->answers,
                'questionBody' => trim((string) $this->ticket->body) ?: null,
                'url' => route('support.show', $this->ticket),
            ],
        );
    }
}
