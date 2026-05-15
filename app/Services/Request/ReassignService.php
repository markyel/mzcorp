<?php

namespace App\Services\Request;

use App\Enums\RequestStatus;
use App\Jobs\Mail\DeliverToManagerInboxJob;
use App\Jobs\Mail\RouteMailToManagerJob;
use App\Models\Request as RequestModel;
use App\Models\RequestAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ручное переподчинение заявки другому менеджеру.
 *
 * Триггер — РОП/директор из карточки заявки. Пишет audit-запись в
 * `request_assignments` и (если есть привязанное письмо) ставит в
 * очередь IMAP-move оригинала в подпапку нового менеджера.
 *
 * IMAP-move async через `RouteMailToManagerJob` — Yandex 360 IMAP COPY
 * держит соединение 5–10 секунд, синхронный вызов блокировал Livewire
 * UI и кнопка «зависала».
 *
 * AssignmentService.autoAssign не используем — он содержит sticky/round-robin
 * логику для НОВЫХ заявок и пропустит ручной выбор оператора.
 */
class ReassignService
{
    public function reassign(
        RequestModel $request,
        User $newAssignee,
        ?string $reason,
        User $by,
    ): RequestAssignment {
        $assignment = DB::transaction(function () use ($request, $newAssignee, $reason, $by): RequestAssignment {
            $request->assigned_user_id = $newAssignee->id;
            // Если заявка была в Pending (без позиций) — оставляем в Pending,
            // иначе переводим в Assigned. New статус не возвращаем — это
            // регрессия по UI-чипам.
            if ($request->status !== RequestStatus::Pending) {
                $request->status = RequestStatus::Assigned;
            }
            $request->assigned_at = now();
            $request->save();

            return RequestAssignment::create([
                'request_id' => $request->id,
                'user_id' => $newAssignee->id,
                'by_user_id' => $by->id,
                'reason' => $reason ? 'manual_reassign: ' . mb_substr($reason, 0, 200) : 'manual_reassign',
                'assigned_at' => now(),
            ]);
        });

        // Два IMAP job'а async:
        //   - RouteMailToManagerJob — COPY в подпапку MZ|<Фамилия> общего
        //     ящика (для секретаря, web UI видит распределение);
        //   - DeliverToManagerInboxJob — APPEND в INBOX личного ящика
        //     менеджера (рабочий поток менеджера).
        // Оба идемпотентны: повторный dispatch не задвоит.
        $email = $request->emailMessage;
        if ($email) {
            RouteMailToManagerJob::dispatch($email->id, $newAssignee->id);
            DeliverToManagerInboxJob::dispatch($email->id, $newAssignee->id);
        }

        return $assignment;
    }
}
