<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app уведомление снабжению: в QuoteParts пришло письмо поставщика, которое платформа не привязала
 * к запросу сама (очередь «Не разобрано»). kind=quoteparts_letter; клик — раздел «QuoteParts» →
 * «Письма без запроса», где письмо привязывают к поставщику запроса или отклоняют.
 */
class QuotePartsInboundLetterNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly int $letterId, public readonly ?string $from, public readonly ?string $subject) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'quoteparts_letter',
            'letter_id' => $this->letterId,
            'from' => $this->from ? mb_substr($this->from, 0, 120) : null,
            'subject' => $this->subject ? mb_substr($this->subject, 0, 160) : null,
        ];
    }
}
