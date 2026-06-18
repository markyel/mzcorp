<?php

namespace App\Prompts\Suppliers;

/**
 * Промпт разбора ответа поставщика на наш запрос расценки (Фаза 3.3).
 * Мы ЗНАЕМ запрошенные позиции (передаём нумерованным списком) — задача LLM
 * сопоставить ответ поставщика с ними: для каждой позиции исход
 * quoted / refused / skipped. Цена/валюта/срок — как в ответе, без интерпретации.
 *
 * gpt-4o-mini, JSON-режим.
 */
class ParseSupplierReplyPrompt
{
    private const MAX_BODY_CHARS = 8000;

    /**
     * @param  array<int, array{index:int, name:string, oem:?string, qty:?string}>  $items
     * @return array<int, array{role: string, content: string}>
     */
    public function build(array $items, string $replyText): array
    {
        $replyText = mb_substr(trim($replyText), 0, self::MAX_BODY_CHARS);

        $itemsBlock = '';
        foreach ($items as $it) {
            $meta = trim(implode(' · ', array_filter([$it['oem'] ?? null, $it['qty'] ?? null])));
            $itemsBlock .= sprintf("%d. %s%s\n", $it['index'], $it['name'], $meta !== '' ? " ({$meta})" : '');
        }

        $system = <<<'SYS'
Тебе дан СПИСОК ПОЗИЦИЙ, которые мы запросили у поставщика, и ТЕКСТ ЕГО ОТВЕТА.
Сопоставь ответ с позициями по номеру/названию/артикулу. Для КАЖДОЙ позиции
из списка верни исход:

- "quoted"  — поставщик дал цену. Заполни price (число, без валюты и пробелов),
              currency (как в ответе: «руб», «USD», «EUR»… если есть),
              valid_until_text (срок поставки/действия как в тексте, если есть),
              quote (краткая цитата из ответа по этой позиции).
- "refused" — поставщик явно отказал по позиции («нет», «снято с производства»,
              «не поставляем», «нет в наличии»). Заполни refusal_reason (кратко) + quote.
- "skipped" — позиция в ответе НЕ упомянута (молчание). Ничего не заполняй.

Правила:
- price — только число (point как десятичный разделитель), без валюты/пробелов/«руб».
- НЕ выдумывай цены и сроки — бери только из текста ответа.
- Если по позиции непонятно — "skipped".

Верни СТРОГО JSON:
{"offers":[{"index":1,"outcome":"quoted|refused|skipped","price":12345.67,"currency":"руб","valid_until_text":"...","refusal_reason":"...","quote":"..."}]}
По каждой позиции из списка — ровно один объект с её index.
SYS;

        $user = "ЗАПРОШЕННЫЕ ПОЗИЦИИ:\n{$itemsBlock}\nОТВЕТ ПОСТАВЩИКА:\n{$replyText}";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }
}
