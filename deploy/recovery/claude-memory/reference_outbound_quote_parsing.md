---
name: outbound-quote-parsing
description: "Разбор исходящих КП/счетов (OutboundQuoteParsingService): полнота строк, текст-лимит, де-якорь от заявки, сверка артикулов, warning-типы"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Разбор НАШИХ исходящих КП/счетов: `ParseOutboundQuoteJob` → `OutboundQuoteParsingService::extractContent` (smalot/pdfparser текст + PDF→images) → `parseWithGPT` (gpt-4o, Vision+text) → `outbound_quote_items` → matcher/enricher. Текст из smalot СКЛЕИВАЕТ токены без пробелов («MX-fixturesM02533») и короче poppler (для МЗ-358503: smalot 7270 симв vs poppler 19550).

## Канон-кейс M-2026-4755 (сводный КП №358503, 24 позиции)
Клиент прислал 6 фото — это СНИМКИ 4 ранее выданных документов (КП 354710 3 поз + счёт 2932 2 поз + КП 349470 17 поз + КП 350192 2 поз = 24) с просьбой «объедините в одно КП». Менеджер собрал сводный КП на 24 позиции. Парсер извлёк только **5 из 24** (Σ 312350 при итоге 472668, −33.9%). Заявка тоже неполная: Vision-разбор позиций извлёк из 2 из 6 фото (3+2), из КП-на-17-позиций — 0.

## Фиксы 25.06 (commit 19355d7 + 8c9a0a1, на проде)
1. **Текст-лимит**: было `mb_substr($text,0,8000)` в `buildParsingPrompt` → длинный КП обрезался, хвостовые строки не доходили. Вынесено в `config services.quotes.parser_text_limit` (env `QUOTE_PARSER_TEXT_LIMIT`, default 24000).
2. **Де-якорь от заявки**: промпт вшивал `request->items` как «Позиции заявки клиента» → модель возвращала ровно их. Теперь явно: список заявки = подсказка, документ может содержать БОЛЬШЕ позиций, извлекай ВСЕ строки таблицы.
3. **Retry на пропуск строк**: `buildRetryFeedbackPrompt` был чисто арифметический. Добавлена ветка (Σ строк ≪ итога, diff<0, ≥`services.quotes.missing_rows_pct` default 10) — просит добрать пропущенные строки. `shouldRetry` триггерит при >5%.
4. **Сверка артикул↔текст** (`verifyArticlesAgainstText`): M-артикул, которого нет в текстовом слое PDF, флагается как мисрид. Кейс: в PDF M00878, прочитано M00478 = в каталоге «Плата DOP-116». **Границы по ЦИФРАМ** `(?<!\d)…(?!\d)`, НЕ по буквам — иначе склейка smalot («fixturesM02533») даёт ложные срабатывания.

## Warning-типы в `outbound_quotes.payload['warnings']` (бейдж в табе «КП», detail.blade)
- `likely_missing_rows` — Σ строк МЕНЬШЕ итога ≥ порога → бейдж «возможно, не все позиции» + `payload['needs_review']` + ERROR-лог в Job (статус остаётся matched, downstream не ломаем).
- `sum_vs_total_mismatch` — прочие расхождения Σ vs итог >2% → «проверь цифры».
- `article_not_in_text` — арт. не в тексте → «проверь артикулы».
- `row_arithmetic_mismatch` — |unit_price×qty − total|>2%.

## Известный остаточный кейс — ДВЕ колонки «Кол-во»
КП Liftway/МЗ имеют 2 кол-ва: «ожидается/заказано» и «к отгрузке/гарантировано». Сумма строки = price × ВТОРАЯ колонка. Vision иногда берёт первую (кейс 358503 поз13 M24157: взял 21 вместо 1 → +28688.40, ровно остаточный +6.1% sum_vs_total). autoFixRowArithmetic НЕ ловит (unit_price×qty=line_total внутренне сходится, ошибка в qty). Ловит только document-sum check → флаг «проверь цифры». Не фикшено (отдельный класс, риск регрессий). Связано: [[revival-offer]] (потребитель строк КП), [[item-parsing-pipeline]] (разбор позиций заявки), [[catalog-matching]].

Переразбор: `quotes:parse-outbound --apply --reset --quote=<id>` (требует --reset). Прод DB только по ssh ([[prod-deploy]]).
