<?php

namespace App\Enums;

/**
 * Статус позиции каталога в пуле IQOT-анализа (iqot_positions).
 *  - pending   — в пуле, ждёт отправки;
 *  - queued    — отобрана к отправке (промежуточное);
 *  - analyzing — отправлена в IQOT, ждём отчёт;
 *  - completed — отчёт получен;
 *  - failed    — ошибка отправки/анализа.
 */
enum IqotPositionStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Analyzing = 'analyzing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'В очереди',
            self::Queued => 'Отобрана',
            self::Analyzing => 'Анализируется',
            self::Completed => 'Готов отчёт',
            self::Failed => 'Ошибка',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Queued, self::Analyzing], true);
    }
}
