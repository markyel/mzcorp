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
     * Внутренняя ли ПЕРЕПИСКА целиком: отправитель И все получатели (to+cc) —
     * наши. Такое письмо — общение сотрудников про заявку, оно НЕ должно
     * влиять на статус/позиции заявки (влияют только письма, где хотя бы на
     * одной стороне заказчик). Кейс M-2026-6071: письмо руководителя менеджеру
     * (оба @myzip.ru) авто-переводило статус в «На согласовании».
     *
     * Пустой список получателей → НЕ считаем internal-only (нечего проверить,
     * безопаснее пропустить дальше по обычному пути).
     */
    public function isInternalOnly(EmailMessage $message): bool
    {
        $from = mb_strtolower(trim((string) $message->from_email));
        if ($from === '' || $this->internalReason($from) === null) {
            return false; // отправитель внешний → письмо касается внешней стороны
        }

        $recipients = [];
        foreach ([$message->to_recipients, $message->cc_recipients] as $bag) {
            foreach ((array) $bag as $r) {
                $email = is_array($r) ? ($r['email'] ?? '') : $r;
                $email = mb_strtolower(trim((string) $email));
                if ($email !== '') {
                    $recipients[] = $email;
                }
            }
        }
        if ($recipients === []) {
            return false;
        }

        foreach ($recipients as $email) {
            // Хоть один внешний получатель (в т.ч. allowlist — order@ и пр.,
            // который представляет клиента) → письмо касается внешней стороны.
            if ($this->inAllowlist($email) || $this->internalReason($email) === null) {
                return false;
            }
        }

        return true;
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
