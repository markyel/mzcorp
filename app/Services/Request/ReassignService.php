<?php

namespace App\Services\Request;

use App\Enums\RequestStatus;
use App\Models\Request as RequestModel;
use App\Models\RequestAssignment;
use App\Models\User;
use App\Services\Mail\MailFolderRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ручное переподчинение заявки другому менеджеру.
 *
 * Триггер — РОП/директор из карточки заявки. Пишет audit-запись в
 * `request_assignments` и (если есть привязанное письмо) перекладывает
 * оригинал в IMAP-подпапку нового менеджера через MailFolderRouter.
 *
 * AssignmentService.autoAssign не используем — он содержит sticky/round-robin
 * логику для НОВЫХ заявок и пропустит ручной выбор оператора.
 */
class ReassignService
{
    public function __construct(
        private readonly MailFolderRouter $folderRouter,
    ) {}

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

        // Перекладывание оригинала в IMAP-папку нового менеджера — best-effort.
        // IMAP-сбой (Yandex недоступен, токен протух) не должен валить
        // транзакцию переподчинения; реассайн в БД важнее.
        $email = $request->emailMessage;
        if ($email) {
            try {
                $this->folderRouter->routeToManager($email, $newAssignee);
            } catch (\Throwable $e) {
                Log::warning('ReassignService: IMAP folder route failed', [
                    'request_id' => $request->id,
                    'new_assignee' => $newAssignee->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $assignment;
    }
}
