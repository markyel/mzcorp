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
    private const MAX_ATTACHMENT_CHARS = 14000;

    /**
     * @param  array<int, array{index:int, name:string, oem:?string, qty:?string}>  $items
     * @param  string  $attachmentText  текст из вложений (прайс PDF/Excel/Word)
     * @param  array<int, string>  $images  data:image/...;base64 страницы PDF / фото прайса
     * @return array<int, array{role: string, content: mixed}>
     */
    public function build(array $items, string $replyText, string $attachmentText = '', array $images = []): array
    {
        $replyText = mb_substr(trim($replyText), 0, self::MAX_BODY_CHARS);
        $attachmentText = mb_substr(trim($attachmentText), 0, self::MAX_ATTACHMENT_CHARS);

        $itemsBlock = '';
        foreach ($items as $it) {
            $meta = trim(implode(' · ', array_filter([$it['oem'] ?? null, $it['qty'] ?? null])));
            $itemsBlock .= sprintf("%d. %s%s\n", $it['index'], $it['name'], $meta !== '' ? " ({$meta})" : '');
        }

        $system = <<<'SYS'
Тебе дан СПИСОК ПОЗИЦИЙ, которые мы запросили у поставщика, и ЕГО ОТВЕТ (текст
письма и/или приложенный прайс — текстом из файла и/или изображениями страниц).
Сопоставь ответ с позициями по номеру/названию/артикулу. Для КАЖДОЙ позиции
из списка верни исход:

- "quoted"  — поставщик дал цену. Заполни price (число, без валюты и пробелов),
              currency (как в ответе: «руб», «USD», «EUR»… если есть),
              valid_until_text (срок поставки/действия как в тексте, если есть),
              quote (краткая цитата из ответа/прайса по этой позиции).
- "refused" — поставщик явно отказал по позиции («нет», «снято с производства»,
              «не поставляем», «нет в наличии»). Заполни refusal_reason (кратко) + quote.
- "skipped" — позиция в ответе НЕ упомянута (молчание). Ничего не заполняй.

Правила:
- price — только число (point как десятичный разделитель), без валюты/пробелов/«руб».
- Цена может быть в прайсе-вложении (таблица/скан) — используй её.
- НЕ выдумывай цены и сроки — бери только из ответа/прайса.
- Если по позиции непонятно — "skipped".

Верни СТРОГО JSON:
{"offers":[{"index":1,"outcome":"quoted|refused|skipped","price":12345.67,"currency":"руб","valid_until_text":"...","refusal_reason":"...","quote":"..."}]}
По каждой позиции из списка — ровно один объект с её index.
SYS;

        $userText = "ЗАПРОШЕННЫЕ ПОЗИЦИИ:\n{$itemsBlock}\nОТВЕТ ПОСТАВЩИКА (письмо):\n"
            . ($replyText !== '' ? $replyText : '(пусто)');
        if ($attachmentText !== '') {
            $userText .= "\n\nПРАЙС/ОТВЕТ ИЗ ВЛОЖЕНИЙ (текст):\n{$attachmentText}";
        }

        // Без изображений — обычное текстовое сообщение. С изображениями —
        // multimodal content (text + image_url) для Vision.
        if ($images === []) {
            $userContent = $userText;
        } else {
            $userContent = [['type' => 'text', 'text' => $userText]];
            foreach ($images as $img) {
                $userContent[] = ['type' => 'image_url', 'image_url' => ['url' => $img]];
            }
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent],
        ];
    }
}
