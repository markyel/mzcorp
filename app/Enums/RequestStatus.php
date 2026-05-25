<?php

namespace App\Enums;

/**
 * Статус заявки (Foundation §5.2).
 *
 * Phase 1.10: minimal manual state-machine. Авто-переходы (DocumentDetector,
 * client-response classifier, scheduler-таймауты) — Phase 4.
 *
 * Жизненный цикл:
 *   pending → new → assigned → in_progress → ...
 *     ├─→ awaiting_client_clarification (вопрос клиенту)
 *     ├─→ quoted (КП ушло)
 *     │      ├─→ under_review (клиент думает)
 *     │      ├─→ postponed_until (клиент отложил)
 *     │      ├─→ awaiting_invoice → invoiced → paid → closed_won
 *     │      ├─→ closed_won
 *     │      └─→ closed_lost (+ reason)
 *     └─→ closed_lost (отказ без КП)
 *
 *   paused — мета-статус (заморозка с возвратом в paused_from_status).
 */
enum RequestStatus: string
{
    case Pending = 'pending';
    case New = 'new';
    case Assigned = 'assigned';

    // Phase 1.10 — ручные ↓
    case InProgress = 'in_progress';
    case AwaitingClientClarification = 'awaiting_client_clarification';
    case Quoted = 'quoted';
    case UnderReview = 'under_review';
    case PostponedUntil = 'postponed_until';
    case AwaitingInvoice = 'awaiting_invoice';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
    case Paused = 'paused';
    case ClosedWon = 'closed_won';
    case ClosedLost = 'closed_lost';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'В обработке',
            self::New => 'Новая',
            self::Assigned => 'Назначена',
            self::InProgress => 'В работе',
            self::AwaitingClientClarification => 'Жду клиента',
            self::Quoted => 'КП отправлено',
            self::UnderReview => 'На согласовании',
            self::PostponedUntil => 'Отложена',
            self::AwaitingInvoice => 'Согласован / ждёт счёт',
            self::Invoiced => 'Счёт отправлен',
            self::Paid => 'Оплачено',
            self::Paused => 'На паузе',
            self::ClosedWon => 'Закрыто · успех',
            self::ClosedLost => 'Закрыто · потеря',
        };
    }

    /**
     * CSS-класс для chip'а статуса в hero-блоке (design tokens):
     *   chip-attn (красный) / chip-info (синий) / chip-ok (зелёный)
     *   chip-warn (янтарный) / chip-paused (серый) / chip-danger (красный)
     *   chip-success (зелёный) / chip-neutral (серый)
     */
    public function chipClass(): string
    {
        return match ($this) {
            self::Pending => 'chip-paused',
            self::New => 'chip-attn',
            self::Assigned => 'chip-info',
            self::InProgress => 'chip-info',
            self::AwaitingClientClarification => 'chip-warn',
            self::Quoted => 'chip-ok',
            self::UnderReview => 'chip-warn',
            self::PostponedUntil => 'chip-warn',
            self::AwaitingInvoice => 'chip-warn',
            self::Invoiced => 'chip-info',
            self::Paid => 'chip-ok',
            self::Paused => 'chip-paused',
            self::ClosedWon => 'chip-ok',
            self::ClosedLost => 'chip-danger',
        };
    }

    /** Терминальный статус — после него нет переходов в активный workflow. */
    public function isTerminal(): bool
    {
        return $this === self::ClosedWon || $this === self::ClosedLost;
    }

    /**
     * Считается ли заявка «в работе» для целей AssignmentService:
     *  - load-counter (нагрузка менеджера)
     *  - sticky lookup
     *
     * Excludes: Pending (не назначена), Paused (заморожена), Closed* (терминал).
     */
    public function isOpenForAssignment(): bool
    {
        return ! in_array($this, [
            self::Pending,
            self::Paused,
            self::ClosedWon,
            self::ClosedLost,
        ], true);
    }

    /**
     * Видна ли заявка менеджеру в пуле. Pending скрыт — менеджеру не с чем
     * работать (парсер ещё не отработал). Все остальные видны.
     */
    public function isVisibleToManager(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Порядковый номер в lifecycle для peak_status / display.
     *
     * Семантика: peak_status трекает только РЕАЛЬНЫЕ ВЕХИ (Quoted+) — после
     * которых заявка содержательно продвинулась по pipeline. Pre-КП этапы
     * (Pending / New / Assigned / InProgress) и БЛОКИРУЮЩИЕ состояния
     * (AwaitingClientClarification — «менеджер ждёт ответа клиента»,
     * Paused, PostponedUntil) — НЕ милстоуны, peak не сдвигают (-1).
     *
     * Bug history (2026-05-22 М-2026-1488): прежде AwaitingClientClarification
     * имела order=4 > InProgress=3 → peak задирался при отправке вопроса,
     * и даже после ответа клиента (current=InProgress) displayed показывало
     * «Жду клиента», потому что peak=AwaitingClientClarification «застревал».
     *
     * Правильное поведение:
     *  - До Quoted: peak=null, displayed = current operational.
     *  - На/после Quoted: peak=Quoted; даже если current=AwaitingClient
     *    Clarification (клиент уточняет КП) — displayed=Quoted (заявка УЖЕ
     *    на milestone «КП отправлено», ожидание ответа — это под-состояние).
     *  - Terminal: ClosedLost=-1 (failure, не milestone); ClosedWon=10.
     */
    public function lifecycleOrder(): int
    {
        return match ($this) {
            // Реальные milestone'ы — двигают peak.
            self::Quoted => 5,
            self::UnderReview => 6,
            self::AwaitingInvoice => 7,
            self::Invoiced => 8,
            self::Paid => 9,
            self::ClosedWon => 10,
            // Pre-КП работа + блокировки + failure — peak не сдвигают.
            self::Pending,
            self::New,
            self::Assigned,
            self::InProgress,
            self::AwaitingClientClarification,
            self::PostponedUntil,
            self::Paused,
            self::ClosedLost => -1,
        };
    }

    /**
     * Карта разрешённых переходов из текущего статуса. Single source of truth
     * для UI кнопок и backend-валидации.
     *
     * Pause — спец-кейс, обрабатывается RequestPauseService::pauseUntil()
     * вне этой карты (любой not-terminal not-paused → Paused).
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::New, self::Assigned, self::ClosedLost],

            // 2026-05-22 расширение: auto-detect КП (OutboundDocumentDetector)
            // и intent-классификатор клиента (InboundIntentClassifier) часто
            // промахиваются — заявки застревают в Assigned/InProgress даже
            // с отправленным КП. Менеджеру нужен ручной escape hatch на
            // любой шаг pipeline. Карта переходов расширена так:
            //   — из любого active статуса можно «📤 КП отправлено» → Quoted,
            //     «📋 Запросил счёт» → AwaitingInvoice, «✓ Закрыть как успех»
            //     → ClosedWon (cash без формальной цепочки счёт→оплата).
            //   — обратный путь (InProgress) сохранён для возврата к работе.
            //   — ClosedLost везде доступен.
            //
            // 2026-05-25 продление: добавлен прямой Invoiced. Кейс M-2026-1525:
            // detector распознал счёт в outbound (confidence 0.9), но AI-decision
            // dismiss'нулся «assigned → invoiced запрещён». В жизни менеджер
            // часто шлёт счёт сразу — клиент знает что хочет (повторный заказ)
            // или КП обсудили устно/в WhatsApp вне MyLift. Симметрично с
            // AwaitingInvoice разрешаем напрямую.
            self::New => [
                self::Assigned, self::InProgress,
                self::AwaitingClientClarification,
                self::Quoted, self::AwaitingInvoice, self::Invoiced,
                self::ClosedWon, self::ClosedLost,
            ],
            self::Assigned => [
                self::InProgress, self::AwaitingClientClarification,
                self::Quoted, self::AwaitingInvoice, self::Invoiced,
                self::ClosedWon, self::ClosedLost,
            ],
            self::InProgress => [
                self::AwaitingClientClarification,
                self::Quoted, self::AwaitingInvoice, self::Invoiced,
                self::ClosedWon, self::ClosedLost,
            ],
            self::AwaitingClientClarification => [
                self::InProgress,
                self::Quoted, self::AwaitingInvoice, self::Invoiced,
                self::ClosedWon, self::ClosedLost,
            ],
            self::Quoted => [
                self::UnderReview, self::PostponedUntil,
                self::AwaitingInvoice, self::Invoiced,
                self::InProgress, // возврат на правки
                self::AwaitingClientClarification, // клиент уточняет после КП
                self::ClosedWon, self::ClosedLost,
            ],
            self::UnderReview => [
                self::InProgress,
                self::AwaitingInvoice, self::Invoiced,
                self::ClosedWon, self::ClosedLost,
            ],
            self::PostponedUntil => [
                self::InProgress,
                self::Quoted, self::AwaitingInvoice, self::Invoiced,
                self::ClosedWon, self::ClosedLost,
            ],
            self::AwaitingInvoice => [
                self::Invoiced,
                self::ClosedWon, // cash без формального учёта оплаты
                self::ClosedLost,
            ],
            self::Invoiced => [
                self::Paid,
                self::ClosedWon, // без отдельного шага Paid (упрощённый учёт)
                self::ClosedLost,
            ],
            self::Paid => [
                self::ClosedWon,
            ],
            self::Paused => [], // resume — через RequestPauseService::resume()
            self::ClosedWon, self::ClosedLost => [], // terminal
        };
    }

    /** Кратко: можно ли поставить на паузу из этого статуса. */
    public function canBePaused(): bool
    {
        return ! $this->isTerminal() && $this !== self::Paused && $this !== self::Pending;
    }
}
