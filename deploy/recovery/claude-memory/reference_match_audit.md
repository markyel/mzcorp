---
name: match-audit
description: Страница «Аудит матчинга» — расхождения авто-матча позиций с каталогом из отправленного КП (ложные матчи) + метод детекции
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-08-06T11:36:49.361Z
---

**Страница `/dashboard/catalog/match-audit`** (`catalog.match-audit`, роль head_of_sales/director/admin, nav «Аудит матчинга»). Живой список КАНДИДАТОВ В ЛОЖНЫЕ МАТЧИ: позиции, где система авто-сматчила один каталог, а менеджер в ОТПРАВЛЕННОМ КП указал другой. Компонент `App\Livewire\Catalog\MatchAudit` (commit 7666d41, 2026-08-06).

**Метод детекции (SQL):** строка разобранного КП `outbound_quote_items` несёт `matched_catalog_item_id` (каталог по SKU из PDF = ИСТИНА, ручной выбор менеджера) и `matched_request_item_id` (позиция заявки). Ложный матч = `request_items.catalog_item_id` (авто-матч) `<>` `oqi.matched_catalog_item_id` по одной позиции. Join: `outbound_quote_items oqi JOIN outbound_quotes oq JOIN request_items ri ON ri.id=oqi.matched_request_item_id LEFT JOIN catalog_items` для sys/kp SKU. Гейты: обе catalog_item_id не null, ri.is_active. Тумблер «скрывать замены/аналоги» = `oqi.is_analog=false AND sysc.name NOT ILIKE '%ЗАМЕНЕНО%' AND oqi.raw_name NOT ILIKE '%аналог%'`. Покрытие: из ~21.6k строк КП ~13.2k привязаны к позициям (matched_request_item_id). Прод 2026-08-06: 577 всего / 522 по умолчанию.

**Действие «Исправить»:** `applyKpCatalog(requestItemId, kpCatalogId)` переставляет `request_items.catalog_item_id` на КП-шный + qa_status=sufficient (audit-лог). Строка уходит из списка.

**Паттерны ошибок (для улучшения МАТЧЕРА — ОТКРЫТАЯ задача):** лево/право («Замок ДШ Wittur правый» vs «левый»; «Гребёнка центральная» vs «левая/правая»), версии («Плата LCB II D4» vs «D1»), артикул-варианты («SWS-000-05» vs «SWS-C00-05»), совсем другая деталь («Привод дверей 700мм» vs «Редуктор ПДК»). Идея фикса: штрафы/правила в матчере на лево/право/версию/размерные токены в названии. Связано: [[catalog-matching]], [[outbound-quote-parsing]], [[duplicated-source-of-truth]].
