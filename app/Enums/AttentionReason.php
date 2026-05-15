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
 * ─────────────────────────────────────────────────────────────────────
 *  АКТИВНЫЕ ПРИЧИНЫ:
 * ─────────────────────────────────────────────────────────────────────
 *   - SlaBreach       ⚡  «Срок реакции» — единый дедлайн SLA по статусу,
 *                          истёк (overdue) → красная подсветка в Pool.
 *                          AttentionService::compute() ставит ТОЛЬКО его
 *                          для всех не-терминальных статусов с дедлайном.
 *   - PostponedResume ⏰  «Возврат после отсрочки» — наступил дедлайн
 *                          явной отсрочки (status=PostponedUntil).
 *   - ClientReplied   📨  «Ответ от клиента» — inbound клиента привязан к
 *                          активной заявке, менеджер ещё не открыл карточку.
 *                          Снимается в Detail::mount по onManagerOpened.
 *   - FreshAssignment 🆕  «Новая заявка» — auto-assigned менеджеру, ещё не
 *                          открывал. info-уровень. Снимается onManagerOpened.
 *   - Manual          🚩  «Ручной флаг» — менеджер/РОП явно поставил «вернуть
 *                          в фокус». Самый сильный: НЕ затирается recompute()
 *                          / onClientReplied / onManagerOpened. Снимается
 *                          только явным `clearManual()`.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  DEPRECATED (оставлены для совместимости с существующими записями БД):
 * ─────────────────────────────────────────────────────────────────────
 *   - AwaitingClient / AwaitingSupplier / QuoteFollowupDue /
 *     InvoiceFollowupDue / PartialQuoteOverdue — не возвращаются compute().
 */
enum AttentionReason: string
{
    case SlaBreach = 'sla_breach';
    case PostponedResume = 'postponed_resume';
    case ClientReplied = 'client_replied';
    case FreshAssignment = 'fresh_assignment';
    case Manual = 'manual';

    // ──────────── deprecated, не возвращаются compute() ────────────
    case AwaitingClient = 'awaiting_client';
    case AwaitingSupplier = 'awaiting_supplier';
    case QuoteFollowupDue = 'quote_followup_due';
    case InvoiceFollowupDue = 'invoice_followup_due';
    case PartialQuoteOverdue = 'partial_quote_overdue';

    public function label(): string
    {
        return match ($this) {
            self::SlaBreach => 'Срок реакции',
            self::PostponedResume => 'Возврат после отсрочки',
            self::ClientReplied => 'Ответ от клиента',
            self::FreshAssignment => 'Новая заявка',
            self::Manual => 'Ручной флаг',
            // legacy
            self::AwaitingClient => 'Жду клиента',
            self::AwaitingSupplier => 'Жду поставщика',
            self::QuoteFollowupDue => 'Нудж по КП',
            self::InvoiceFollowupDue => 'Нудж по счёту',
            self::PartialQuoteOverdue => 'Допослать частичное КП',
        };
    }

    /**
     * Короткая иконка-эмодзи для бейджа в Pool.
     */
    public function icon(): string
    {
        return match ($this) {
            self::SlaBreach => '⚡',
            self::PostponedResume => '⏰',
            self::ClientReplied => '📨',
            self::FreshAssignment => '🆕',
            self::Manual => '🚩',
            // legacy
            self::AwaitingClient => '👤',
            self::AwaitingSupplier => '📦',
            self::QuoteFollowupDue => '💬',
            self::InvoiceFollowupDue => '🧾',
            self::PartialQuoteOverdue => '🧩',
        };
    }

    /**
     * Информационный (sky / amber) тон, а не алярмический (red).
     * Только SlaBreach с истёкшим дедлайном — алярм (red).
     */
    public function isInfo(): bool
    {
        return match ($this) {
            self::ClientReplied, self::FreshAssignment, self::Manual, self::PostponedResume => true,
            default => false,
        };
    }

    /**
     * Manual flag — самый сильный, НЕ затирается обычным recompute /
     * onClientReplied / onManagerOpened. Только явный clearManual().
     */
    public function isSticky(): bool
    {
        return $this === self::Manual;
    }
}
