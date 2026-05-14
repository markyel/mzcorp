<?php

namespace App\Prompts\Mail;

use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;

/**
 * Промпт классификатора клиентских ответов в треде после КП
 * (Foundation §7.2). Дёргается только если письмо привязано к Request
 * в статусе quoted / under_review / postponed_until / awaiting_clarification.
 *
 * 6 типов intent (Foundation §7.2 + inbound_unclear fallback):
 *   • under_review_acknowledgment
 *   • postponement_request (+ извлекаем дату клиента, если есть)
 *   • invoice_request
 *   • decline_with_reason (+ извлекаем reason taxonomy + цитата)
 *   • clarification_response (только если статус был awaiting_clarification)
 *   • unclear — алерт менеджеру, без auto-перехода
 *
 * Используем gpt-4o-mini — простая классификация, short body.
 * confidence ниже 0.6 → принудительный unclear (защита от ложных).
 */
class ClassifyClientResponsePrompt
{
    private const MAX_BODY_CHARS = 6000;

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function build(EmailMessage $message, Request $request): array
    {
        $body = (string) ($message->body_plain ?: strip_tags((string) $message->body_html));
        $body = mb_substr(trim($body), 0, self::MAX_BODY_CHARS);

        $subject = (string) ($message->subject ?? '(без темы)');
        $fromEmail = (string) ($message->from_email ?? '');
        $fromName = (string) ($message->from_name ?? '');

        $userPrompt = "## КОНТЕКСТ ЗАЯВКИ\n"
            . "Internal code: {$request->internal_code}\n"
            . "Текущий статус: {$request->status->value} ({$request->status->label()})\n"
            . "Клиент: " . ($request->client_name ?: $request->client_email) . "\n"
            . "\n"
            . "## ВХОДЯЩЕЕ ПИСЬМО\n"
            . "From: {$fromName} <{$fromEmail}>\n"
            . "Subject: {$subject}\n"
            . "Date: " . ($message->sent_at?->toIso8601String() ?? '') . "\n"
            . "\n"
            . "## ТЕКСТ ПИСЬМА\n"
            . ($body !== '' ? $body : '(пустое тело письма)');

        return [
            ['role' => 'system', 'content' => $this->systemPrompt($request->status)],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    private function systemPrompt(RequestStatus $currentStatus): string
    {
        $clarificationHint = $currentStatus === RequestStatus::AwaitingClientClarification
            ? "Текущий статус заявки = awaiting_client_clarification — ожидаем ответ\n"
                . "на наш вопрос. Если клиент содержательно ответил (например прислал\n"
                . "артикул, фото, описание модели) — intent = clarification_response.\n"
            : "Текущий статус заявки НЕ awaiting_client_clarification.\n"
                . "Intent clarification_response в этом случае НЕ выбирай.\n";

        return <<<PROMPT
Ты — классификатор ответов клиентов на наши коммерческие предложения.
Это часть CRM системы MyZip (запасные части лифтового оборудования).

Письмо — это ответ клиента в треде после того, как мы выслали КП или
задали уточняющий вопрос. Твоя задача — определить намерение клиента
из 6 фиксированных типов.

{$clarificationHint}

═══ ТИПЫ INTENT ═══

1. under_review_acknowledgment — «получили, на согласовании, ответ
   через N дней / через месяц / на следующей неделе»
   • «Получили КП, направил на согласование руководству»
   • «Изучаем, ответим до конца недели»
   • «Передал на рассмотрение, дам обратную связь»

2. postponement_request — клиент откладывает решение до явной даты
   • «Перенесите вопрос на следующий квартал»
   • «Вернёмся к этому после Нового года / в феврале»
   • «Пока не актуально, спросим в марте»
   Извлеки suggested_resume_date (ISO 8601) если возможно.

3. invoice_request — клиент устраивает КП, просит счёт
   • «Принимаем, выставите счёт»
   • «Согласны, направьте счёт на оплату»
   • «Берём, реквизиты во вложении»

4. decline_with_reason — клиент отказывается. Извлеки suggested_closed_lost_reason
   из taxonomy:
   • client_declined_price        — «дорого», «не вписываемся в бюджет», «нашли дешевле»
   • client_declined_timing       — «долго», «не успеваем», «нужно срочнее»
   • client_declined_competitor   — «нашли у конкурентов», «уже заказали у X»
   • client_declined_other        — иной отказ
   Также извлеки cited_phrase — ТОЧНАЯ цитата из письма (1-2 предложения),
   подтверждающая отказ. Используется в аналитике дашборда.

5. clarification_response — клиент ответил на наш уточняющий вопрос
   (статус awaiting_client_clarification). Прислал артикул / фото / модель.
   Если статус другой — НЕ выбирай.

6. unclear — намерение непонятно или письмо вообще не про заявку
   (out-of-office, благодарность, риторический вопрос, общая переписка).
   Менеджеру покажем алерт, статус не двигаем.

═══ ПРАВИЛА ═══

• Confidence < 0.6 → принудительно меняй intent на unclear.
• Если письмо одновременно «получили КП» И «дайте счёт» — приоритет
  invoice_request (более конкретное действие).
• «Дорого» без альтернатив — decline_with_reason / client_declined_price.
  «Дорого, есть дешевле?» — НЕ decline, это переговоры → unclear (менеджер
  ответит) или intent decline только если клиент явно отказывается.
• «Подумаем» / «свяжемся» без даты → under_review_acknowledgment.

═══ ФОРМАТ ОТВЕТА ═══

Строго JSON без markdown:

{
  "intent": "under_review_acknowledgment | postponement_request | invoice_request | decline_with_reason | clarification_response | unclear",
  "confidence": 0.0-1.0,
  "reasoning": "1-2 предложения на русском",
  "suggested_resume_date": "ISO8601 или null (только для postponement_request)",
  "suggested_closed_lost_reason": "client_declined_price | client_declined_timing | client_declined_competitor | client_declined_other | null (только для decline_with_reason)",
  "cited_phrase": "ТОЧНАЯ цитата 1-2 предложения из письма или null (для decline)"
}
PROMPT;
    }
}
