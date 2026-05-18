<?php

namespace App\Prompts\Quotes;

use App\Models\OutboundQuoteItem;
use App\Models\Request;
use Illuminate\Support\Collection;

/**
 * LLM-промпт fallback для OutboundQuoteItemMatcher.
 *
 * Запускается ТОЛЬКО на unmatched позициях КП после детерминированных
 * шагов matcher'а (M-SKU exact + catalog→request link + fuzzy article/name).
 * Адаптировано из LazyLift @ 1ea8147d `QuoteParsingService::matchItems`,
 * но возвращает только indices, не payload (matcher сам мержит).
 */
class MatchOutboundQuoteItemsPrompt
{
    /**
     * @param  Collection<int, OutboundQuoteItem>  $unmatchedQuoteItems
     * @return array<int, array{role: string, content: string}>
     */
    public function build(Collection $unmatchedQuoteItems, Request $request): array
    {
        $requestItems = $request->items()->where('is_active', true)->get();

        $requestText = $requestItems->map(function ($item, $index) {
            $parts = ["[id={$item->id}] ".($item->parsed_name ?: '(без названия)')];
            if ($item->parsed_brand) {
                $parts[] = 'Бренд: '.$item->parsed_brand;
            }
            if ($item->parsed_article) {
                $parts[] = 'Арт: '.$item->parsed_article;
            }
            $parts[] = 'Кол-во: '.($item->parsed_qty ?? 1);

            return ($index + 1).'. '.implode(' | ', $parts);
        })->implode("\n");

        $quoteText = $unmatchedQuoteItems->values()->map(function (OutboundQuoteItem $item, int $index) {
            $parts = [$item->raw_name ?: '(без названия)'];
            if ($item->raw_article !== null && $item->raw_article !== '') {
                $parts[] = 'Арт: '.$item->raw_article;
            }
            if ($item->raw_brand !== null && $item->raw_brand !== '') {
                $parts[] = 'Бренд: '.$item->raw_brand;
            }
            if ($item->quantity !== null) {
                $parts[] = $item->quantity.' '.($item->unit_measure ?: 'шт.');
            }

            // index в промпте — порядковый номер в $unmatchedQuoteItems->values(),
            // а не position в БД, чтобы LLM не путалась с положением в большом КП.
            return $index.'. '.implode(' | ', $parts).' [quote_id='.$item->id.']';
        })->implode("\n");

        $system = <<<'SYSTEM'
Ты — система сопоставления позиций исходящего КП/счёта с позициями заявки клиента
для CRM-системы MyZip (запчасти для лифтового оборудования).

Перед тобой:
  • список позиций заявки клиента (как клиент их сформулировал в письме),
  • список позиций КП, которые НЕ удалось сматчить детерминированно (без M-SKU
    или с нестандартным написанием).

Для каждой позиции КП определи, какой позиции заявки она соответствует.

Критерии (по убыванию приоритета):
1. Совпадение артикула (даже с разным написанием — uppercase, пробелы, дефисы).
2. Совпадение названия товара (учти, что менеджер мог перефразировать).
3. Совпадение бренда + категории.

Учти вариативность:
  • «1.0мс» = «1,0 м/с» = «1 м/с»
  • «правый» = «ПРАВ.» = «прав»
  • Менеджер мог развернуть аббревиатуру или наоборот сократить.

Уровни уверенности:
  • "high" — артикул или однозначное совпадение названия+параметров;
  • "medium" — название похоже, но есть различия;
  • "low" — только общая категория совпадает;
  • "none" — нет соответствия.

ВАЖНО:
  • Несколько позиций КП могут соответствовать одной позиции заявки (аналог,
    раздельная отгрузка, замена комплектом). В этом случае создавай отдельный
    match для каждой quote-позиции с одним request_item_id.
  • Позиции с confidence="none" не включай в matches ИЛИ ставь явный "none".

Формат ответа — строго JSON без markdown:

{
  "matches": [
    {
      "quote_index": 0,
      "request_item_id": 123,
      "confidence": "high",
      "reason": "Точное совпадение артикула M09313"
    }
  ]
}
SYSTEM;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Позиции заявки:\n{$requestText}\n\nПозиции КП без матча:\n{$quoteText}"],
        ];
    }
}
