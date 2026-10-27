<?php

namespace App\Notifications;

use App\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app уведомление: поставщик написал в обращении через QuoteParts (вне запросов цен).
 * kind=quoteparts_conversation; клик ведёт в карточку поставщика, к «Обращениям», а письмо на
 * support@ (topic=support) — на вкладку QuoteParts «Вопросы», где на него отвечают.
 */
class QuotePartsConversationNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly array $payload, public readonly ?Supplier $supplier) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'quoteparts_conversation',
            'conversation_id' => $this->payload['conversation_id'] ?? null,
            'tag' => $this->payload['tag'] ?? null,
            'subject' => mb_substr((string) ($this->payload['subject'] ?? ''), 0, 200),
            'started_by' => $this->payload['started_by'] ?? null,
            'supplier_id' => $this->supplier?->id,
            // support@: писать может и не поставщик — тогда подпись из письма.
            'topic' => $this->payload['topic'] ?? null,
            'supplier' => $this->supplier
                ? mb_substr((string) ($this->supplier->organization?->name ?: $this->supplier->name ?: $this->supplier->email), 0, 120)
                : (mb_substr((string) (($this->payload['contact_name'] ?? null) ?: ($this->payload['contact_email'] ?? '')), 0, 120) ?: null),
            'preview' => mb_substr((string) ($this->payload['preview'] ?? ''), 0, 160),
        ];
    }
}
