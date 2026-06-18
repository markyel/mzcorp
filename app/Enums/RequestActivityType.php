<?php

namespace App\Enums;

/**
 * Тип последнего значимого события по заявке (Pool колонка «Событие»).
 *
 * Каждый тип имеет:
 *  - label() / icon() — для UI
 *  - requiresAttention() — событие, после которого ход за нами
 *    (заявка должна светиться в Pool)
 *  - silencesAttention() — событие «ход передан клиенту/поставщику»,
 *    автоматически снимает существующий ClientReplied/FreshAssignment
 *
 * События не взаимоисключающие с AttentionReason — это две оси:
 *   - last_activity_type    = ЧТО последнее произошло
 *   - attention_reason      = ПОЧЕМУ заявка светится в Pool (если светится)
 */
enum RequestActivityType: string
{
    // ─────────── Требуют внимания (ход за нами) ───────────
    case RequestCreated = 'request_created';
    case Assigned = 'assigned';
    case ClientReplied = 'client_replied';
    case SupplierReplied = 'supplier_replied';
    case PostSaleMessage = 'post_sale_message';
    case Resumed = 'resumed';
    case Reanimated = 'reanimated';
    case ManualFlagSet = 'manual_flag_set';

    // ─────────── Не требуют внимания (ход за клиентом/поставщиком) ───────────
    case ManagerReplied = 'manager_replied';
    case ClarificationSent = 'clarification_sent';
    case QuoteSent = 'quote_sent';
    case InvoiceSent = 'invoice_sent';
    case SupplierInquirySent = 'supplier_inquiry_sent';
    // Цикл обновления цен (Фаза 3.5):
    case PricesActualized = 'prices_actualized';
    case AllSuppliersRefused = 'all_suppliers_refused';

    // ─────────── Нейтральные ───────────
    case Paid = 'paid';
    case ClosedWon = 'closed_won';
    case ClosedLost = 'closed_lost';
    case Paused = 'paused';
    case StatusChange = 'status_change';
    case ManualFlagCleared = 'manual_flag_cleared';

    public function label(): string
    {
        return match ($this) {
            self::RequestCreated => 'Новая',
            self::Assigned => 'Назначена',
            self::ClientReplied => 'Ответ клиента',
            self::SupplierReplied => 'Ответ поставщика',
            self::PostSaleMessage => 'Постпродажное сообщение',
            self::Resumed => 'Снята с паузы',
            self::Reanimated => 'Реанимирована',
            self::ManualFlagSet => 'Ручной флаг',

            self::ManagerReplied => 'Отправлено клиенту',
            self::ClarificationSent => 'Уточнение отправлено',
            self::QuoteSent => 'КП отправлено',
            self::InvoiceSent => 'Счёт отправлен',
            self::SupplierInquirySent => 'Запрос поставщику',
            self::PricesActualized => 'Цены актуализированы',
            self::AllSuppliersRefused => 'Поставщики отказали',

            self::Paid => 'Оплачено',
            self::ClosedWon => 'Закрыто success',
            self::ClosedLost => 'Закрыто потеря',
            self::Paused => 'На паузе',
            self::StatusChange => 'Смена статуса',
            self::ManualFlagCleared => 'Флаг снят',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::RequestCreated => '📥',
            self::Assigned => '🆕',
            self::ClientReplied => '📨',
            self::SupplierReplied => '📦',
            self::PostSaleMessage => '🛒',
            self::Resumed => '▶',
            self::Reanimated => '↻',
            self::ManualFlagSet => '🚩',

            self::ManagerReplied => '✉',
            self::ClarificationSent => '❓',
            self::QuoteSent => '💼',
            self::InvoiceSent => '🧾',
            self::SupplierInquirySent => '📦',
            self::PricesActualized => '💰',
            self::AllSuppliersRefused => '🚫',

            self::Paid => '💰',
            self::ClosedWon => '✓',
            self::ClosedLost => '⊘',
            self::Paused => '⏸',
            self::StatusChange => '↪',
            self::ManualFlagCleared => '🏳',
        };
    }

    /**
     * Событие, после которого ход за нами — заявка должна светиться в Pool
     * (используется тематически в UI; реальный attention_level ставится
     * через AttentionService).
     */
    public function requiresAttention(): bool
    {
        return match ($this) {
            self::RequestCreated,
            self::Assigned,
            self::ClientReplied,
            self::SupplierReplied,
            self::PostSaleMessage,
            self::Resumed,
            self::Reanimated,
            self::ManualFlagSet,
            self::PricesActualized,
            self::AllSuppliersRefused => true,
            default => false,
        };
    }

    /**
     * Событие «ход передан клиенту/поставщику» — автоматически снимает
     * существующий info-flag (ClientReplied / FreshAssignment). После
     * этого recompute по статусу даст обычный SlaBreach или NULL.
     */
    public function silencesAttention(): bool
    {
        return match ($this) {
            self::ManagerReplied,
            self::ClarificationSent,
            self::QuoteSent,
            self::InvoiceSent,
            self::SupplierInquirySent => true,
            default => false,
        };
    }

    /**
     * Активность по факту = переходу статуса: рендер activity-строки в UI
     * дублирует статус-чип (одинаковые лейблы / иконки). Пары:
     *   - QuoteSent        ↔ Quoted
     *   - InvoiceSent      ↔ Invoiced
     *   - Paid             ↔ Paid
     *   - ClosedWon        ↔ ClosedWon
     *   - ClosedLost       ↔ ClosedLost
     *   - Paused           ↔ Paused
     *   - ClarificationSent ↔ AwaitingClientClarification
     *   - Assigned         ↔ Assigned (при свежем назначении)
     *
     * Кейс M-2026-1525: после auto-detect счёта status=Invoiced + activity=
     * InvoiceSent → две строки «Счёт отправлен» в Pool-листе. С isRedundant
     * UI прячет нижнюю.
     */
    public function isRedundantWithStatus(?RequestStatus $status): bool
    {
        if ($status === null) {
            return false;
        }
        return match ($this) {
            self::QuoteSent => $status === RequestStatus::Quoted,
            self::InvoiceSent => $status === RequestStatus::Invoiced,
            self::Paid => $status === RequestStatus::Paid,
            self::ClosedWon => $status === RequestStatus::ClosedWon,
            self::ClosedLost => $status === RequestStatus::ClosedLost,
            self::Paused => $status === RequestStatus::Paused,
            self::ClarificationSent => $status === RequestStatus::AwaitingClientClarification,
            self::Assigned => $status === RequestStatus::Assigned,
            default => false,
        };
    }
}
