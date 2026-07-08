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
 * Дайджест новых сообщений по обращению — ОДНО письмо на пачку ответов
 * вместо письма на каждый комментарий (жалоба по тикету #70: 3 почти
 * одинаковых письма подряд).
 *
 * Собирается кроном support:email-pending-replies из сообщений с
 * emailed_at IS NULL. $toAuthor = true — письмо автору обращения с
 * ответами создателя; false — разработчику с репликами автора.
 *
 * @property Collection<int, \App\Models\SupportTicketMessage> $messages
 */
class SupportTicketDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportTicket $ticket,
        public Collection $messages,
        public bool $toAuthor = true,
    ) {
    }

    public function envelope(): Envelope
    {
        $count = $this->messages->count();
        $label = $count > 1 ? sprintf(' (%d)', $count) : '';

        return new Envelope(
            subject: sprintf(
                'MyLift · %s по обращению #%d%s — %s',
                $this->toAuthor ? 'Ответ' : 'Сообщение',
                $this->ticket->id,
                $label,
                $this->ticket->subject,
            ),
        );
    }

    public function content(): Content
    {
        $this->messages->each(fn ($m) => $m->loadMissing(['author', 'attachments']));

        return new Content(
            markdown: 'mail.support.ticket-digest',
            with: [
                'ticket' => $this->ticket,
                'messages' => $this->messages,
                'toAuthor' => $this->toAuthor,
                'questionBody' => trim((string) $this->ticket->body) ?: null,
                'url' => route('support.show', $this->ticket),
            ],
        );
    }
}
