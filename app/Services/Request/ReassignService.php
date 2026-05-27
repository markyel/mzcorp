<?php

namespace App\Services\Request;

use App\Enums\RequestStatus;
use App\Jobs\Mail\ArchiveFromOldManagerInboxJob;
use App\Jobs\Mail\DeliverToManagerInboxJob;
use App\Jobs\Mail\RouteMailToManagerJob;
use App\Models\Request as RequestModel;
use App\Models\RequestAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Переподчинение заявки другому менеджеру.
 *
 * Триггеры:
 *  - ручной — РОП/директор из карточки заявки ($by = current user);
 *  - системный — MailRouter применяет sticky direct_mailbox для reply'я,
 *    пришедшего в личный ящик не-assigned менеджера ($by = null).
 *    Жёсткое правило «личный ящик X → Request у X» (см. CLAUDE.md /
 *    Foundation §1.5). Reason тогда содержит явный префикс типа
 *    `sticky_direct_mailbox_on_reply` чтобы аудит отличал источник.
 *
 * Пишет audit в `request_assignments` и (если есть привязанное письмо)
 * ставит в очередь IMAP-операции — Route в подпапку MZ|<Фамилия> общего
 * ящика + Deliver в INBOX нового менеджера + Archive из INBOX старого.
 * Все три job'а идемпотентны.
 *
 * IMAP-move async через `RouteMailToManagerJob` — Yandex 360 IMAP COPY
 * держит соединение 5–10 секунд, синхронный вызов блокировал Livewire
 * UI и кнопка «зависала».
 *
 * AssignmentService.autoAssign не используем — он содержит sticky/round-robin
 * логику для НОВЫХ заявок и пропустит уже-сделанный выбор переподчинения.
 */
class ReassignService
{
    public function reassign(
        RequestModel $request,
        User $newAssignee,
        ?string $reason,
        ?User $by,
    ): RequestAssignment {
        // Snapshot ДО транзакции: внутри замыкания мы перепишем
        // assigned_user_id, и после dispatch'а доступа к старому ID уже нет.
        $oldAssigneeId = $request->assigned_user_id;

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

            // Reason prefix:
            //  - $by !== null → manual_reassign (триггер UI РОПа/директора);
            //  - $by === null → system_reassign (триггер pipelines, например
            //    sticky direct_mailbox при reply в личный ящик).
            $prefix = $by !== null ? 'manual_reassign' : 'system_reassign';
            $finalReason = $reason
                ? $prefix . ': ' . mb_substr($reason, 0, 200)
                : $prefix;

            return RequestAssignment::create([
                'request_id' => $request->id,
                'user_id' => $newAssignee->id,
                'by_user_id' => $by?->id,
                'reason' => $finalReason,
                'assigned_at' => now(),
            ]);
        });

        // Три IMAP job'а async:
        //   - RouteMailToManagerJob — COPY в подпапку MZ|<Фамилия> общего
        //     ящика (для секретаря, web UI видит распределение).
        //   - DeliverToManagerInboxJob — APPEND в INBOX личного ящика
        //     НОВОГО менеджера (рабочий поток нового менеджера).
        //   - ArchiveFromOldManagerInboxJob — UID MOVE копии из INBOX
        //     личного ящика СТАРОГО менеджера в MZ/Reassigned + STORE \Seen.
        //     Старая копия физически остаётся (Yandex quirk без EXPUNGE)
        //     либо переезжает (если Yandex EXPUNGE прошёл), но из INBOX
        //     visually уходит из счётчика непрочитанных.
        // Все три идемпотентны: повторный dispatch не задвоит.
        $email = $request->emailMessage;
        if ($email) {
            RouteMailToManagerJob::dispatch($email->id, $newAssignee->id);
            DeliverToManagerInboxJob::dispatch($email->id, $newAssignee->id);

            if ($oldAssigneeId !== null && $oldAssigneeId !== $newAssignee->id) {
                ArchiveFromOldManagerInboxJob::dispatch($email->id, $oldAssigneeId);
            }
        }

        return $assignment;
    }
}
