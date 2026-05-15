<?php

namespace App\Services\Mail;

use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use Illuminate\Support\Facades\Log;

/**
 * Привязка исходящих писем (Sent) к существующим Request — Phase 1.9 outbound.
 *
 * Параллель к `InboundReplyLinker`, но проще: AI-clarification не нужен
 * (оператор знает, на что отвечает), категоризация не нужна (это наше письмо),
 * нет 5-го уровня. Семантика level 4 другая: смотрим `to_recipients` (клиент),
 * не `from_email` (там наш ящик).
 *
 *   L1: In-Reply-To → найти EmailMessage с тем же message_id, взять его
 *       related_request_id.
 *   L2: References → то же по массиву.
 *   L3: Subject `M-2026-NNNN` → lookup Request по internal_code.
 *   L3.5: External codes (LZ-REQ-NNNN и др.) → самое раннее EmailMessage с
 *         тем же маркером. См. config('services.mail.external_codes').
 *   L4: to_recipients[].email → открытые Request с `client_email`. Если 1 —
 *       линкуем. Если 2+ — берём самую свежую и пишем WARNING (РОП может
 *       поправить вручную через переподчинение, либо мы добавим UI-«сменить
 *       привязку треда» в Phase 2).
 *
 * Возвращает Request если линковка удалась, null — иначе. Записывает
 * `email_messages.related_request_id`. На стороне MailRouter это всё, что
 * нужно — Detail.php грузит весь thread по related_request_id.
 */
class OutgoingMailLinker
{
    public function __construct(
        private readonly \App\Services\Request\RequestActivityService $activity,
    ) {
    }

    public function tryLink(EmailMessage $message): ?Request
    {
        if ($message->related_request_id) {
            return Request::find($message->related_request_id);
        }

        // L1+L2: header threading.
        $candidateIds = $this->collectCandidateMessageIds($message);
        $matched = null;
        $matchedBy = null;

        if (! empty($candidateIds)) {
            $matched = EmailMessage::query()
                ->whereIn('message_id', $candidateIds)
                ->whereNotNull('related_request_id')
                ->orderByDesc('id')
                ->first();
            $matchedBy = $matched ? 'in_reply_to_or_references' : null;
        }

        // L3: subject internal_code.
        if (! $matched) {
            $matched = $this->matchBySubjectCode($message);
            $matchedBy = $matched ? 'subject_internal_code' : null;
        }

        // L3.5: external partner codes (LZ-REQ-NNNN и т.п.).
        if (! $matched) {
            $matched = $this->matchByExternalCode($message);
            $matchedBy = $matched ? 'external_code' : null;
        }

        $request = $matched && $matched->related_request_id
            ? Request::find($matched->related_request_id)
            : null;

        // L4: to_recipients + open Requests клиента.
        if (! $request) {
            $request = $this->matchByOpenRequestForRecipients($message);
            if ($request) {
                $matchedBy = 'to_recipient_open_request';
            }
        }

        if (! $request) {
            return null;
        }

        $message->forceFill(['related_request_id' => $request->id])->save();

        // Pool: исходящее от менеджера → ManagerReplied. silencesAttention()
        // снимает существующий ClientReplied/FreshAssignment — ход передан.
        // RequestStateService::transitionTo (на КП / Уточнение / Счёт) может
        // позже перезатереть на более конкретный тип через touch().
        $this->activity->touch(
            $request,
            \App\Enums\RequestActivityType::ManagerReplied,
            $message->sent_at ?: now(),
        );

        Log::info('OutgoingMailLinker: linked outbound to Request', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'matched_by' => $matchedBy,
        ]);

        return $request;
    }

    /**
     * @return array<int, string>
     */
    private function collectCandidateMessageIds(EmailMessage $message): array
    {
        $ids = [];

        if ($message->in_reply_to) {
            $ids[] = $this->normalize($message->in_reply_to);
        }

        if (is_array($message->references_header)) {
            foreach (array_reverse($message->references_header) as $ref) {
                $clean = $this->normalize((string) $ref);
                if ($clean !== '') {
                    $ids[] = $clean;
                }
            }
        }

        return array_values(array_unique(array_filter($ids, fn ($v) => $v !== '')));
    }

    /**
     * Если оператор отвечает «свежим» письмом без References, но в subject
     * остался код заявки `M-2026-NNNN` — линкуем по нему.
     */
    private function matchBySubjectCode(EmailMessage $message): ?EmailMessage
    {
        $haystack = trim(((string) $message->subject) . "\n" . ((string) $message->body_plain));
        if ($haystack === '') {
            return null;
        }

        if (! preg_match('/\bM-\d{4}-\d{4,}\b/u', $haystack, $m)) {
            return null;
        }

        $code = $m[0];
        $request = Request::where('internal_code', $code)->first();
        if (! $request || ! $request->email_message_id) {
            return null;
        }

        return EmailMessage::find($request->email_message_id);
    }

    /**
     * L3.5: маркеры партнёрских систем — то же что в InboundReplyLinker.
     * См. config('services.mail.external_codes').
     */
    private function matchByExternalCode(EmailMessage $message): ?EmailMessage
    {
        $patterns = (array) config('services.mail.external_codes', []);
        if (empty($patterns)) {
            return null;
        }

        $haystack = trim(((string) $message->subject) . "\n" . ((string) $message->body_plain));
        if ($haystack === '') {
            return null;
        }

        $codes = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $haystack, $m)) {
                foreach ($m[0] as $code) {
                    $codes[$code] = true;
                }
            }
        }
        if (empty($codes)) {
            return null;
        }

        $bestParent = null;
        foreach (array_keys($codes) as $code) {
            $parent = EmailMessage::query()
                ->whereNotNull('related_request_id')
                ->where('id', '!=', $message->id)
                ->where(function ($q) use ($code) {
                    $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $code) . '%';
                    $q->where('subject', 'ilike', $needle)
                        ->orWhere('body_plain', 'ilike', $needle);
                })
                ->orderBy('id')
                ->first(['id', 'related_request_id']);

            if ($parent === null) {
                continue;
            }
            if ($bestParent === null || $parent->id < $bestParent->id) {
                $bestParent = $parent;
            }
        }

        return $bestParent;
    }

    /**
     * L4: ищем открытые заявки по адресам получателей. Берём первый адрес,
     * по которому есть ровно 1 открытая Request. Если таких уникальных
     * привязок нет, но есть множественный матч — берём самую свежую и
     * логируем WARNING (РОП может вручную переподчинить через UI, если
     * оказалось не то).
     */
    private function matchByOpenRequestForRecipients(EmailMessage $message): ?Request
    {
        $emails = $this->extractRecipientEmails($message);
        if (empty($emails)) {
            return null;
        }

        $openStatuses = [
            RequestStatus::Pending->value,
            RequestStatus::New->value,
            RequestStatus::Assigned->value,
        ];

        $candidates = Request::query()
            ->whereIn('client_email', $emails)
            ->whereIn('status', $openStatuses)
            ->orderByDesc('created_at')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // 2+ кандидата: при отсутствии header-threading это плохой сигнал
        // — оператор мог писать «вообще» этому клиенту. Берём самую свежую,
        // помечаем suspicious. РОП увидит выбор в табе «Активность» (через
        // тред) и при ошибке использует «Переподчинить» (Phase 1.13).
        $picked = $candidates->first();

        Log::warning('OutgoingMailLinker: ambiguous outbound recipient match — picked latest', [
            'email_message_id' => $message->id,
            'recipient_emails' => $emails,
            'candidate_request_ids' => $candidates->pluck('id')->all(),
            'picked_request_id' => $picked->id,
        ]);

        return $picked;
    }

    /**
     * Все email-адреса из to_recipients и cc_recipients (jsonb массивы
     * `[{email, name}, ...]`). Нормализованные lowercase, без дубликатов.
     *
     * @return array<int, string>
     */
    private function extractRecipientEmails(EmailMessage $message): array
    {
        $emails = [];
        foreach ([(array) $message->to_recipients, (array) $message->cc_recipients] as $list) {
            foreach ($list as $entry) {
                $email = is_array($entry) ? ($entry['email'] ?? null) : null;
                if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = mb_strtolower(trim($email));
                }
            }
        }

        return array_values(array_unique($emails));
    }

    private function normalize(string $id): string
    {
        return trim($id, " \t\n\r\0\x0B<>");
    }
}
