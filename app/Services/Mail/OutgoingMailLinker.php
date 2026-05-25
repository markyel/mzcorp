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
     * L4: ищем открытые заявки по адресам получателей.
     *
     * 2026-05-22: переписано после кейса mbox=6 (alexander.rodenkov, см.
     * MEMORY.md «Phantom outbound через L4»). Старая логика была слишком
     * жадной — 62 outbound-письма директора прилипли к 10 Request через
     * совпадение to/cc и client_email коллеги (`@myzip.ru` как client_email
     * или supplier-домен у фантомной Request).
     *
     * Новые guard'ы (все обязательны):
     *  1. Recipient-фильтр: исключаем внутренние адреса из to/cc через
     *     `InternalSenderDetector::detect`. Письмо коллеге не должно
     *     привязываться к заявке только потому, что у заявки случайно
     *     client_email = этот коллега.
     *  2. Time-window: только заявки, созданные в окне последних N дней
     *     (`config('services.mail.outbound_link_window_days')`, default 90).
     *     Outbound на адрес «древнего» клиента — почти всегда новый thread.
     *  3. Refuse-on-ambiguity: при 2+ кандидатах — null + WARNING.
     *     Старая логика тихо брала «самую свежую», что создаёт ложные связи.
     *     РОП руками решит, если оператору это нужно.
     */
    private function matchByOpenRequestForRecipients(EmailMessage $message): ?Request
    {
        $emails = $this->extractRecipientEmails($message);
        if (empty($emails)) {
            return null;
        }

        // Guard-0: outbound — reply к НЕИЗВЕСТНОМУ нам треду.
        // Если in_reply_to / references указаны (это НЕ свежий compose, а
        // продолжение чужого треда), но ни одного из этих message_id нет
        // в нашей БД — мы НЕ видели этот тред. L4 fallback по получателю
        // создаст false-link на любую открытую заявку клиента (типичный
        // кейс M-2026-1549: Yakubovich ответил на «Фотобарьер 356106»,
        // тред не реплицирован, L4 приклеил КП к «Блок управления БУТ-01»
        // того же клиента). Лучше оставить outbound без привязки.
        //
        // Свежий compose (новый thread от менеджера) — in_reply_to пуст,
        // этот guard ничего не блокирует, L4 работает как раньше.
        $candidateIds = $this->collectCandidateMessageIds($message);
        if (!empty($candidateIds)) {
            $knownAny = EmailMessage::query()
                ->whereIn('message_id', $candidateIds)
                ->exists();
            if (!$knownAny) {
                Log::warning('OutgoingMailLinker L4: outbound replies to unknown thread, skip', [
                    'email_message_id' => $message->id,
                    'in_reply_to' => $message->in_reply_to,
                    'recipients' => $emails,
                    'candidate_ids_unmatched' => $candidateIds,
                ]);
                return null;
            }
        }

        // Guard-1: убрать внутренние/наши адреса из набора получателей.
        // Если после фильтра ничего не осталось — это внутренняя переписка,
        // не привязываем.
        $externalRecipients = $this->filterExternal($emails);
        if (empty($externalRecipients)) {
            Log::debug('OutgoingMailLinker L4: all recipients internal, skip', [
                'email_message_id' => $message->id,
                'recipients' => $emails,
            ]);
            return null;
        }

        $openStatuses = [
            RequestStatus::Pending->value,
            RequestStatus::New->value,
            RequestStatus::Assigned->value,
        ];

        // Guard-2: time-window. По умолчанию 90 дней.
        $windowDays = (int) config('services.mail.outbound_link_window_days', 90);
        $cutoff = $windowDays > 0 ? now()->subDays($windowDays) : null;

        $query = Request::query()
            ->whereIn('client_email', $externalRecipients)
            ->whereIn('status', $openStatuses);
        if ($cutoff !== null) {
            $query->where('created_at', '>=', $cutoff);
        }
        $candidates = $query->orderByDesc('created_at')->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // Guard-3: 2+ кандидата — отказ. Не угадываем «самую свежую».
        // Лучше оставить outbound без привязки и попросить РОПа руками,
        // чем тихо создать ложную связь, которая потом распространяется
        // через References-каскад в L1/L2.
        Log::warning('OutgoingMailLinker L4: ambiguous, refusing to auto-link', [
            'email_message_id' => $message->id,
            'recipients' => $externalRecipients,
            'candidate_request_ids' => $candidates->pluck('id')->all(),
        ]);

        return null;
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
