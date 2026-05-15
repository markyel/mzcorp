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

        // 1. Domain match.
        $domains = (array) config('services.mail.internal_domains', []);
        foreach ($domains as $d) {
            $d = mb_strtolower(trim((string) $d));
            if ($d === '') {
                continue;
            }
            if (str_ends_with($from, '@' . $d)) {
                return 'domain:' . $d;
            }
        }

        // 2. Mailbox match (наши OAuth-подключённые ящики).
        if (Mailbox::query()->whereRaw('LOWER(email) = ?', [$from])->exists()) {
            return 'mailbox';
        }

        // 3. User match (наши пользователи системы — кто-то из коллег).
        if (User::query()->whereRaw('LOWER(email) = ?', [$from])->exists()) {
            return 'user';
        }

        return null;
    }
}
