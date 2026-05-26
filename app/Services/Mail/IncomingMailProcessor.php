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
        // Принимаем client_request (новая заявка) и thread_reply (ответ
        // в треде). Для thread_reply создаём Request ТОЛЬКО когда linker
        // не нашёл existing — это «висящий» reply на тред которого у нас
        // нет (forward старой переписки клиента, например). Без этого
        // fallback'а такие письма пропадали без следа. Кейс M-?-?
        // (Ангелина «Re: Fwd: Запрос стоимости лм3245» — после удаления
        // Level-2 классификатора thread_reply без linker-match выпадал).
        $acceptedCategories = [
            EmailCategory::ClientRequest->value,
            EmailCategory::ThreadReply->value,
        ];
        if (! in_array($message->category, $acceptedCategories, true)) {
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

        // Тема + тело — часть клиентов пишет всю заявку одной строкой
        // в subject (артикул + qty), body содержит только подпись или
        // вообще пуст. Раньше guard смотрел только в body и резал такие
        // письма. Кейсы M-2026-1815 (MAA250AY301 1 штука), M-2026-1816
        // (GAA737AA1 6 штук).
        $combined = (string) $message->subject . "\n" . $plain;

        // M-артикул — внутренний SKU MyZip, сильнейший сигнал
        // товарной заявки. Кейс M-2026 (Liftway): subject=«Счёт»,
        // body=«M04990 - 1шт.» (12 симв) — реальная заявка на счёт.
        if (preg_match('/\bM\d{4,}\b/u', $combined)) {
            return false;
        }

        // Артикул-подобный токен: uppercase ASCII letters + digits.
        // Ловит KONE/OTIS-style codes (MAA250AY301, GAA737AA1,
        // KM713857G01), стандартные подшипники (6308-2RS1 — нет, тут
        // нет uppercase letters, OK), и пр. Cyrillic буквы не попадают
        // (паттерн ASCII-only), русский текст не триггерит false-positive.
        if (preg_match('/\b[A-Z]{1,4}\d{2,}[A-Z0-9]*\b/u', $combined)) {
            return false;
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
