<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\User;

/**
 * Детектор внутренних отправителей.
 *
 * Бизнес-кейс: M-2026-0161 — наш сотрудник `alexander.rodenkov@myzip.ru`
 * прислал внутреннее сообщение в общий ящик `mail@myzip.ru`, gpt-4o
 * категоризовал его как `client_request`, IncomingMailProcessor создал
 * Request, AssignmentService назначил менеджера. Запись бессмысленная —
 * это внутренняя переписка, не клиентская заявка.
 *
 * Логика: from_email относится к нам, если хотя бы одно из:
 *   - домен совпадает с `config('services.mail.internal_domains')`
 *     (default `['myzip.ru']`);
 *   - email совпадает (case-i) с любым `Mailbox.email`
 *     (наши OAuth-подключённые ящики);
 *   - email совпадает (case-i) с любым `User.email`
 *     (наши пользователи системы).
 *
 * Используется в `MailCategoryClassifier::categorize()` как pre-classifier
 * short-circuit ДО LLM-вызова: если внутренний — принудительно `irrelevant`,
 * confidence=1.0. Никакой LLM не нужен — это детерминированно.
 */
class InternalSenderDetector
{
    /**
     * @return string|null  Причина (`domain:myzip.ru` / `mailbox` / `user`)
     *                       или null если отправитель внешний.
     */
    public function detect(EmailMessage $message): ?string
    {
        $from = mb_strtolower(trim((string) $message->from_email));
        if ($from === '') {
            return null;
        }

        // 0. Allowlist — техническая автоматика на нашем домене, которая
        // не является «сотрудником» и должна пропускаться дальше
        // (категоризатор сам решит client_request / irrelevant).
        // Типовой кейс: order@myzip.ru — web-form ящик сайта, шлёт заявки
        // клиентов через нашу почту. Без allowlist'а doman match банил их
        // как «внутренние», заявки терялись.
        if ($this->inAllowlist($from)) {
            return null;
        }

        return $this->internalReason($from);
    }

    /**
     * Должно ли inbound-письмо влиять на статус/позиции заявки. Правило
     * (по требованию заказчика): влияют только письма, где заказчик на одной
     * из сторон (отправитель ИЛИ получатель). Внутренняя переписка сотрудников
     * — нет, даже если в CC стоит личный внешний адрес коллеги.
     *
     *   - Отправитель ВНЕШНИЙ → влияет (это заказчик или его коллега).
     *   - Отправитель НАШ (сотрудник) → влияет ТОЛЬКО если заказчик заявки
     *     (clientEmail) явно среди получателей to/cc. Иначе это внутреннее
     *     общение про заявку — не трогаем.
     *
     * Кейс M-2026-6071: письмо руководителя менеджеру (from/to @myzip.ru, в CC
     * личный markyellow@yandex.ru — не заказчик) авто-переводило «КП отправлено»
     * → «На согласовании». clientEmail пуст → false (не можем подтвердить
     * заказчика при нашем отправителе → безопаснее не трогать статус).
     */
    public function affectsRequestStatus(EmailMessage $message, ?string $clientEmail): bool
    {
        // Внешний отправитель — вероятно заказчик (или его коллега/альт-адрес).
        if ($this->detect($message) === null) {
            return true;
        }

        // Отправитель наш → влияет только если заказчик среди получателей.
        $client = mb_strtolower(trim((string) $clientEmail));
        if ($client === '') {
            return false;
        }

        return in_array($client, $this->recipientEmails($message), true);
    }

    /**
     * @return array<int, string> lowercased to+cc адреса
     */
    private function recipientEmails(EmailMessage $message): array
    {
        $out = [];
        foreach ([$message->to_recipients, $message->cc_recipients] as $bag) {
            foreach ((array) $bag as $r) {
                $email = mb_strtolower(trim((string) (is_array($r) ? ($r['email'] ?? '') : $r)));
                if ($email !== '') {
                    $out[] = $email;
                }
            }
        }

        return $out;
    }

    private function inAllowlist(string $email): bool
    {
        $allowlist = array_filter(array_map(
            fn ($e) => mb_strtolower(trim((string) $e)),
            (array) config('services.mail.internal_sender_allowlist', [])
        ));

        return in_array($email, $allowlist, true);
    }

    /**
     * Причина, по которой адрес считается нашим (`domain:x` / `mailbox` /
     * `user`), либо null если внешний. Allowlist здесь НЕ учитывается —
     * вызывающий решает сам (detect короткозамыкает, isInternalOnly трактует
     * allowlist-адрес как внешнюю сторону).
     */
    private function internalReason(string $email): ?string
    {
        // 1. Domain match.
        $domains = (array) config('services.mail.internal_domains', []);
        foreach ($domains as $d) {
            $d = mb_strtolower(trim((string) $d));
            if ($d === '') {
                continue;
            }
            if (str_ends_with($email, '@' . $d)) {
                return 'domain:' . $d;
            }
        }

        // 2. Mailbox match (наши OAuth-подключённые ящики).
        if (Mailbox::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return 'mailbox';
        }

        // 3. User match (наши пользователи системы — кто-то из коллег).
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return 'user';
        }

        return null;
    }
}
