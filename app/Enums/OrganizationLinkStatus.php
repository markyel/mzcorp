<?php

namespace App\Enums;

/**
 * Решение по сомнительной привязке контрагента к адресу заказчика
 * (OrganizationLinkRequest).
 *
 *   pending   — ждёт менеджера, связи нет;
 *   confirmed — подтверждена, связь создана;
 *   rejected  — ошибка в документе, связь не создаётся и больше не предлагается.
 */
enum OrganizationLinkStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'ждёт подтверждения',
            self::Confirmed => 'привязка подтверждена',
            self::Rejected => 'отклонена как ошибка',
        };
    }
}
