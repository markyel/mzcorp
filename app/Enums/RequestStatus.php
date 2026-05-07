<?php

namespace App\Enums;

/**
 * Статус заявки.
 *
 * Минимальный enum для Phase 1. Foundation §5.2 описывает полную
 * state-machine с десятком статусов — это Phase 4. Пока:
 *
 *   pending   — заявка создана из inbound-письма, парсер позиций ещё в очереди
 *               или вернул пустой результат. Менеджеру в пуле НЕ показывается
 *               (ему не с чем работать), РОП/директор видят (для контроля).
 *   new       — позиции распарсены, ждёт назначения менеджеру.
 *   assigned  — менеджер назначен, в работе.
 */
enum RequestStatus: string
{
    case Pending = 'pending';
    case New = 'new';
    case Assigned = 'assigned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'В обработке',
            self::New => 'Новая',
            self::Assigned => 'В работе',
        };
    }

    /**
     * Готова ли заявка к показу менеджеру в пуле.
     * Pending = парсер ещё не отработал, у менеджера нет позиций для работы.
     */
    public function isVisibleToManager(): bool
    {
        return $this !== self::Pending;
    }
}
