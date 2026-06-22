<?php

namespace App\Services\Mail;

use App\Enums\ClientNotificationType;
use App\Models\ClientNotificationOptout;
use Illuminate\Support\Facades\DB;

/**
 * Стоп-лист авто-уведомлений по e-mail клиента.
 *
 * Проверка вызывается из `ClientNotificationService::dispatch` — единой точки
 * отправки всех типов (sync-хуки и cron идут через неё), поэтому одного гарда
 * достаточно для всех типов. Редактируется из админ-страницы
 * /dashboard/notification-optouts и из карточек клиента/контакта (раздел
 * «Клиенты») — все через единый toggle() здесь.
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

    /**
     * Заглушённые типы (values) для одного e-mail.
     *
     * @return array<int, string>
     */
    public function suppressedFor(?string $email): array
    {
        $normalized = mb_strtolower(trim((string) $email));
        if ($normalized === '') {
            return [];
        }

        $entry = ClientNotificationOptout::query()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->first(['suppressed_types']);

        return $entry !== null ? array_values((array) $entry->suppressed_types) : [];
    }

    /**
     * Заглушённые типы по нескольким e-mail (батч, для карточки организации).
     *
     * @param  array<int, string>  $emails  (любой регистр)
     * @return array<string, array<int, string>>  lower(email) => [type_value, ...]
     */
    public function suppressedForMany(array $emails): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($e) => mb_strtolower(trim((string) $e)),
            $emails,
        ))));
        if ($normalized === []) {
            return [];
        }

        return ClientNotificationOptout::query()
            ->whereIn(DB::raw('lower(email)'), $normalized)
            ->get(['email', 'suppressed_types'])
            ->mapWithKeys(fn (ClientNotificationOptout $e) => [
                mb_strtolower((string) $e->email) => array_values((array) $e->suppressed_types),
            ])
            ->all();
    }

    /**
     * Переключить один тип для e-mail: включить ↔ заглушить. Пустую запись без
     * комментария удаляем (нет опт-аутов = всё включено по умолчанию).
     */
    public function toggle(?string $email, ClientNotificationType $type, ?int $byUserId = null): void
    {
        $normalized = mb_strtolower(trim((string) $email));
        if ($normalized === '') {
            return;
        }

        $entry = ClientNotificationOptout::query()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->first() ?? new ClientNotificationOptout(['email' => $normalized]);

        $suppressed = array_values((array) ($entry->suppressed_types ?? []));
        if (in_array($type->value, $suppressed, true)) {
            $suppressed = array_values(array_diff($suppressed, [$type->value])); // включить
        } else {
            $suppressed[] = $type->value; // заглушить
        }

        $hasComment = trim((string) ($entry->comment ?? '')) !== '';
        if ($suppressed === [] && ! $hasComment) {
            if ($entry->exists) {
                $entry->delete();
            }

            return;
        }

        if (! $entry->exists) {
            $entry->created_by_user_id = $byUserId;
        }
        $entry->suppressed_types = $suppressed;
        $entry->save();
    }
}
