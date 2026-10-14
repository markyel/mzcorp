<?php

namespace App\Services\QuoteParts;

use App\Models\User;

/**
 * Переключатель QuoteParts в mzcorp. Канал «Запрос через QuoteParts» виден, только когда:
 *  - включён общий рубильник quoteparts.rfq_enabled (Настройки / раздел QuoteParts);
 *  - пользователь отмечен участником пилота (users.quoteparts_pilot);
 *  - задан ключ API.
 * Иначе mzcorp работает как раньше; старый модуль поставщиков не меняется.
 */
class QuotePartsPilot
{
    public const SETTING = 'quoteparts.rfq_enabled';

    public static function switchedOn(): bool
    {
        return (bool) app_setting(self::SETTING, config('services.quoteparts.rfq_enabled', false));
    }

    public static function enabledFor(?User $user): bool
    {
        return $user !== null
            && (bool) $user->quoteparts_pilot
            && self::switchedOn()
            && app(QuotePartsClient::class)->isConfigured();
    }
}
