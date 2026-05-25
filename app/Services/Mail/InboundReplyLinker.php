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
     * Lookback для поиска closed_lost кандидата (Phase 2.1 наследование).
     * Кандидат — точка отсчёта для CheckInheritanceJob (LLM-проверка).
     */
    private const ARCHIVE_CANDIDATE_LOOKBACK_DAYS = 180;

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
            // Сначала смотрим всех parent'ов с этим Message-ID, чтобы отличить
            // три кейса: матч в активную Request / orphan-без-request_id /
            // parent-с-terminal-request.
            $parents = EmailMessage::query()
                ->whereIn('message_id', $candidateIds)
                ->orderByDesc('id')
                ->get();

            // Кейс 1 (happy path): parent есть и его Request не terminal —
            // привязываемся к ней.
            $matched = $parents
                ->first(function (EmailMessage $p): bool {
                    if ($p->related_request_id === null) {
                        return false;
                    }
                    $req = Request::find($p->related_request_id);
                    return $req && ! $req->status->isTerminal();
                });
            $matchedBy = $matched ? 'in_reply_to_or_references' : null;

            if (! $matched) {
                // Кейс 2: parent найден, но его Request в terminal (closed_won/
                // closed_lost). Это «реанимация» — клиент молчал, передумал.
                // В fallback `from_email_open_request` падать нельзя — оттуда
                // reply попадёт к ЧУЖОЙ открытой заявке клиента (кейс
                // M-2026-1558: reply на КП по закрытому КВШ-480 прицепился
                // к открытому Тросу того же клиента). Записываем inheritance
                // candidate (для UI «↻ Реанимировать»), возвращаем null.
                $terminalParent = $parents
                    ->first(function (EmailMessage $p): bool {
                        if ($p->related_request_id === null) {
                            return false;
                        }
                        $req = Request::find($p->related_request_id);
                        return $req && $req->status->isTerminal();
                    });
                if ($terminalParent) {
                    $terminalRequest = Request::find($terminalParent->related_request_id);
                    $this->rememberInheritanceCandidate(
                        $message,
                        $terminalRequest,
                        'in_reply_to_terminal_request',
                    );
                    Log::info('InboundReplyLinker: deferred — parent in terminal Request, inheritance candidate recorded', [
                        'email_message_id' => $message->id,
                        'parent_email_id' => $terminalParent->id,
                        'terminal_request_id' => $terminalRequest->id,
                        'terminal_status' => $terminalRequest->status->value,
                    ]);
                    return null;
                }

                // Кейс 3: parent есть, но БЕЗ related_request_id (parent
                // застрял в категоризации / упал OpenAI). НЕ падать в
                // fallback — ждём пока parent обработается, cron
                // `mail:relink-deferred` подберёт reply повторно.
                // Кейс 25.05 #3684 → M-2026-1654.
                $orphanParent = $parents->first(fn (EmailMessage $p): bool => $p->related_request_id === null);
                if ($orphanParent) {
                    Log::info('InboundReplyLinker: deferred — parent exists but not yet linked to Request', [
                        'email_message_id' => $message->id,
                        'orphan_parent_id' => $orphanParent->id,
                        'parent_message_id' => $orphanParent->message_id,
                        'parent_categorized_at' => $orphanParent->categorized_at,
                    ]);
                    return null;
                }
            }
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

        // Автоматическая реанимация закрытых заявок ОТКЛЮЧЕНА (Phase 1
        // нового поведения). Архивная заявка — это история; новый запрос
        // клиента не должен «оживлять» её автоматически.
        //
        // НО: само наличие thread-match'а — это сигнал что новая Request
        // может быть продолжением архивной. Сохраняем кандидата в
        // detected_artifacts.inheritance_candidate_id — после парсинга
        // позиций async-job CheckInheritanceJob проверит гипотезу через
        // LLM, и при подтверждении (confidence >= threshold) свяжет новую
        // Request с архивной через RequestInheritanceService::linkChild.
        //
        // Реальные кейсы продолжения (клиент молчал → передумал, просит
        // обновить КП) — обслуживаются именно так (наследование), плюс
        // ручная кнопка «↻ Реанимировать» (Phase 3).
        if ($request->status->isTerminal()) {
            $this->rememberInheritanceCandidate($message, $request, (string) $matchedBy);

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

        // Irrelevant — это категорически «не клиент». Чаще всего сюда
        // попадают supplier-reply на наши закупочные запросы, авто-ответы,
        // newsletter'ы. Level 4 (from_email + open Request) для них опасен:
        // supplier мог раньше писать нам по другому поводу, и его новый
        // reply прицепится к чужой клиентской заявке. Header threading
        // (levels 1-3) для irrelevant остаётся — там точное совпадение
        // по Message-ID, можно безопасно положить в наш тред.
        if ($message->category === EmailCategory::Irrelevant->value) {
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

        // Level 4-bis: если открытых заявок нет — ищем кандидата среди
        // закрытых (Phase 2.1 «наследование»). НЕ реанимируем —
        // tryLink() запишет hint, после парсинга позиций async LLM-check
        // решит, стоит ли линковать как наследника.
        //
        // Guards A/B остаются как фильтры качества кандидата: даже на
        // стадии «найти hint» массово-закрытые (Pre-launch cleanup) и
        // совсем другие subject'ы — не стоит подсовывать LLM.
        $lookbackDate = now()->subDays(self::ARCHIVE_CANDIDATE_LOOKBACK_DAYS);
        $closedCandidate = Request::query()
            ->where('client_email', $message->from_email)
            ->where('status', RequestStatus::ClosedLost->value)
            ->where('closed_at', '>=', $lookbackDate)
            ->orderByDesc('closed_at')
            ->first();

        if ($closedCandidate === null) {
            return null;
        }

        // Guard A: массово-закрытые без явного клиентского триггера —
        // не подсовываем LLM. Mass-closed (Pre-launch cleanup, cron-
        // recovery) — это административные действия без участия клиента,
        // наследовать от них бессмысленно: оригинальный контекст потерян
        // (closed_lost_source_message_id IS NULL).
        if ($closedCandidate->closed_lost_source_message_id === null) {
            return null;
        }

        // Guard B (subject mismatch) — СНЯТ. Изначально был эвристикой
        // экономии LLM-вызовов, но получилось слишком грубо: forward'ы
        // / новые темы того же клиента отрезались до LLM-проверки.
        // По дизайну наследования — LLM check должен решать по позициям
        // и контексту письма, не по subject (он часто не совпадает в
        // forward'ах). LLM-вызов дёшев (gpt-4o-mini), false-positive
        // случаи отсекутся уже при confidence-проверке (≥0.7).

        // Возвращаем кандидата — статус ClosedLost, поэтому tryLink()
        // пойдёт по terminal-ветке: запишет hint, вернёт null. Новая
        // Request будет создана IncomingMailProcessor'ом; CheckInheritanceJob
        // решит, линковать ли как наследника.
        return $closedCandidate;
    }

    /**
     * Phase 2.1 — записать кандидата в наследование (hint).
     * Дёргается из tryLink() когда matched-request имеет terminal статус.
     */
    private function rememberInheritanceCandidate(EmailMessage $message, Request $candidate, string $matchedBy): void
    {
        $artifacts = (array) ($message->detected_artifacts ?? []);
        $artifacts['inheritance_candidate_id'] = $candidate->id;
        $artifacts['inheritance_candidate_matched_by'] = $matchedBy;
        $message->forceFill(['detected_artifacts' => $artifacts])->save();

        Log::info('InboundReplyLinker: archive candidate remembered for inheritance check', [
            'email_message_id' => $message->id,
            'candidate_request_id' => $candidate->id,
            'candidate_status' => $candidate->status->value,
            'matched_by' => $matchedBy,
        ]);
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
