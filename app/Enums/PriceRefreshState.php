<?php

namespace App\Enums;

/**
 * Статус обновления цен на заявке (Фаза 3.5) — поле requests.price_refresh_state.
 * Отдельно от воронки RequestStatus (не влияет на SLA). Жизненный цикл:
 *   awaiting   → менеджер отправил RFQ по неактуальным сматченным позициям,
 *                ждём цены/ответы (по части позиций — pending).
 *   actualized → все отслеживаемые позиции решены и есть хотя бы одна с
 *                актуальной ценой → можно делать КП.
 *   refused    → все отслеживаемые позиции — отказ поставщиков (ни одной
 *                актуальной цены).
 * NULL — заявка вне цикла обновления цен.
 */
enum PriceRefreshState: string
{
    case Awaiting = 'awaiting';
    case Actualized = 'actualized';
    case Refused = 'refused';

    public function label(): string
    {
        return match ($this) {
            self::Awaiting => 'Цена на обновлении',
            self::Actualized => 'Цены актуализированы',
            self::Refused => 'Поставщики отказали',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Awaiting => '⏳',
            self::Actualized => '💰',
            self::Refused => '🚫',
        };
    }

    /** Tailwind-класс чипа (по уже скомпилированным chip-* в проекте). */
    public function chipClass(): string
    {
        return match ($this) {
            self::Awaiting => 'chip-info',
            self::Actualized => 'chip-ok',
            self::Refused => 'chip-warn',
        };
    }
}
