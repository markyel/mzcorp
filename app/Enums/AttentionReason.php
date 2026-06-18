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
 *   - PostSale        🛒  «Постпродажа» — клиент написал по уже оформленному
 *                          заказу (платёжка, доставка/отгрузка, сертификаты,
 *                          закрывающие документы). Ставится на «оплаченные/
 *                          закрытые» статусы (awaiting_invoice / invoiced /
 *                          paid / closed_won); для paid/closed_won — обход
 *                          silentStatuses. Заявку не реанимирует.
 *                          Снимается onManagerOpened.
 *   - Manual          🚩  «Ручной флаг» — менеджер/РОП явно поставил «вернуть
 *                          в фокус». Самый сильный: НЕ затирается recompute()
 *                          / onClientReplied / onManagerOpened. Снимается
 *                          только явным `clearManual()`.
 *   - PricesActualized 💰 «Цены актуализированы» — по заявке обновились цены
 *                          всех отслеживаемых позиций (PriceRefreshReconciler).
 *                          info-уровень. Снимается onManagerOpened / QuoteSent.
 *   - AllSuppliersRefused 🚫 «Поставщики отказали» — по всем отслеживаемым
 *                          позициям только отказы. info-уровень. Снимается
 *                          onManagerOpened.
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
    case SupplierReplied = 'supplier_replied';
    case PostSale = 'post_sale';
    // Цикл обновления цен (Фаза 3.5):
    case PricesActualized = 'prices_actualized';
    case AllSuppliersRefused = 'all_suppliers_refused';

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
            self::SupplierReplied => 'Ответ поставщика',
            self::PostSale => 'Постпродажа',
            self::PricesActualized => 'Цены актуализированы',
            self::AllSuppliersRefused => 'Поставщики отказали',
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
            self::SupplierReplied => '📦',
            self::PostSale => '🛒',
            self::PricesActualized => '💰',
            self::AllSuppliersRefused => '🚫',
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
            self::ClientReplied,
            self::FreshAssignment,
            self::Manual,
            self::PostponedResume,
            self::SupplierReplied,
            self::PostSale,
            self::PricesActualized,
            self::AllSuppliersRefused => true,
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
