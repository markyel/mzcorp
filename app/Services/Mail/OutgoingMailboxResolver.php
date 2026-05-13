<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use App\Models\Request;

/**
 * Выбор FROM-mailbox для исходящего письма (Phase 1.9).
 *
 * Алгоритм (план §«Mailbox resolution»):
 *   1. У assigned менеджера есть active personal mailbox с canSendOutbound() →
 *      берём его.
 *   2. Иначе fallback на shared mailbox (config('services.mail_outbound.shared_email')).
 *   3. Иначе null + reason — UI выводит ошибку.
 *
 * Auto-fallback срабатывает ТОЛЬКО когда у менеджера нет своего mailbox.
 * Если есть, но OAuth refresh fail — fallback не делаем автоматически:
 * Sender при send отдаст error, UI спросит у пользователя подтверждения.
 */
class OutgoingMailboxResolver
{
    /**
     * @return array{mailbox: ?Mailbox, isFallback: bool, reason: ?string}
     */
    public function resolve(Request $request): array
    {
        $assigned = $request->assignedUser;

        if ($assigned !== null) {
            $personal = $assigned->primaryOutboundMailbox();
            if ($personal !== null) {
                return [
                    'mailbox' => $personal,
                    'isFallback' => false,
                    'reason' => null,
                ];
            }
        }

        $sharedEmail = (string) config('services.mail_outbound.shared_email', 'mail@myzip.ru');
        $shared = Mailbox::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($sharedEmail)])
            ->where('is_active', true)
            ->first();

        if ($shared !== null && $shared->canSendOutbound()) {
            return [
                'mailbox' => $shared,
                'isFallback' => true,
                'reason' => $assigned ? 'no_personal_mailbox' : 'no_assigned_user',
            ];
        }

        return [
            'mailbox' => null,
            'isFallback' => false,
            'reason' => 'no_mailbox_available',
        ];
    }
}
