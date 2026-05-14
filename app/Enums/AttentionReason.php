<?php

namespace App\Enums;

/**
 * Причина, по которой заявка должна снова попасть в фокус менеджера
 * (Foundation §5.3).
 *
 * Идёт парой с `requests.attention_required_at`: если `_at` есть — `reason`
 * обязан быть валидным значением. NULL только когда `_at = NULL`
 * (терминал / paused / Paid).
 *
 * Phase 1.11 активно используются: awaiting_client, quote_followup_due,
 * invoice_followup_due, postponed_resume, sla_breach.
 * Phase 2-резерв: awaiting_supplier, partial_quote_overdue.
 */
enum AttentionReason: string
{
    case AwaitingClient = 'awaiting_client';
    case AwaitingSupplier = 'awaiting_supplier';
    case QuoteFollowupDue = 'quote_followup_due';
    case InvoiceFollowupDue = 'invoice_followup_due';
    case PostponedResume = 'postponed_resume';
    case PartialQuoteOverdue = 'partial_quote_overdue';
    case SlaBreach = 'sla_breach';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingClient => 'Жду клиента',
            self::AwaitingSupplier => 'Жду поставщика',
            self::QuoteFollowupDue => 'Нудж по КП',
            self::InvoiceFollowupDue => 'Нудж по счёту',
            self::PostponedResume => 'Возврат после отсрочки',
            self::PartialQuoteOverdue => 'Допослать частичное КП',
            self::SlaBreach => 'Срок реакции',
        };
    }

    /**
     * Короткая иконка-эмодзи для бейджа в Pool (без зависимости от глифов
     * design tokens — это статус-маркер, не chip).
     */
    public function icon(): string
    {
        return match ($this) {
            self::AwaitingClient => '👤',
            self::AwaitingSupplier => '📦',
            self::QuoteFollowupDue => '💬',
            self::InvoiceFollowupDue => '🧾',
            self::PostponedResume => '⏰',
            self::PartialQuoteOverdue => '🧩',
            self::SlaBreach => '⚡',
        };
    }
}
