<?php

namespace App\Notifications;

use App\Models\QuotePartsRfq;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app уведомление автору запроса через QuoteParts: поставщик ответил (формой или
 * письмом) или задал вопрос. kind=quoteparts_reply / quoteparts_question показывается в
 * bell-dropdown; клик ведёт в заявку, а запрос из «Снабжения» — в «Снабжение».
 */
class QuotePartsSupplierNotification extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $questions
     */
    public function __construct(
        public readonly QuotePartsRfq $rfq,
        public readonly string $event,
        public readonly ?string $supplierName,
        public readonly array $questions = [],
    ) {}

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
            'kind' => $this->event === 'supplier.question' ? 'quoteparts_question' : 'quoteparts_reply',
            'quoteparts_rfq_id' => $this->rfq->id,
            'tag' => $this->rfq->tag,
            'request_id' => $this->rfq->request_id,
            'internal_code' => $this->rfq->request?->internal_code,
            'supplier' => $this->supplierName ? mb_substr($this->supplierName, 0, 120) : null,
            'preview' => mb_substr(implode(' · ', $this->questions), 0, 160),
        ];
    }
}
