<?php

namespace App\Enums;

/**
 * Статус позиции каталога в пуле IQOT-анализа (iqot_positions).
 *  - pending   — в пуле, ждёт отправки;
 *  - queued    — отобрана к отправке (промежуточное);
 *  - analyzing — отправлена в IQOT, ждём отчёт;
 *  - completed — отчёт получен И есть хотя бы один оффер;
 *  - no_offers — отчёт получен, но поставщики не прислали ни одного предложения
 *                (IQOT отдаёт completed, но сравнивать не с чем);
 *  - failed    — ошибка отправки/анализа;
 *  - excluded  — исключена из пула вручную («не запрашивать никогда»).
 */
enum IqotPositionStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Analyzing = 'analyzing';
    case Completed = 'completed';
    case NoOffers = 'no_offers';
    case Failed = 'failed';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'В очереди',
            self::Queued => 'Отобрана',
            self::Analyzing => 'Анализируется',
            self::Completed => 'Готов отчёт',
            self::NoOffers => 'Без предложений',
            self::Failed => 'Ошибка',
            self::Excluded => 'Исключена',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Queued, self::Analyzing], true);
    }
}
