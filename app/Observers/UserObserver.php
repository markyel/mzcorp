<?php

namespace App\Observers;

use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Сайд-эффекты на изменение состояния пользователя.
 *
 * При архивации (`archived_at` → not null) — все его personal-mailbox'ы
 * автоматически деактивируются (`is_active = false`). Это закрывает кейс
 * «orphan-mailbox»: после archive user'а наш sync продолжал тянуть письма
 * в его IMAP-ящик, а `MailDeliverToManagerService` через backfill-cron
 * мог APPEND'ить туда повторно. Кейс 2026-05-27: тестовые ящики man1/man2/man3
 * остались active после archive user#5/6/7 → 246 production-писем APPEND'нуты
 * в тестовые ящики, видимые как «массовые дубли» (см. MEMORY).
 *
 * При restore (`archived_at` → null) — НЕ включаем mailbox'ы обратно, потому
 * что admin может намеренно держать ящик off-line (отпуск, потеря OAuth).
 * Включение делать вручную через Admin/Managers UI.
 */
class UserObserver
{
    public function updated(User $user): void
    {
        if (! $user->wasChanged('archived_at')) {
            return;
        }

        // Восстановление пользователя — mailbox'ы НЕ трогаем (см. docblock).
        if ($user->archived_at === null) {
            return;
        }

        $affected = Mailbox::query()
            ->where('owner_user_id', $user->id)
            ->where('type', \App\Enums\MailboxType::Personal->value)
            ->where('is_active', true)
            ->get();

        foreach ($affected as $mb) {
            $mb->forceFill(['is_active' => false])->save();
            Log::info('UserObserver: deactivated mailbox on user archive', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'mailbox_id' => $mb->id,
                'mailbox_email' => $mb->email,
            ]);
        }
    }
}
