<?php

namespace App\Services\Mail;

use App\Enums\EmailCategory;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound thread linking (Phase 1.9 inbound-часть).
 *
 * Когда приходит ответное письмо (Re:/Fwd: или продолжение диалога с клиентом),
 * нужно прицепить его к существующей Request, а не создавать дубликат.
 *
 * Многоуровневый matcher (приоритет ↓):
 *   1. In-Reply-To       — точное совпадение со сохранённым EmailMessage.message_id.
 *   2. References        — любой ID в цепочке (поиск с конца — самый свежий первым).
 *   3. Subject internal_code `M-2026-NNNN`  — safety net для Fwd / поломанных headers.
 *   4. From_email + open Requests этого клиента — для случаев, когда headers
 *      потеряны вовсе (mobile-клиент, ручной forward, новый thread без citing).
 *      Гейтим по category Phase 1.8c: client_request не линкуем (это новая
 *      заявка, у клиента может быть параллельно несколько).
 *
 * Если совпадение найдено — `related_request_id` нового письма ставится
 * на ту же Request, и MailRouter дальше его обрабатывает идемпотентно
 * (IncomingMailProcessor::processIfRequest вернёт раньше из-за уже
 * проставленного related_request_id и не создаст новую заявку, а
 * ParseRequestItemsJob тоже не дёргается).
 */
class InboundReplyLinker
{
    /**
     * @return Request|null  null = thread не найден, обычный flow.
     */
    public function tryLink(EmailMessage $message): ?Request
    {
        // Уже привязан — ничего не делаем.
        if ($message->related_request_id) {
            return Request::find($message->related_request_id);
        }

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

        if (! $matched) {
            $matched = $this->matchBySubjectCode($message);
            $matchedBy = $matched ? 'subject_internal_code' : null;
        }

        // Уровень 4: по from_email + open Requests того же клиента.
        // Возвращает Request напрямую (не EmailMessage) — у этого уровня
        // другая семантика matched-объекта.
        $directRequest = null;
        if (! $matched) {
            $directRequest = $this->matchByOpenRequestForFromEmail($message);
            $matchedBy = $directRequest ? 'from_email_open_request' : null;
        }

        // Унифицируем: либо $matched (EmailMessage с related_request_id),
        // либо $directRequest (Request напрямую).
        $request = $directRequest
            ?: ($matched && $matched->related_request_id ? Request::find($matched->related_request_id) : null);

        if (! $request) {
            return null;
        }

        $message->forceFill(['related_request_id' => $request->id])->save();

        Log::info('InboundReplyLinker: linked to existing Request', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'matched_by' => $matchedBy,
            'matched_email_id' => $matched?->id,
        ]);

        return $request;
    }

    /**
     * Собрать всех кандидатов на match: in_reply_to + references_header.
     * References — массив (cast в EmailMessage), идём с конца (свежие первыми).
     *
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
     * Subject-парсинг как safety net: ищем `M-2026-NNNN` в subject ИЛИ body
     * (Fwd часто оборачивает оригинал в body, subject теряется).
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

    private function normalize(string $id): string
    {
        return trim($id, " \t\n\r\0\x0B<>");
    }

    /**
     * Уровень 4: пробуем угадать thread по from_email + открытые Request клиента.
     *
     * Логика:
     *   - Если категоризатор Phase 1.8c сказал client_request → точно НЕ линкуем
     *     (это новая заявка от клиента, возможно у него уже есть открытые,
     *     но это другая тема).
     *   - Если категория thread_reply ИЛИ subject начинается с Re:/Fwd:/Fw: —
     *     это reply, ищем открытые заявки этого клиента и берём самую свежую
     *     по created_at (последний контакт = последний разговор).
     *   - Иначе (новый subject, не reply, не thread_reply) — не линкуем.
     *
     * Эвристика «самая свежая» неидеальна, если у клиента параллельно идут
     * 2+ заявки и он отвечает на старую. Но для типичного потока (5-10
     * клиентов, 1-2 открытые на каждого) даёт правильный matching в 80%+.
     * Дальнейшее уточнение — AI multi-choice (5-й уровень) — отложено
     * до момента когда мы получим n8n workflow JSON для эталона.
     */
    private function matchByOpenRequestForFromEmail(EmailMessage $message): ?Request
    {
        if (! $message->from_email) {
            return null;
        }

        // Жёсткий блок: AI говорит «это новая заявка» — верим.
        if ($message->category === EmailCategory::ClientRequest->value) {
            return null;
        }

        $isReply = $this->subjectLooksLikeReply((string) $message->subject);
        $isThreadReply = $message->category === EmailCategory::ThreadReply->value;

        if (! $isReply && ! $isThreadReply) {
            return null;
        }

        $openStatuses = [
            RequestStatus::Pending->value,
            RequestStatus::New->value,
            RequestStatus::Assigned->value,
        ];

        return Request::query()
            ->where('client_email', $message->from_email)
            ->whereIn('status', $openStatuses)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Subject «Re:», «Fwd:», «Fw:» (любой регистр / без пробела после двоеточия).
     */
    private function subjectLooksLikeReply(string $subject): bool
    {
        if ($subject === '') {
            return false;
        }

        return preg_match('/^\s*(re|fwd|fw)\s*:/iu', $subject) === 1;
    }
}
