<?php

namespace App\Enums;

/**
 * Жизненный цикл КП (исходящего нашего предложения клиенту).
 *
 *   draft     — менеджер редактирует, ещё не отправлено клиенту.
 *               Цены/qty/discount правятся in-place, версия не растёт.
 *   sent      — отправлено клиенту (через ComposeForm + PDF attach).
 *               Immutable. Дальнейшие правки → новая версия КП
 *               (QuotationService::freezeVersion() создаёт новый
 *               Quotation v+1 в статусе draft).
 *   accepted  — клиент подтвердил (явно: «принимаем КП», или через
 *               запрос счёта = AwaitingInvoice intent в реплае).
 *   rejected  — клиент явно отказался (decline intent + reason).
 *   cancelled — менеджер отменил draft до отправки (или КП больше
 *               не актуально — состав изменился). История остаётся.
 */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Sent => 'Отправлено',
            self::Accepted => 'Принято',
            self::Rejected => 'Отклонено',
            self::Cancelled => 'Отменено',
        };
    }

    /** Можно ли ещё редактировать (правки items / общая скидка / реквизиты). */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Терминальный статус (нельзя вернуть в работу без новой версии). */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Accepted, self::Rejected, self::Cancelled], true);
    }
}
