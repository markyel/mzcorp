<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportTicketCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket)
    {
    }

    public function envelope(): Envelope
    {
        $code = '#' . $this->ticket->id;
        return new Envelope(
            subject: 'MyLift · Новый тикет ' . $code . ' — ' . $this->ticket->subject,
        );
    }

    public function content(): Content
    {
        $this->ticket->loadMissing(['user', 'initialAttachments']);

        return new Content(
            markdown: 'mail.support.ticket-created',
            with: [
                'ticket' => $this->ticket,
                'author' => $this->ticket->user,
                'ctx' => $this->ticket->context ?? [],
                'url' => route('support.show', $this->ticket),
            ],
        );
    }
}
