<?php

namespace App\Enums;

/**
 * Жизненный цикл тикета «связь с создателем».
 *
 *   open ──► in_progress ──► resolved ──► closed
 *      └─────────────────────────────────────►
 *
 * open         — пришёл тикет, ещё не взят в работу.
 * in_progress  — админ ответил / разбирается.
 * resolved     — админ решил, ждёт подтверждения пользователя.
 * closed       — закрыт (пользователем или автоматически).
 */
enum SupportTicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Открыт',
            self::InProgress => 'В работе',
            self::Resolved => 'Решён',
            self::Closed => 'Закрыт',
        };
    }

    /**
     * CSS-класс chip'а в дизайн-системе (см. resources/css/app.css).
     */
    public function chipClass(): string
    {
        return match ($this) {
            self::Open => 'chip-attn',
            self::InProgress => 'chip-info',
            self::Resolved => 'chip-ok',
            self::Closed => 'chip-neutral',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::InProgress;
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
