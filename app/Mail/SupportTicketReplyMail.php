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

        // Исходный вопрос — дублируем в письме, чтобы спрашивающий видел
        // контекст без перехода в CRM. Первичный текст вопроса живёт в
        // support_tickets.body (сообщения — только последующие реплики).
        $firstMessage = $this->ticket->messages()
            ->where('is_internal', false)
            ->orderBy('id')
            ->first();
        $questionBody = trim((string) $this->ticket->body);
        $questionAt = $this->ticket->created_at;
        if ($questionBody === '' && $firstMessage !== null && $firstMessage->id !== $this->message->id) {
            $questionBody = trim((string) $firstMessage->body);
            $questionAt = $firstMessage->created_at;
        }

        return new Content(
            markdown: 'mail.support.ticket-reply',
            with: [
                'ticket' => $this->ticket,
                'message' => $this->message,
                'author' => $this->message->author,
                'questionBody' => $questionBody !== '' ? $questionBody : null,
                'questionAt' => $questionAt,
                'url' => route('support.show', $this->ticket),
            ],
        );
    }
}
