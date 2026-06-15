<?php

namespace App\Services\Mail;

use App\Enums\ClientNotificationType;
use App\Models\ClientNotificationOptout;

/**
 * Стоп-лист авто-уведомлений по e-mail клиента.
 *
 * Проверка вызывается из `ClientNotificationService::dispatch` — единой точки
 * отправки всех типов (sync-хуки и cron идут через неё), поэтому одного гарда
 * достаточно для всех 6 типов.
 */
class ClientNotificationOptoutService
{
    /**
     * Нужно ли заглушить уведомление данного типа для адреса.
     */
    public function isSuppressed(?string $email, ClientNotificationType $type): bool
    {
        $normalized = mb_strtolower(trim((string) $email));
        if ($normalized === '') {
            return false;
        }

        $entry = ClientNotificationOptout::query()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->first();

        return $entry !== null && $entry->suppresses($type);
    }
}
