<?php

namespace App\Prompts\Mail;

use App\Models\EmailMessage;
use App\Models\Request;

/**
 * Промпт LLM-классификатора исходящих писем менеджера в треде заявки
 * (Foundation §7.1, LLM-вариант).
 *
 * Используется как fallback после rule-based `OutboundDocumentDetector` —
 * если регекс по filename + keyword не сработал (кейс: PDF назван
 * «Предложение_МЗ-355319.pdf» с body «КП» — слова «коммерческое»/«КП»
 * нет в текстовом виде ниже шаблонного боди).
 *
 * Возвращает 5 типов (соответствуют outbound DetectorType):
 *   • quotation — КП (наше предложение клиенту); target=Quoted
 *   • invoice — счёт на оплату; target=Invoiced
 *   • clarification — запрос уточнения у клиента (без attachment'а с КП/счётом);
 *     target=AwaitingClientClarification
 *   • declined — менеджер отказал «не наша номенклатура / не наш профиль»;
 *     target=ClosedLost с reason=off_topic
 *   • other — обычная переписка без авто-перехода
 *
 * gpt-4o-mini — дешёво и достаточно. confidence < 0.6 → принудительно other.
 */
class ClassifyOutboundDocumentPrompt
{
    private const MAX_BODY_CHARS = 4000;

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function build(EmailMessage $message, Request $request): array
    {
        $body = (string) ($message->body_plain ?: strip_tags((string) $message->body_html));
        $body = mb_substr(trim($body), 0, self::MAX_BODY_CHARS);

        $subject = (string) ($message->subject ?? '(без темы)');

        // Перечень имён и расширений приложенных файлов — главный сигнал
        // для quotation/invoice.
        $attLines = [];
        foreach ($message->attachments as $att) {
            $attLines[] = sprintf(
                '- %s (%s, %d bytes)',
                $att->filename ?: '(без имени)',
                $att->mime_type ?: 'unknown',
                (int) $att->size_bytes,
            );
        }
        $attsBlock = empty($attLines) ? '(нет вложений)' : implode("\n", $attLines);

        $userPrompt = "## КОНТЕКСТ ЗАЯВКИ\n"
            . "Internal code: {$request->internal_code}\n"
            . "Текущий статус: {$request->status->value} ({$request->status->label()})\n"
            . "Клиент: " . ($request->client_name ?: $request->client_email) . "\n"
            . "\n"
            . "## ИСХОДЯЩЕЕ ПИСЬМО МЕНЕДЖЕРА\n"
            . "Subject: {$subject}\n"
            . "Date: " . ($message->sent_at?->toIso8601String() ?? '') . "\n"
            . "\n"
            . "## ВЛОЖЕНИЯ\n"
            . $attsBlock
            . "\n\n"
            . "## ТЕКСТ ПИСЬМА\n"
            . ($body !== '' ? $body : '(пустое тело — только вложения)');

        return [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ты — классификатор исходящих писем менеджера клиенту в CRM-системе MyZip
(запчасти для лифтового оборудования).

Менеджер получил заявку и отвечает клиенту в email-треде. Определи тип
письма из 5 фиксированных категорий по содержимому subject + body + именам
вложений (КП/счёт обычно прикреплены как PDF/XLSX).

═══ ТИПЫ ═══

1. quotation — КП (коммерческое предложение, наше ценовое предложение
   клиенту по его запросу).
   Сигналы:
   • Прикреплён PDF/XLSX с именем «КП», «Quote», «Quotation», «Commercial»,
     «Предложение», «Коммерч*», «Цены», или с шифром заявки/контрагента
     («Предложение МЗ-355319», «KP_M-2026-0759»).
   • Body содержит «коммерческое предложение», «КП», «наше предложение»,
     «предлагаем», «прилагаю прайс», «итого N руб», «срок поставки», «цена».
   • Часто body короткий («КП», «Высылаю», «Во вложении») — главный
     сигнал — приложенный файл.

2. invoice — счёт на оплату.
   Сигналы:
   • Файл «Счёт_NNN.pdf», «invoice», «inv-», «bill».
   • Body «счёт на оплату», «выставляем счёт», «реквизиты», «УПД».
   • Чаще приходит ПОСЛЕ статуса awaiting_invoice (клиент уже согласился
     с КП и попросил счёт).

3. clarification — запрос уточнения у клиента (нет вложения с КП/счётом).
   Сигналы:
   • Body «уточните», «пришлите», «не хватает информации», «какая модель»,
     «для подготовки КП нужно», «фото маркировки».
   • Вложений нет ИЛИ только наши уточняющие документы (анкета, опросник).

4. declined — менеджер сообщает клиенту, что запрос вне нашего профиля
   и заявка закрывается. Эквивалентно ручному «Закрыть · потеря» с
   reason=off_topic. Срабатывает на ЯВНЫХ ОТКАЗАХ без follow-up:
   • «Не наша номенклатура», «не наш профиль», «не наша тема»
   • «Мы не работаем с этой техникой», «не делаем», «не занимаемся»
   • «У нас этого нет», «не торгуем этим», «не наш ассортимент»
   • «К сожалению, ничем не можем помочь»
   ВАЖНО: если менеджер пишет «не наша номенклатура, но я могу уточнить
   у поставщика» / «пришлите фото — попробую найти» — это НЕ decline,
   это clarification (продолжение работы).
   Decline = безусловный отказ + закрытие темы.

5. other — обычная переписка, не вызывающая авто-перехода статуса.
   • «Добрый день, мы получили ваш запрос, в работе», «уведомление о
     заказе принят», «спасибо за обращение», заглушки.
   • Письма без явных сигналов КП/счёта/уточнения/отказа.

═══ ПРАВИЛА ═══

• Если есть PDF/XLSX-вложение с КП-признаком в имени файла И body не
  содержит явных слов «счёт» — это quotation.
• Если есть PDF/XLSX с invoice-признаком ИЛИ body про «счёт на оплату» —
  это invoice (priority выше чем quotation).
• declined > clarification: если есть и фраза отказа, и просьба прислать
  что-то — приоритет clarification (продолжение).
• Decline confidence ≤ 0.85 для НЕОДНОЗНАЧНЫХ случаев (содержит «но»,
  предложение, follow-up). Чёткий короткий отказ — 0.9+.
• Confidence < 0.6 → принудительно other.
• Empty body + PDF/XLSX без явного имени → если статус заявки
  assigned/in_progress, скорее всего quotation, conf 0.65-0.75.

═══ ФОРМАТ ОТВЕТА ═══

Строго JSON без markdown.

Для type=quotation/invoice/clarification/other:
{
  "type": "quotation | invoice | clarification | other",
  "confidence": 0.0-1.0,
  "reasoning": "1-2 предложения на русском, почему такой выбор"
}

Для type=declined — ДОПОЛНИТЕЛЬНО проставь suggested_closed_lost_reason
и cited_phrase (точная цитата отказа из тела письма, ≤200 симв):
{
  "type": "declined",
  "confidence": 0.0-1.0,
  "reasoning": "1-2 предложения на русском",
  "suggested_closed_lost_reason": "off_topic",
  "cited_phrase": "Не наша номенклатура."
}
PROMPT;
    }
}
