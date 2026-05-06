<?php

namespace App\Prompts\Mail;

use App\Models\EmailMessage;

/**
 * Промпт классификации входящего письма для gpt-4o-mini.
 *
 * Foundation §2.4: модель должна вернуть один из 6 классов:
 *   request | reclamation | accounting | general_question | spam | other
 *
 * Используем JSON-режим (response_format=json_object), чтобы парсить ответ
 * детерминированно. Дополнительно просим уверенность (confidence 0..1)
 * и кратко обоснование (reason) — для UI «AI quality dashboard» Phase 6.
 *
 * Промпт на русском, потому что 99% писем — на русском.
 */
class ClassifyIncomingPrompt
{
    private const MAX_BODY_CHARS = 8000;

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function build(EmailMessage $message): array
    {
        $body = (string) ($message->body_plain ?: strip_tags((string) $message->body_html));
        $body = mb_substr(trim($body), 0, self::MAX_BODY_CHARS);

        $userContent = "Тема: " . ($message->subject ?: '(без темы)') . "\n"
            . "От: " . ($message->from_name ? "{$message->from_name} <{$message->from_email}>" : $message->from_email) . "\n"
            . "Дата: " . ($message->sent_at?->toDateTimeString() ?? 'неизвестно') . "\n"
            . "----\n"
            . ($body !== '' ? $body : '(пустое тело)');

        return [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $userContent],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
        Ты — классификатор входящих email-писем для отдела продаж B2B-дистрибьютора лифтовых запчастей MyLift / MyZip.

        Твоя задача — определить класс письма. Выбери ровно один из:
        - request           : клиент просит запчасти, спрашивает наличие/цены, RFQ. Письма с фразами «прошу подобрать», «нужны запчасти», «запрос», «заявка», «КП на» относятся сюда.
        - reclamation       : клиент жалуется на брак, требует возврат, эскалирует претензию. Слова «рекламация», «претензия», «возврат», «брак», «не работает».
        - accounting        : письма от бухгалтерии: счета-фактуры, акты сверки, УПД, подтверждения оплаты, баланс. Часто из дочерних/партнёрских компаний.
        - general_question  : общий нетоварный вопрос (например, «как у вас оформить договор», «какие условия доставки»). Не заявка.
        - spam              : маркетинг, рассылки, реклама услуг, фишинг.
        - other             : ничего из вышеперечисленного, либо служебные/системные уведомления (логистика, сервис-провайдеры).

        Внимательно смотри на тему ПЕРВЫМ ДЕЛОМ. Если в теме «заявка» / «запрос» / «RFQ» / «КП» — это почти всегда request. Если «акт сверки» / «счёт-фактура» / «оплата» — accounting. Если «рекламация» / «претензия» — reclamation.

        Если уверенности нет — выбирай other.

        Ответ: ТОЛЬКО валидный JSON-объект:
        {"classification":"<один из 6 классов>","confidence":<0..1>,"reason":"<кратко на русском, до 80 символов>"}

        Никаких пояснений, markdown, кода — только JSON.
        PROMPT;
    }
}
