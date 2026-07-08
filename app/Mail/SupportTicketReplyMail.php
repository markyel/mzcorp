<?php

namespace App\Mail;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportTicketReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportTicket $ticket,
        public SupportTicketMessage $message,
    ) {
    }

    public function envelope(): Envelope
    {
        $code = '#' . $this->ticket->id;
        return new Envelope(
            subject: 'MyLift · Ответ по тикету ' . $code . ' — ' . $this->ticket->subject,
        );
    }

    public function content(): Content
    {
        $this->message->loadMissing(['author']);

        // Исходный вопрос (первое видимое сообщение тикета) — дублируем в
        // письме, чтобы спрашивающий видел контекст без перехода в CRM.
        $question = $this->ticket->messages()
            ->where('is_internal', false)
            ->orderBy('id')
            ->first();

        return new Content(
            markdown: 'mail.support.ticket-reply',
            with: [
                'ticket' => $this->ticket,
                'message' => $this->message,
                'author' => $this->message->author,
                'question' => ($question !== null && $question->id !== $this->message->id) ? $question : null,
                'url' => route('support.show', $this->ticket),
            ],
        );
    }
}
