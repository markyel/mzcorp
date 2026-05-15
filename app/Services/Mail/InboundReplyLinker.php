<?php

namespace App\Services\Mail;

use App\Enums\EmailCategory;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\Request\RequestStateService;
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
 *   3.5 External codes (LZ-REQ-NNNN и др.) — маркеры партнёрских систем
 *       (Liftway-saas). Через regex из `config('services.mail.external_codes')`.
 *       Находит самое раннее EmailMessage с тем же маркером и связанным Request.
 *       Решает: (а) дубль-привязку «напоминаний» партнёрской системы к случайной
 *       open Request клиента; (б) дедупликацию копий одного письма, разосланного
 *       по нескольким нашим внутренним адресам.
 *   4. From_email + open Requests этого клиента — для случаев, когда headers
 *      потеряны вовсе (mobile-клиент, ручной forward, новый thread без citing).
 *      Гейтим по category Phase 1.8c: client_request не линкуем (это новая
 *      заявка). При 1 открытой → она же; при 2+ → передаём в уровень 5.
 *   5. AI multi-choice (`ThreadClarificationAi`, gpt-4o-mini) — если у клиента
 *      2+ открытых Request, GPT смотрит на тело письма + список тем и выбирает
 *      наиболее подходящую (или null = новая тема). На сбое — fallback на самую
 *      свежую. Source: LazyLift n8n workflow `AI Agent: Process Clarification`.
 *
 * Если совпадение найдено — `related_request_id` нового письма ставится
 * на ту же Request, и MailRouter дальше его обрабатывает идемпотентно
 * (IncomingMailProcessor::processIfRequest вернёт раньше из-за уже
 * проставленного related_request_id и не создаст новую заявку, а
 * ParseRequestItemsJob тоже не дёргается).
 */
class InboundReplyLinker
{
    public function __construct(
        private readonly ThreadClarificationAi $clarifier,
        private readonly RequestStateService $stateService,
        private readonly \App\Services\Request\RequestActivityService $activity,
    ) {
    }

    /**
     * Lookback для реанимации по from_email (level 4-bis).
     * Header-threading (levels 1-3) реанимирует без ограничения по сроку —
     * там явный thread-link и контекст важнее.
     */
    private const REANIMATE_FROM_EMAIL_LOOKBACK_DAYS = 180;

    /**
     * Foundation §5.2: реанимация по from_email допустима только для
     * «тихих» причин закрытия. Декларативные decline'ы (price/timing/
     * competitor) НЕ реанимируем по этому уровню — клиент явно отказался,
     * новое письмо скорее всего другая тема (создастся новая Request).
     * Декларативные decline'ы ВСЁ ЕЩЁ реанимируются по header-threading
     * (levels 1-3) — там есть прямой message_id link.
     */
    private const REANIMATE_FROM_EMAIL_SILENT_REASONS = [
        'no_client_response_to_clarification',
        'no_client_response_to_quote',
        'manual_other',
        'off_topic',
    ];

    /**
     * @return Request|null  null = thread не найден, обычный flow.
     */
    public function tryLink(EmailMessage $message): ?Request
    {
        // Уже привязан — ничего не делаем.
        if ($message->related_request_id) {
            return Request::find($message->related_request_id);
        }

        // Level 0: cross-mailbox дедуп по Message-ID. Одно и то же физическое
        // письмо может появиться в двух Mailbox'ах — например, мы APPEND'ули
        // оригинал в личный ящик менеджера (DeliverToManagerInboxJob),
        // и IMAP sync личного ящика создал новый EmailMessage с тем же
        // message_id, но другим mailbox_id. Уникальность БД
        // (mailbox_id, folder, message_id) разрешает оба row'а, но это —
        // ОДИН клиентский запрос, не два. Если у нас уже есть запись с
        // тем же message_id и related_request_id — линкуем эту копию туда
        // же, не создаём дубль Request.
        if ($message->message_id) {
            $sameIdLinked = EmailMessage::query()
                ->where('message_id', $message->message_id)
                ->where('id', '!=', $message->id)
                ->whereNotNull('related_request_id')
                ->orderBy('id') // самая ранняя — родительская запись
                ->first();
            if ($sameIdLinked) {
                $request = Request::find($sameIdLinked->related_request_id);
                if ($request) {
                    $message->forceFill([
                        'related_request_id' => $request->id,
                    ])->save();

                    Log::info('InboundReplyLinker: linked by same message_id (cross-mailbox dedup)', [
                        'email_message_id' => $message->id,
                        'parent_email_message_id' => $sameIdLinked->id,
                        'request_id' => $request->id,
                        'message_id' => $message->message_id,
                    ]);

                    return $request;
                }
            }
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

        // Уровень 3.5: маркеры партнёрских систем (LZ-REQ-NNNN и т.п.) —
        // см. config('services.mail.external_codes').
        if (! $matched) {
            $matched = $this->matchByExternalCode($message);
            $matchedBy = $matched ? 'external_code' : null;
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

        // Foundation §5.2: реанимация закрытых заявок.
        // Header-threading (levels 1-3) — реанимируем любой closed_lost,
        // потому что есть явный thread-link.
        // From_email-match (level 4-bis) — уже отфильтрован в matchByOpenRequestForFromEmail
        // (только silent reasons + lookback).
        // ClosedWon никогда не реанимируем — там сделка состоялась, новое
        // письмо обрабатывается как новая заявка.
        if ($request->status === RequestStatus::ClosedLost) {
            try {
                $request = $this->stateService->reanimate($request, null, $message);
                $matchedBy = ($matchedBy ?? 'unknown') . ':reanimated';
            } catch (\Throwable $e) {
                Log::warning('InboundReplyLinker: reanimate failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($request->status === RequestStatus::ClosedWon) {
            // Не реанимируем won — это новый запрос от клиента.
            return null;
        }

        $message->forceFill(['related_request_id' => $request->id])->save();

        // Pool: входящее от клиента → ClientReplied (требует внимания).
        // sent_at — стабильный порядок при бэкфилле/перезапуске sync'а.
        $this->activity->touch(
            $request,
            \App\Enums\RequestActivityType::ClientReplied,
            $message->sent_at ?: now(),
        );

        Log::info('InboundReplyLinker: linked to existing Request', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'matched_by' => $matchedBy,
            'matched_email_id' => $matched?->id,
            'reanimated_count' => $request->reanimated_count,
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

    /**
     * Уровень 3.5: маркеры партнёрских систем.
     *
     * Извлекаем все маркеры по конфигурируемым regex-паттернам из subject+body.
     * Для каждого маркера ищем самое раннее EmailMessage с тем же маркером и
     * непустым related_request_id — это «правильный родитель».
     *
     * Идея: 6 копий одного письма с `LZ-REQ-1208` (рассылка партнёра на наши
     * адреса) → первое создаст Request, остальные 5 через этот уровень
     * прицепятся к нему. То же — для напоминаний без header threading.
     */
    private function matchByExternalCode(EmailMessage $message): ?EmailMessage
    {
        $patterns = (array) config('services.mail.external_codes', []);
        if (empty($patterns)) {
            return null;
        }

        $haystack = ((string) $message->subject) . "\n" . ((string) $message->body_plain);
        $haystack = trim($haystack);
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

        // Сначала ищем самое раннее письмо для каждого маркера. Из всех
        // найденных родителей берём тот, что в самой ранней Request (id ASC).
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

    private function normalize(string $id): string
    {
        return trim($id, " \t\n\r\0\x0B<>");
    }

    /**
     * Уровень 4 + 5: пробуем угадать thread по from_email + открытые Request клиента.
     *
     * Логика:
     *   - Категоризатор Phase 1.8c сказал client_request → точно НЕ линкуем
     *     (это новая заявка, возможно параллельная к открытым).
     *   - Если category=thread_reply ИЛИ subject начинается с Re:/Fwd:/Fw:
     *     — это reply. Ищем открытые заявки этого клиента:
     *        · 0 → null (новая заявка с reply-subject, бывает от мобилок);
     *        · 1 → возвращаем эту;
     *        · 2+ → 5-й уровень: AI clarifier выбирает наиболее подходящую
     *          (или возвращает null если AI считает что это новая тема).
     *   - Иначе — не линкуем.
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

        $candidates = Request::query()
            ->where('client_email', $message->from_email)
            ->whereIn('status', $openStatuses)
            ->orderByDesc('created_at')
            ->get();

        if ($candidates->isNotEmpty()) {
            if ($candidates->count() === 1) {
                return $candidates->first();
            }

            // 5-й уровень: AI multi-choice. На сбое clarifier возвращает либо
            // самую свежую (fallback), либо null (если AI решил что это новая тема).
            return $this->clarifier->chooseRequest($message, $candidates);
        }

        // Foundation §5.2: level 4-bis — reanimate closed_lost.
        // Только если у клиента нет ОТКРЫТЫХ заявок (иначе приоритет
        // у open). Только silent reasons. Только за последние N дней.
        // Это спасает кейс «клиент молчал 30 дней после КП → no_client_response_to_quote
        // → через 2 недели передумал, написал → реанимируем ту же заявку».
        $lookbackDate = now()->subDays(self::REANIMATE_FROM_EMAIL_LOOKBACK_DAYS);
        $closedCandidate = Request::query()
            ->where('client_email', $message->from_email)
            ->where('status', RequestStatus::ClosedLost->value)
            ->whereIn('closed_lost_reason', self::REANIMATE_FROM_EMAIL_SILENT_REASONS)
            ->where('closed_at', '>=', $lookbackDate)
            ->orderByDesc('closed_at')
            ->first();

        return $closedCandidate;
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
