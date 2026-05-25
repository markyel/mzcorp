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

        return new Content(
            markdown: 'mail.support.ticket-reply',
            with: [
                'ticket' => $this->ticket,
                'message' => $this->message,
                'author' => $this->message->author,
                'url' => route('support.show', $this->ticket),
            ],
        );
    }
}
