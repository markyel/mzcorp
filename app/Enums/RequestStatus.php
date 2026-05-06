<?php

namespace App\Enums;

/**
 * Статус заявки.
 *
 * Минимальный enum для Phase 1. Foundation §5.2 описывает полную
 * state-machine с десятком статусов — это Phase 4. Пока:
 *
 *   new       — заявка только создана, ещё не назначена менеджеру.
 *   assigned  — менеджер назначен, в работе.
 */
enum RequestStatus: string
{
    case New = 'new';
    case Assigned = 'assigned';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::Assigned => 'В работе',
        };
    }
}
