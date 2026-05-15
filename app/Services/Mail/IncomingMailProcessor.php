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
        private readonly EmailTextCleanerService $cleaner,
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

        // Empty-content guard: если очищенное тело короче порога И нет
        // attachment'ов — это «А вложения и не было )» / односложный ответ,
        // парсер позиций отработает в ноль. Создавать Request бессмысленно.
        // Переписываем category на irrelevant с пометкой, чтобы письмо
        // попало в /dashboard/mail-review (РОП может «↻ Это заявка» если
        // ошибочно).
        if ($this->isContentEmpty($message)) {
            $message->forceFill([
                'category' => EmailCategory::Irrelevant->value,
                'category_reasoning' => 'Empty body, no attachments — not actionable (auto-guard)',
            ])->save();

            Log::info('IncomingMailProcessor: skip empty content (auto-guard)', [
                'email_message_id' => $message->id,
                'from_email' => $message->from_email,
                'subject' => mb_substr((string) $message->subject, 0, 80),
            ]);

            return null;
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

    /**
     * Тело письма пустое для создания заявки?
     *
     * Считает «пустым» когда:
     *   - нет attachments, И
     *   - очищенное тело (dequote + removeSignature + удаление external-маркеров)
     *     короче `config('services.mail.empty_body_guard_min_chars')` символов.
     *
     * Порог 40 символов — типичные «А вложения не было», «Спасибо!»,
     * «Получили». Реальные заявки даже в одну строку длиннее.
     */
    private function isContentEmpty(EmailMessage $message): bool
    {
        if ($message->attachments()->count() > 0) {
            return false;
        }

        $threshold = (int) config('services.mail.empty_body_guard_min_chars', 40);
        if ($threshold <= 0) {
            return false; // guard выключен
        }

        $plain = (string) $message->body_plain;
        if ($plain === '' || $this->cleaner->bodyPlainLooksBroken($plain)) {
            $plain = $this->cleaner->htmlToText((string) $message->body_html);
        }
        $cleaned = $this->cleaner->cleanInboundReferenceText($plain);

        // Срезаем external-маркеры (LZ-REQ-NNNN и т.п.) — они header, не контент.
        $patterns = (array) config('services.mail.external_codes', []);
        foreach ($patterns as $p) {
            $cleaned = (string) preg_replace($p, '', $cleaned);
        }

        return mb_strlen(trim($cleaned)) < $threshold;
    }
}
