<?php

namespace App\Services\Mail;

use App\Enums\EmailCategory;
use App\Enums\RequestStatus;
use App\Jobs\Mail\ParseRequestItemsJob;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\Request\AssignmentService;
use App\Services\Request\InternalCodeGenerator;
use App\Services\Request\RequestActivityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Создание Request из inbound email-а с category=client_request.
 *
 * Phase 1.8d: создаём Request со статусом `Pending` (БЕЗ assignment, БЕЗ
 * folder routing) и сразу dispatch'им `ParseRequestItemsJob`. Парсер позиций
 * после успешного persist'а вызовет `AssignmentService::autoAssign()`,
 * который сам переведёт статус в `Assigned` + назначит менеджера +
 * скопирует письмо в `MZ\|{Lastname}`.
 *
 * Менеджер видит в пуле только заявки со статусами `new` / `assigned`;
 * `pending` — это заявки, которые ещё парсятся либо парсер вернул пусто
 * (видны только РОПу/директору для контроля).
 */
class IncomingMailProcessor
{
    public function __construct(
        private readonly InternalCodeGenerator $codeGenerator,
        private readonly AssignmentService $assignment,
        private readonly MailFolderRouter $folders,
        private readonly RequestActivityService $activity,
    ) {
    }

    public function processIfRequest(EmailMessage $message): ?Request
    {
        // Только заявки клиентов. Решение принимает Level-1 категоризатор
        // (gpt-4o), Level-2 (gpt-4o-mini) удалён — системно ошибался.
        if ($message->category !== EmailCategory::ClientRequest->value) {
            return null;
        }

        // Идемпотентность: уже привязали Request к этому письму.
        if ($message->related_request_id) {
            return Request::find($message->related_request_id);
        }

        $request = DB::transaction(function () use ($message) {
            $req = Request::create([
                'internal_code' => $this->codeGenerator->next(),
                'email_message_id' => $message->id,
                // Pending: парсер позиций ещё не отработал. Менеджеру
                // в пуле такие заявки не показываются.
                'status' => RequestStatus::Pending,
                'client_email' => $message->from_email ?: '',
                'client_name' => $message->from_name,
                'subject' => $message->subject,
            ]);

            $message->forceFill(['related_request_id' => $req->id])->save();

            $this->activity->touch($req, \App\Enums\RequestActivityType::RequestCreated);

            return $req;
        });

        // Phase 1.8d: парсер позиций в очереди. После успешного persist'а
        // RequestItemPersister сам вызовет AssignmentService::autoAssign(),
        // который переведёт статус Pending → Assigned + назначит менеджера +
        // скопирует письмо в подпапку MZ\|{Lastname}.
        ParseRequestItemsJob::dispatch($message->id);

        Log::info('Request created from incoming mail (pending parse)', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
        ]);

        return $request->fresh();
    }
}
