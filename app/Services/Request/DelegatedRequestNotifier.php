<?php

namespace App\Services\Request;

use App\Mail\DelegatedRequestActivityMail;
use App\Models\Request;
use App\Models\User;
use App\Notifications\RequestDelegatedActivityNotification;
use App\Services\Mail\SystemNotificationMailer;
use Illuminate\Support\Facades\Log;

/**
 * Информирование ACTING-менеджеров (кому временно делегированы заявки
 * отсутствующего коллеги) о новых событиях по делегированным заявкам —
 * новом письме клиента. Оригинальный владелец отсутствует (потому и
 * делегировано), поэтому новый ответственный узнаёт о событии через
 * колокольчик (in-app) + email со ссылкой на заявку.
 *
 * Троттлинг: одному acting по одной заявке не чаще раза в THROTTLE_MINUTES,
 * чтобы серия писем клиента не залила уведомлениями.
 */
class DelegatedRequestNotifier
{
    private const THROTTLE_MINUTES = 30;

    public function __construct(private readonly SystemNotificationMailer $mailer)
    {
    }

    /**
     * Уведомить acting-менеджеров о новом событии по делегированной заявке.
     */
    public function notifyActingManagers(Request $request): void
    {
        $delegations = $request->activeDelegations()
            ->with('actingUser:id,name,email')
            ->get();
        if ($delegations->isEmpty()) {
            return;
        }

        $seen = [];
        foreach ($delegations as $d) {
            $acting = $d->actingUser;
            if ($acting === null || isset($seen[$acting->id])) {
                continue;
            }
            // Оригинальному владельцу (assigned) не дублируем.
            if ((int) $acting->id === (int) $request->assigned_user_id) {
                continue;
            }
            $seen[$acting->id] = true;

            if ($this->throttled($acting, $request)) {
                continue;
            }

            // 1) In-app (колокольчик).
            try {
                $acting->notify(RequestDelegatedActivityNotification::from($request));
            } catch (\Throwable $e) {
                Log::warning('DelegatedRequestNotifier: bell notify failed (non-fatal)', [
                    'request_id' => $request->id,
                    'acting_user_id' => $acting->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // 2) Email со ссылкой (per-mailbox SMTP через SystemNotificationMailer).
            if (trim((string) $acting->email) !== '') {
                try {
                    $this->mailer->sendMailable($acting->email, new DelegatedRequestActivityMail(
                        requestId: $request->id,
                        internalCode: $request->internal_code,
                        reqSubject: $request->subject,
                        clientName: $request->client_name ?: $request->client_email,
                    ));
                } catch (\Throwable $e) {
                    Log::warning('DelegatedRequestNotifier: email failed (non-fatal)', [
                        'request_id' => $request->id,
                        'acting_user_id' => $acting->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /** Уже уведомляли этого acting по этой заявке в окне троттлинга? */
    private function throttled(User $user, Request $request): bool
    {
        // notifications.data — колонка TEXT (не jsonb), поэтому каст к jsonb
        // перед оператором ->> (иначе «operator does not exist: text ->>»).
        return $user->notifications()
            ->where('created_at', '>', now()->subMinutes(self::THROTTLE_MINUTES))
            ->whereRaw("(data::jsonb)->>'kind' = ?", ['delegated_activity'])
            ->whereRaw("(data::jsonb)->>'request_id' = ?", [(string) $request->id])
            ->exists();
    }
}
