<?php

namespace App\Prompts\Mail;

/**
 * Второй проход LLM при парсинге reply'я к существующей заявке.
 *
 * Контекст: основной парсер (ParseItemsPrompt) только что вернул
 * массив `new_items` из тела reply. У заявки уже есть `existing_items`.
 * Этот промпт решает по каждому new_items[i] — это «truly new» (новая
 * позиция, которую клиент дописал) или «clarification» (тот же товар,
 * что и existing_items[j], просто артикул в другой системе кодов).
 *
 * Типичный паттерн уточнения:
 *  - Liftway-auto: «LW-0026262 — 24 шт», следом reply «Артикул M21595 —
 *    24 штуки».
 *  - Клиент: «Контактор 100А — 5 шт», следом reply «по позиции 1 артикул
 *    3RT2016-2GG22».
 *
 * Сигналы для CLARIFICATION:
 *  - совпадение qty + unit;
 *  - похожее name (одно и то же изделие, разные коды);
 *  - reply явно ссылается на позицию («по поз. 1», «к артикулу LW-...»);
 *  - reply содержит уточняющую формулировку («выставите счёт», «правильный
 *    артикул», «у нас в системе называется»).
 *
 * Сигналы для TRULY NEW:
 *  - qty явно другой;
 *  - название другого товара;
 *  - reply сам говорит «добавьте ещё» / «забыл указать».
 *
 * При сомнении — НОВАЯ позиция (это безопаснее: дубль виден сразу,
 * слипшаяся неправильно — нет).
 */
class DecideClarificationsPrompt
{
    public static function systemMessage(): string
    {
        return <<<'PROMPT'
Ты — пост-процессор парсера заявок. На вход — массив УЖЕ существующих
позиций заявки (`existing_items`) и массив новых позиций, извлечённых
из reply'я клиента (`new_items`). Решай по каждой new_items[i]:

  - "new"            — это действительно новая позиция (qty другой,
                       название другого товара, клиент явно добавляет);
  - "clarification"  — это уточнение к одной из existing_items
                       (тот же товар, тот же qty/unit, просто артикул
                       в другой системе кодов; либо клиент в reply
                       прямо ссылается на номер существующей позиции).

ПРАВИЛА:
1. Сильные сигналы для CLARIFICATION:
   • qty + unit совпадают с одной из existing_items;
   • parsed_name той же сущности (например, оба «Вкладыш 9мм»);
   • reply содержит фразы «выставите счёт», «правильный артикул», «у
     нас в системе называется», «по позиции 1», «к артикулу X» и т.п.

2. Сигналы для NEW:
   • qty явно отличается от всех existing;
   • parsed_name другой сущности;
   • reply говорит «добавьте ещё», «забыл указать», «+ нужно ещё».

3. При СОМНЕНИИ — отдавай "new" (false-clarification сливает разные товары,
   это хуже чем дубль).

4. Если existing_items пусто — все new должны быть "new" (clarifications
   физически не к чему привязать). Этот случай не должен прийти, но если
   пришёл — отвечай безопасно.

5. Один new_items[i] может быть уточнением только ОДНОЙ existing.
   Одна existing может получить несколько уточнений (если в reply
   несколько артикулов на одну позицию).

═══ ФОРМАТ ОТВЕТА ═══
Строго JSON без markdown:
{
  "decisions": [
    {
      "new_item_index": 0,
      "verdict": "clarification",
      "target_position": 1,
      "reasoning": "оба «Вкладыш 9мм», qty=24 шт совпадает, reply говорит «выставите счёт на позиции»"
    },
    {
      "new_item_index": 1,
      "verdict": "new",
      "target_position": null,
      "reasoning": "qty=10 шт в new, в existing такого нет"
    }
  ]
}

new_item_index — 0-based индекс в массиве new_items.
target_position — поле position у existing_items (НЕ массивный index), null если verdict=new.
reasoning — короткое объяснение (1-2 предложения, для аудита).

Если не уверен — verdict="new", target_position=null.
PROMPT;
    }

    /**
     * Собрать user-prompt с двумя секциями.
     *
     * @param array<int, array{position: int, parsed_name: string, parsed_brand: ?string, parsed_article: ?string, parsed_qty: float|string, parsed_unit: string}> $existing
     * @param array<int, array{name: string, brand: ?string, article: ?string, qty: float, unit: string}> $newItems
     */
    public static function userMessage(array $existing, array $newItems, ?string $replyContextSnippet = null): string
    {
        $payload = [
            'existing_items' => $existing,
            'new_items' => $newItems,
        ];
        if ($replyContextSnippet !== null && trim($replyContextSnippet) !== '') {
            $payload['reply_text'] = mb_substr(trim($replyContextSnippet), 0, 4000);
        }

        return "## КОНТЕКСТ\n```json\n"
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n```\n\nВерни решения по каждой new_items[i].";
    }
}
