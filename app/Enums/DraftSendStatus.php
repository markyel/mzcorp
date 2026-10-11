<?php

namespace App\Enums;

/**
 * Состояние отправки черновика — поле email_messages.send_status.
 *   queued → SMTP был недоступен (сетевой сбой), письмо ждёт автоповтора
 *            в send_next_attempt_at (OutgoingSendRetryService).
 *   failed → повторы исчерпаны или ошибка не временная; письмо осталось
 *            черновиком, автор получил уведомление.
 * NULL — обычный черновик.
 */
enum DraftSendStatus: string
{
    case Queued = 'queued';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Ожидает отправки',
            self::Failed => 'Не отправлено',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Queued => '⏳',
            self::Failed => '⚠',
        };
    }
}
