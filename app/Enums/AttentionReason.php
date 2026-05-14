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
 *  АКТИВНЫЕ ПРИЧИНЫ (минимизация 2026-05-21):
 * ─────────────────────────────────────────────────────────────────────
 *   - SlaBreach       ⚡  «Срок реакции» — единый дедлайн SLA по статусу,
 *                          истёк (overdue) → красная подсветка в Pool.
 *                          AttentionService::compute() ставит ТОЛЬКО его
 *                          для всех не-терминальных статусов с дедлайном.
 *   - PostponedResume ⏰  «Возврат после отсрочки» — наступил дедлайн
 *                          явной отсрочки (status=PostponedUntil).
 *   - ClientReplied   📨  «Ответ от клиента» — inbound клиента привязан к
 *                          активной заявке, менеджер ещё не открыл карточку.
 *                          Снимается в Detail::mount по last_seen_at.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  DEPRECATED (оставлены для совместимости с существующими записями БД,
 *  AttentionService::compute() НЕ возвращает, в Pool рендерятся как fallback):
 * ─────────────────────────────────────────────────────────────────────
 *   - AwaitingClient        — «ждём клиента» это статус, не алярм
 *   - AwaitingSupplier      — не использовался, Phase 2 reserve
 *   - QuoteFollowupDue      — нудж по КП — заменён на SlaBreach
 *   - InvoiceFollowupDue    — нудж по счёту — заменён на SlaBreach
 *   - PartialQuoteOverdue   — не использовался
 *
 * Backfill старых записей: миграция чистит deprecated reason'ы при деплое;
 * на следующем cron-tick / transition'е recompute() пересчитает по новой
 * логике.
 */
enum AttentionReason: string
{
    case SlaBreach = 'sla_breach';
    case PostponedResume = 'postponed_resume';
    case ClientReplied = 'client_replied';

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
     * ClientReplied — позитивный сигнал «есть новости», не пропущенный SLA.
     */
    public function isInfo(): bool
    {
        return $this === self::ClientReplied;
    }
}
