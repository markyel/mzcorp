<?php

namespace App\Prompts\Mail;

/**
 * Path C (2026-05-21): извлечение контекстных уточнений из reply-письма
 * клиента, когда оно НЕ содержит структурированных позиций (масленка
 * «на противовесе», «по последней позиции — 2 шт. вместо 1»).
 *
 * Триггер: ParseRequestItemsJob получил пустой items[] для reply-сообщения
 * (related_request_id ≠ null), и у заявки нет активного ClarificationBatch.
 *
 * Назначение: LLM смотрит на список существующих позиций + текст reply и
 * предлагает уточнения вида (item_id, field, value, confidence). Запись
 * идёт в request_items.quality_assessment_payload.enrichment_suggestions[]
 * — тот же канал что использует ClarificationAnswerMatcher. UI (💡 badge,
 * блок «Предложенные уточнения») автоматически подхватывает.
 */
class EnrichExistingItemsFromReplyPrompt
{
    /**
     * @param  array<int, array{id: int, position: int, parsed_name: ?string, parsed_brand: ?string, parsed_article: ?string, parsed_qty: ?float, parsed_unit: ?string}>  $existingItems
     * @return array<int, array{role: string, content: string}>
     */
    public static function build(array $existingItems, string $replyBody, ?string $replySubject = null): array
    {
        return [
            ['role' => 'system', 'content' => self::systemMessage()],
            ['role' => 'user', 'content' => self::userMessage($existingItems, $replyBody, $replySubject)],
        ];
    }

    private static function systemMessage(): string
    {
        return <<<'TXT'
Ты помогаешь менеджеру лифтовых запчастей разобрать ответ клиента в email-
треде уже существующей заявки.

КОНТЕКСТ: клиент уже прислал исходное письмо со списком позиций. Сейчас
он прислал follow-up без явного перечня товаров — это либо уточнение по
одной из позиций, либо общий контекст для всей заявки. Структурированных
«новых позиций» в этом письме нет (если бы были — их бы извлёк другой
парсер до тебя).

ТВОЯ ЗАДАЧА: найти, какие из существующих позиций клиент УТОЧНЯЕТ, и
вернуть список предлагаемых обновлений их полей.

═══ ЧТО ИСКАТЬ ═══

Типичные паттерны уточнений в free-text reply:

1. Дополнение бренда / артикула / модели:
   «по плате — это плата ARO 47.RDP», «масленка KONE», «двигатель
   модели Y-200T2-12»
   → field = parsed_brand или parsed_article, value = новое значение.

2. Уточнение количества / единицы измерения:
   «нужно 2 шт., а не 1», «по тросам — 4 куска по 25 м», «пересчитайте
   масленки — 5, а не 2»
   → field = parsed_qty, value = новое значение (число).

3. Контекст применения (где/как стоит, на каком узле):
   «масленка на противовесе», «кнопка из кабины», «верхний концевик»,
   «по второму лифту — все остальные позиции с прошлого письма»
   → field = note, value = краткое описание контекста.

4. Уточнение по фото:
   «фото 3 — это то что нужно», «на фото видна табличка GBA21230F10»
   → field = parsed_article (если артикул) или note (если контекст).

5. KB-параметры (марка лифта, материал, габариты и т.п.):
   «диаметр 6 мм», «оригинал, не аналог», «с подсветкой»
   → field = kb:<slug>, value = значение (slug если известен — например
   kb:diameter, kb:lift_brand, kb:button_illumination).

═══ ПРАВИЛА ═══

- Уточнение должно ЯВНО относиться к одной из существующих позиций (по
  названию / артикулу / номеру / типу). Не угадывай.
- Если клиент пишет «по масленке» — найди позицию с маслёнкой; если
  таких несколько и непонятно к какой — НЕ возвращай suggestion (это
  должен решить менеджер).
- НЕ предлагай поля, которые УЖЕ заполнены тем же значением (если в
  позиции уже parsed_brand="KONE", и клиент пишет «KONE» — не предлагай).
- Не выдумывай. Если в reply нет конкретики о существующих позициях —
  верни пустой массив.
- source_quote — короткая цитата из reply (до 200 символов), из которой
  ты сделал вывод.
- confidence: 0.0–1.0. Выше 0.9 — клиент ЯВНО и однозначно уточнил.
  0.6–0.9 — есть привязка к позиции, но формулировка чуть размытая.
  Ниже 0.6 — НЕ возвращай.
- Все text-поля на русском.

═══ ВЫХОДНОЙ JSON ═══

Только JSON, без markdown:
{
  "suggestions": [
    {
      "item_id": 12345,
      "field": "parsed_brand" | "parsed_article" | "parsed_qty" | "note" | "kb:<slug>",
      "value": "Schindler" | "GBA21230F10" | "2" | "на противовесе" | ...,
      "source_quote": "по масленке - это масленка на противовесе",
      "confidence": 0.92,
      "reasoning": "клиент явно уточняет применение существующей позиции #2 (масленка)"
    }
  ]
}

Если уточнений нет — {"suggestions": []}.
TXT;
    }

    /**
     * @param array<int, array<string, mixed>> $existingItems
     */
    private static function userMessage(array $existingItems, string $replyBody, ?string $replySubject): string
    {
        $lines = [];
        $lines[] = 'ТЕМА REPLY: '.($replySubject ?: '(без темы)');
        $lines[] = '';
        $lines[] = 'СУЩЕСТВУЮЩИЕ ПОЗИЦИИ ЗАЯВКИ:';
        if (empty($existingItems)) {
            $lines[] = '(нет позиций)';
        } else {
            foreach ($existingItems as $i) {
                $line = sprintf(
                    '  #%d [item_id=%d] %s',
                    (int) ($i['position'] ?? 0),
                    (int) ($i['id'] ?? 0),
                    (string) ($i['parsed_name'] ?? '(без названия)'),
                );
                $attrs = [];
                if (! empty($i['parsed_brand'])) {
                    $attrs[] = 'brand='.$i['parsed_brand'];
                }
                if (! empty($i['parsed_article'])) {
                    $attrs[] = 'article='.$i['parsed_article'];
                }
                $qty = $i['parsed_qty'] ?? null;
                $unit = $i['parsed_unit'] ?? null;
                if ($qty !== null) {
                    $attrs[] = 'qty='.$qty.($unit ? ' '.$unit : '');
                }
                if (! empty($attrs)) {
                    $line .= ' | '.implode(', ', $attrs);
                }
                $lines[] = $line;
            }
        }
        $lines[] = '';
        $lines[] = 'ТЕКСТ REPLY ОТ КЛИЕНТА:';
        $lines[] = '```';
        $lines[] = trim($replyBody) !== '' ? $replyBody : '(пустое тело)';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'Извлеки уточнения согласно формату.';

        return implode("\n", $lines);
    }
}
