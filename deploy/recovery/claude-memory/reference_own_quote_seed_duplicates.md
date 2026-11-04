---
name: own-quote-seed-duplicates
description: "Дубли позиций заявки из нашего же КП: OwnQuoteRequestItemSeeder сеял строку КП отдельной позицией, когда она не сматчилась с позицией клиента; фикс матчера (Step 3.5 single_pair/LLM link-only) + гард сидера + бэкфилл 323 заявок (2026-10-07)"
metadata:
  type: reference
---

**Симптом** (кейс M-2026-18523): клиент просит одну деталь своими словами («Направляющая поручня … DAA402NVB5»), менеджер шлёт КП с нашей позицией (M12077 «Алюминиевая направляющая поручня Otis 506NCE»), и в заявке появляется ВТОРАЯ позиция — дубль запрошенной, с каталогом, а у позиции клиента каталог так и не подобран. За 30 дней: 733 засеянных позиций в 510 заявках, 469 заявок с дублями (323 — чистые 1:1).

**Механика:** `ParseOutboundQuoteJob` → `OutboundQuoteItemMatcher::match()`: Step 1 резолвит каталог по M-SKU строки, Step 2 (catalog→request) не находит позицию без каталога/без M-кода, Step 2.5/3 (сходство имён ≥0.5/0.7) не дотягивают, Step 4 (LLM) намеренно пропускается для строк с каталогом → `matched_request=0` → `OwnQuoteRequestItemSeeder::seedForRequest` (гард был «нет каталожно-сматченных активных позиций» — позиция клиента без каталога его проходила) → сеет строку КП как новую позицию (`data_source=own_quote_seed`).

**Фикс (commit 3208094):** (1) матчер Step 3.5 `matchOrphansToUnlinkedRequestItems`: строки с каталогом без позиции ↔ активные позиции без каталога, не занятые другими строками КП; ровно 1↔1 → линк напрямую (`match_source=single_pair`, score 0.8), иначе `matchByLlm` только для привязки (каталог строки не трогает); дальше `OutboundQuoteCatalogEnricher` переносит каталог на позицию клиента. (2) сидер: второй гард — есть активные позиции из других писем или ручные → не сеять (лог «skipped — request already has client items»). Легитимный сценарий сидера (клиент переслал наше КП, в заявке только шум из этого же письма) сохранён.

**Бэкфилл** `/tmp/backfill_seeded_dupes.php apply` (разовый, 30д, только 1:1): строка КП → позиция клиента (`single_pair`, reason backfill), каталог → на позицию клиента (`quality_assessment_payload.catalog_match.method=single_pair_backfill`, `folded_request_item_id`), засеянный дубль → `is_active=false`. 323 заявки. Многопозиционные (185) не трогал — там нужен взгляд или LLM.

Связано: [[catalog-matching]], [[match-audit]], [[outbound-quote-parsing]].
