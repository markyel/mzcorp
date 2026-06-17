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

    /**
     * @param  bool  $allowFuzzyRecipientMatch  Разрешить L4 (привязка по
     *   получателю + subject-similarity). Для авто-перепривязки в фоне
     *   (`mail:relink-deferred-outbound`) передаём false: линкуем ТОЛЬКО по
     *   детерминированным заголовкам/коду (L1/L2/L3/L3.5), без шаткого L4 —
     *   ошибочная авто-привязка к не той заявке хуже, чем оставить в триаже.
     */
    public function tryLink(EmailMessage $message, bool $allowFuzzyRecipientMatch = true): ?Request
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

        // L4: to_recipients + open Requests клиента (fuzzy, по subject-similarity).
        if (! $request && $allowFuzzyRecipientMatch) {
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
     * L4: ищем НЕ-архивные заявки клиента по получателям и subject-similarity.
     *
     * 2026-05-25: переписано после кейса M-2026-1549 (Yakubovich-ответ на
     * Fwd «Фотобарьер 356106» приклеился к «Блок управления БУТ-01» того же
     * клиента). Правильная заявка про «Фотобарьер» (M-2026-1437) оказалась
     * `closed_lost`, единственная открытая 1549 была про другой товар.
     * Старый L4 брал её как единственного кандидата — false-link с каскадом
     * в AiDecision «КП отправлено».
     *
     * Новая логика:
     *  1. Recipient-фильтр: убираем внутренние адреса из to/cc.
     *  2. Кандидаты — НЕ-архивные заявки клиента (`!isTerminal()`).
     *     Архивные closed_won / closed_lost не рассматриваются: если КП
     *     ушло по архивной заявке — это новый тред, менеджер привяжет
     *     вручную / переоткроет старую.
     *  3. Subject-similarity (Jaccard на токенах после нормализации) ранжирует
     *     кандидатов. Линкуем ТОЛЬКО при similarity ≥ порога
     *     (`outbound_link_subject_similarity_threshold`, default 0.5).
     *     Ниже порога — refuse (не угадываем «самую свежую»).
     *
     * 2026-05-22 guards про phantom-outbound (mbox=6 alexander.rodenkov) и
     * Refuse-on-ambiguity сохраняются естественным образом: внутренние
     * получатели отфильтрованы (1), низкий similarity при разных subject
     * автоматически отсекает мисматчи (3).
     */
    private function matchByOpenRequestForRecipients(EmailMessage $message): ?Request
    {
        $emails = $this->extractRecipientEmails($message);
        if (empty($emails)) {
            return null;
        }

        // Guard-1: убрать внутренние/наши адреса из набора получателей.
        $externalRecipients = $this->filterExternal($emails);
        if (empty($externalRecipients)) {
            Log::debug('OutgoingMailLinker L4: all recipients internal, skip', [
                'email_message_id' => $message->id,
                'recipients' => $emails,
            ]);
            return null;
        }

        // Не-архивные кандидаты клиента.
        $archivedStatuses = [
            RequestStatus::ClosedWon->value,
            RequestStatus::ClosedLost->value,
        ];

        $candidates = Request::query()
            ->whereIn('client_email', $externalRecipients)
            ->whereNotIn('status', $archivedStatuses)
            ->orderByDesc('created_at')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Score каждого кандидата subject-similarity'ем.
        $outboundTokens = $this->normalizeSubjectTokens((string) $message->subject);
        if (empty($outboundTokens)) {
            Log::debug('OutgoingMailLinker L4: outbound subject normalized to empty, skip', [
                'email_message_id' => $message->id,
                'subject' => $message->subject,
            ]);
            return null;
        }

        $scored = [];
        foreach ($candidates as $c) {
            $candidateTokens = $this->normalizeSubjectTokens((string) $c->subject);
            $similarity = $this->jaccard($outboundTokens, $candidateTokens);
            $scored[] = ['request' => $c, 'similarity' => $similarity];
        }
        usort($scored, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        $best = $scored[0];

        $threshold = (float) config('services.mail.outbound_link_subject_similarity_threshold', 0.5);

        if ($best['similarity'] < $threshold) {
            Log::warning('OutgoingMailLinker L4: best candidate subject below threshold, refuse', [
                'email_message_id' => $message->id,
                'recipients' => $externalRecipients,
                'best_request_id' => $best['request']->id,
                'best_similarity' => $best['similarity'],
                'threshold' => $threshold,
                'candidates' => array_map(
                    fn ($s) => ['id' => $s['request']->id, 'sim' => round($s['similarity'], 3), 'subj' => mb_substr((string) $s['request']->subject, 0, 50)],
                    $scored,
                ),
            ]);
            return null;
        }

        Log::info('OutgoingMailLinker L4: subject-similarity matched', [
            'email_message_id' => $message->id,
            'request_id' => $best['request']->id,
            'similarity' => $best['similarity'],
            'threshold' => $threshold,
        ]);

        return $best['request'];
    }

    /**
     * Нормализация subject в набор токенов для Jaccard.
     *
     *   - lowercase
     *   - убираем префиксы Re:/Fwd:/Re[2]:/Fw: (рекурсивно)
     *   - убираем [...] квадратные префиксы (типа [MyLift forward])
     *   - разбиваем на токены по пробелам и пунктуации
     *   - откидываем токены короче 3 символов (стопворды + союзы естественно)
     *   - откидываем чисто-числовые токены > 4 символов (это коды
     *     заказов / serial / external trackers, дают ложные совпадения)
     *
     * @return array<int, string>
     */
    private function normalizeSubjectTokens(string $subject): array
    {
        $s = mb_strtolower(trim($subject));
        if ($s === '') {
            return [];
        }

        // Рекурсивно режем префиксы.
        $prev = null;
        while ($prev !== $s) {
            $prev = $s;
            $s = preg_replace('/^(re|fw|fwd|forward|re\[\d+\])\s*:\s*/u', '', $s);
            $s = preg_replace('/^\[[^\]]*\]\s*/u', '', $s);
            $s = trim($s);
        }

        // Разбиваем на токены — буквы/цифры в Unicode.
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $clean = [];
        foreach ($tokens as $t) {
            if (mb_strlen($t) < 3) {
                continue;
            }
            // Чисто числовой токен длиной >4 — внешний код, мусор для сравнения.
            if (mb_strlen($t) > 4 && preg_match('/^\d+$/u', $t)) {
                continue;
            }
            $clean[$t] = true;
        }

        return array_keys($clean);
    }

    /**
     * Jaccard similarity = |A ∩ B| / |A ∪ B|.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        if (empty($a) || empty($b)) {
            return 0.0;
        }
        $setA = array_flip($a);
        $setB = array_flip($b);
        $inter = count(array_intersect_key($setA, $setB));
        $union = count($setA + $setB);
        return $union > 0 ? $inter / $union : 0.0;
    }

    /**
     * Оставить только внешние email-адреса (отбросить наши internal_domains,
     * наши Mailbox.email, наши User.email). Использует тот же детектор,
     * что и MailCategoryClassifier (через InternalSenderDetector::detect),
     * но напрямую — обёрткой над EmailMessage не пользуемся, тут только
     * адрес.
     *
     * @param  array<int, string>  $emails
     * @return array<int, string>
     */
    private function filterExternal(array $emails): array
    {
        $domains = (array) config('services.mail.internal_domains', []);
        $domains = array_values(array_filter(array_map(
            fn ($d) => mb_strtolower(trim((string) $d)),
            $domains,
        )));

        // Mailbox.email / User.email — наших адресов мало (5-10 mailbox'ов,
        // десятки User'ов), поэтому забираем всё разом и сравниваем
        // case-insensitive в PHP. Это надёжнее `whereIn(DB::raw('LOWER(email)'))`
        // и не зависит от SQL-collation. Memory-overhead ~1KB.
        $ourSet = [];
        foreach (\App\Models\Mailbox::query()->pluck('email') as $e) {
            $ourSet[mb_strtolower((string) $e)] = true;
        }
        foreach (\App\Models\User::query()->pluck('email') as $e) {
            $ourSet[mb_strtolower((string) $e)] = true;
        }

        $external = [];
        foreach ($emails as $e) {
            $eLower = mb_strtolower($e);
            if (isset($ourSet[$eLower])) {
                continue;
            }
            $isInternalDomain = false;
            foreach ($domains as $d) {
                if ($d !== '' && str_ends_with($eLower, '@' . $d)) {
                    $isInternalDomain = true;
                    break;
                }
            }
            if ($isInternalDomain) {
                continue;
            }
            $external[] = $eLower;
        }

        return array_values(array_unique($external));
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
