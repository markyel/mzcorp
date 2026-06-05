<?php

namespace App\Enums;

/**
 * Локальный статус записи IqotSubmission (батч позиций в IQOT API).
 * Маппится из iqot_status (ответ API) через fromIqot(). Порт из LazyLift.
 */
enum IqotSubmissionStatus: string
{
    case Draft = 'draft';
    case Sending = 'sending';
    case Accepted = 'accepted';
    case Processing = 'processing';
    case Collecting = 'collecting';
    case ReadyMinimum = 'ready_minimum';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /**
     * IQOT status → наш локальный. Неизвестный → null (оставляем текущий).
     */
    public static function fromIqot(?string $iqotStatus): ?self
    {
        return match ($iqotStatus) {
            'accepted' => self::Accepted,
            'processing' => self::Processing,
            'ready',
            'collecting' => self::Collecting,
            'ready_minimum' => self::ReadyMinimum,
            'completed' => self::Completed,
            'cancelled' => self::Cancelled,
            default => null,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Failed], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Accepted, self::Processing, self::Collecting, self::ReadyMinimum], true);
    }

    /**
     * Состояния, в которых уже может появиться отчёт.
     */
    public function mayHaveReport(): bool
    {
        return in_array($this, [self::ReadyMinimum, self::Completed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Sending => 'Отправка',
            self::Accepted => 'Принято',
            self::Processing => 'Обработка',
            self::Collecting => 'Сбор офферов',
            self::ReadyMinimum => 'Есть офферы',
            self::Completed => 'Завершено',
            self::Cancelled => 'Отменено',
            self::Failed => 'Ошибка',
        };
    }
}
