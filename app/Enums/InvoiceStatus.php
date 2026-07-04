<?php

namespace App\Enums;

/**
 * Статус Invoice (счёт за позиции по заявке).
 *
 *   pending   — выставлен, ждём оплату.
 *   paid      — клиент оплатил (бухгалтерия пометила вручную в UI «Счета»).
 *   expired   — срок действия истёк (auto через cron `invoices:check-expiry`).
 *               Request возвращается в AwaitingInvoice — можно перевыставить.
 *   cancelled — менеджер аннулировал вручную (с reason'ом).
 */
enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    /** Частичная оплата (импорт оплат 1С, Оп% < 100). Заявка закрывается как успех; доплата переводит счёт в Paid. */
    case PartiallyPaid = 'partially_paid';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает оплаты',
            self::Paid => 'Оплачен',
            self::PartiallyPaid => 'Частично оплачен',
            self::Expired => 'Просрочен',
            self::Cancelled => 'Аннулирован',
        };
    }

    /** CSS-класс chip из design tokens. */
    public function chipClass(): string
    {
        return match ($this) {
            self::Pending => 'chip-warn',
            self::Paid => 'chip-ok',
            self::PartiallyPaid => 'chip-sky',
            self::Expired => 'chip-danger',
            self::Cancelled => 'chip-paused',
        };
    }

    /** Терминальный — финальный статус, изменения недопустимы. */
    public function isTerminal(): bool
    {
        return $this === self::Paid || $this === self::Cancelled;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
