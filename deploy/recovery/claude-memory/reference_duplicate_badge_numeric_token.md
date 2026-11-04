---
name: duplicate-badge-numeric-token
description: "Бейдж «возможно дубль» на позициях: not-flag при разных числовых токенах имени (лифтовые кнопки символ 1≠4)"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Бейдж `⚠ возможно дубль #N` на позициях заявки строится из `request_items.quality_assessment_payload['possible_duplicate_of']`, который заполняет `RequestItemPersister::recomputePossibleDuplicates` (вызывается в `persist`). Похожесть пары — `possibleDuplicateSimilarity`: `similar_text` имён ≥85% (+ guard'ы по article/brand).

**Грабля:** `similar_text` посимвольный — «Кнопка приказа с символом 1» vs «…с символом 4» = 98%, хотя это разные кнопки (разные этажи). Заявка M-2026-4011 зажглась бейджами на всех соседних позициях, мазала на чужие.

**Фикс:** перед `similar_text` — guard `numericTokens()`: если наборы числовых токенов имён (со знаком, `-1`≠`1`) различаются → не дубль. Тот же принцип, что в `isDuplicate` (3RT2026≠3RT2025). Позиции с одинаковым номером и близким именем по-прежнему флагаются.

Бэкфилл существующих ложных бейджей: пересчёт `recomputePossibleDuplicates` (private, через Reflection) по заявкам, где `quality_assessment_payload->'possible_duplicate_of' is not null`.

Связано с [[item-parsing-pipeline]] — тот же тикет, но корень переполнения позиций (11 вместо 6) был в split-пути парсинга, а не в бейдже.
