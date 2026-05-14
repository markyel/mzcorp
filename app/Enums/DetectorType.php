<?php

namespace App\Enums;

/**
 * Тип события, который распознал DocumentDetector (Foundation §7).
 *
 * Делится на outbound (мы что-то отправили клиенту) и inbound
 * (клиент нам что-то ответил). Каждое значение однозначно отображается
 * на target RequestStatus (см. ::targetStatus()).
 */
enum DetectorType: string
{
    // ─── outbound: отправлено менеджером ──────────────────
    case OutboundQuotationFull = 'outbound_quotation_full';
    case OutboundQuotationPartial = 'outbound_quotation_partial';
    case OutboundInvoice = 'outbound_invoice';
    case OutboundClarification = 'outbound_clarification';

    // ─── inbound: ответ клиента после КП ─────────────────
    case InboundUnderReview = 'inbound_under_review';
    case InboundPostponed = 'inbound_postponed';
    case InboundInvoiceRequest = 'inbound_invoice_request';
    case InboundDecline = 'inbound_decline';
    case InboundClarificationResponse = 'inbound_clarification_response';
    case InboundUnclear = 'inbound_unclear';

    public function label(): string
    {
        return match ($this) {
            self::OutboundQuotationFull => 'Отправлено КП',
            self::OutboundQuotationPartial => 'Отправлено частичное КП',
            self::OutboundInvoice => 'Отправлен счёт',
            self::OutboundClarification => 'Запрос уточнения клиенту',
            self::InboundUnderReview => 'Клиент: на согласовании',
            self::InboundPostponed => 'Клиент: отложил',
            self::InboundInvoiceRequest => 'Клиент: запросил счёт',
            self::InboundDecline => 'Клиент: отказ',
            self::InboundClarificationResponse => 'Клиент: ответ на уточнение',
            self::InboundUnclear => 'Клиент: непонятно',
        };
    }

    public function isOutbound(): bool
    {
        return str_starts_with($this->value, 'outbound_');
    }

    public function isInbound(): bool
    {
        return str_starts_with($this->value, 'inbound_');
    }

    /**
     * На какой RequestStatus переводим заявку при apply.
     * NULL = AI не предлагает конкретный переход (например inbound_unclear —
     * только алерт менеджеру, без auto-перехода).
     */
    public function targetStatus(): ?RequestStatus
    {
        return match ($this) {
            self::OutboundQuotationFull => RequestStatus::Quoted,
            self::OutboundQuotationPartial => RequestStatus::Quoted, // partial-флаг — Phase позже
            self::OutboundInvoice => RequestStatus::Invoiced,
            self::OutboundClarification => RequestStatus::AwaitingClientClarification,
            self::InboundUnderReview => RequestStatus::UnderReview,
            self::InboundPostponed => RequestStatus::PostponedUntil,
            self::InboundInvoiceRequest => RequestStatus::AwaitingInvoice,
            self::InboundDecline => RequestStatus::ClosedLost,
            self::InboundClarificationResponse => RequestStatus::InProgress,
            self::InboundUnclear => null,
        };
    }
}
