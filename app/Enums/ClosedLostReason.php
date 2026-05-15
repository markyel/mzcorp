<?php

namespace App\Enums;

/**
 * Причины закрытия заявки как closed_lost (Foundation §5.2).
 *
 * Хранится в `requests.closed_lost_reason`. Для дашбордной аналитики
 * РОПа («почему мы теряем заявки»). `*_other` — требуют ручной комментарий
 * оператора в `closed_lost_comment`.
 */
enum ClosedLostReason: string
{
    case OffTopic = 'off_topic';
    case NoClientResponseToClarification = 'no_client_response_to_clarification';
    case NoClientResponseToQuote = 'no_client_response_to_quote';
    case ClientDeclinedPrice = 'client_declined_price';
    case ClientDeclinedTiming = 'client_declined_timing';
    case ClientDeclinedCompetitor = 'client_declined_competitor';
    case ClientDeclinedOther = 'client_declined_other';
    case WeCantOffer = 'we_cant_offer';
    case InvoiceUnpaid = 'invoice_unpaid';
    case InvoiceCancelled = 'invoice_cancelled';
    case ManualOther = 'manual_other';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::OffTopic => 'Не наша тематика',
            self::NoClientResponseToClarification => 'Клиент молчит после уточнения',
            self::NoClientResponseToQuote => 'Клиент молчит после КП',
            self::ClientDeclinedPrice => 'Клиент отказ: дорого',
            self::ClientDeclinedTiming => 'Клиент отказ: долго',
            self::ClientDeclinedCompetitor => 'Клиент отказ: выбрал конкурента',
            self::ClientDeclinedOther => 'Клиент отказ: другая причина',
            self::WeCantOffer => 'Мы не можем предложить',
            self::InvoiceUnpaid => 'Счёт не оплачен в срок',
            self::InvoiceCancelled => 'Счёт отменён',
            self::ManualOther => 'Закрыто РОПом вручную',
            self::Duplicate => 'Дубликат — слита с другой заявкой',
        };
    }

    /**
     * Требует ли причина обязательного `closed_lost_comment` (свободный текст).
     */
    public function requiresComment(): bool
    {
        return in_array($this, [
            self::ClientDeclinedOther,
            self::ManualOther,
        ], true);
    }
}
