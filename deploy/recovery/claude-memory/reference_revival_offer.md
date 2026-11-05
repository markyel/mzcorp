---
name: revival-offer
description: "«Оживляющее» письмо RevivalOffer: проигранную заявку с КП реанимируем, если цена позиций упала >N% — авто-email + реанимация по ответу"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Новый тип клиентского уведомления `ClientNotificationType::RevivalOffer` (cron, через `notifications:dispatch-client`, ежечасно). Встроен в существующий каркас уведомлений (`ClientNotificationService::dispatch` — шаблон/opt-out/идемпотентность/reply-в-тред). Реализовано 2026-06-17.

## Кого ловит (cron `collectRevivalOffers` в NotificationsDispatchClientCommand)
1. `Request.status=closed_lost`, `closed_at` за период (default 14д, `services.notifications.revival.period_days` / settings `notifications.revival.period_days`).
2. Есть реально отправленное КП — через существующий `resolveLastSentQuote` (Quotation sent ИЛИ OutboundQuote quotation_full/partial); нужен номер + якорь треда.
3. По позициям заявки (`request_items.catalog_item_id`) есть `CatalogPriceChange` с падением (new<old) ≥ порога (default 15%, `drop_threshold_pct`) и `changed_at >= КП sent_at` (упало ПОСЛЕ того, как мы выставили КП). Хелперы `collectPriceDrops` / `buildDroppedSummary`.
4. Дедуп — `scope_key = 'quote_'.номер_КП` → **одно письмо на КП** (ClientNotificationSent uniq request+type+scope).

## Письмо
Шаблон в `ClientNotificationTemplatesSeeder` (placeholders: quote_number, quote_date, dropped_summary, reply_keyword). **is_enabled=TRUE из коробки** — исключение из правила «все типы off» (заказчик: «включить сразу»). Сидер чинит пустой stub, который мог создать `ClientNotificationTemplate::forType()` (он создаёт disabled-пустую строку, если опередил сидер) — иначе RevivalOffer остался бы выключенным/пустым. На деплое: `migrate` + `db:seed --class=ClientNotificationTemplatesSeeder`.

## Ответ клиента → реанимация
MailRouter (в reply-блоке, `if linkedRequest`): если заявка `closed_lost` И есть `RevivalOffer` ClientNotificationSent с `responded_at IS NULL` → dispatch `RevivalReplyMatcherJob(msg, sentId)`. Джоб: LLM (`clarification_model`=gpt-4o-mini, json `{wants_new_quote,confidence}`) классифицирует ответ → **positive** (`wants && conf≥0.6`) → `RequestStateService::reanimate(author:null, sourceMessage:msg, event:'revival_positive_reply')` (closed_lost→**in_progress**) + `attention->onClientReplied`. negative/unclear → ничего. `responded_at`/`response_intent` (миграция `..add_response_to_client_notifications_sent`) гасят повторную LLM-классификацию на следующих reply'ях. `reanimate(author:null)` пишет статус напрямую, без проверки прав — системный вызов ок.

## БАГ-фикс 22.06 — финал: каталожное сравнение + гейт по closed_at (commit 063bc03; b8303da/792167d откачены)
Кейс M-2026-4639: письмо «было 193 704 → стало 164 420 (−15.1%)», хотя клиенту выставляли КП на 150 444.23. Таймлайн: КП 17.06 15:30 (по флору 150 444.23) → `CatalogPriceChange` 17.06 **23:00** (price 193 704→164 420, price_min 177 239→150 444, каталог «догнал» цену КП) → закрытие **22.06** → revival 22.06.

**Корень бага = ДАТА (вердикт заказчика, НЕ «скидка»):** старый гейт `changed_at >= sent_at` ловил изменение 23:00 (формально «после КП»), но клиент эту цену уже видел в КП. Слать надо ТОЛЬКО при изменении каталога **после закрытия заявки**. **Сравнивать цену из КП (со скидкой/НДС) с каталожной розницей НЕЛЬЗЯ** — разные базы (моя промежуточная попытка b8303da давала 57 ложных «−20/−50%» из разницы НДС/наценки vs флор). Правильно — каталожное `old_price→new_price` (одна база): скидка клиента множитель, в % сокращается, % падения каталога = % падения для клиента.

**Фикс (`collectPriceDrops`):** оригинальное каталожное сравнение `CatalogPriceChange` `new_price<old_price` ≥ порога. Гейт `changed_at` см. ниже. В письме (`buildDroppedSummary`) — только «дешевле на N%» (без абсолютов: розница ≠ цене КП). Откат: убраны `quotedUnitPricesByCatalogItem`/`source`/`source_id`/импорты CatalogItem,QuotationItem,DB.

**ФИНАЛЬНЫЕ настройки (commit c9d5615, на проде, оба рычага по решению заказчика):** (1) порог `drop_threshold_pct` дефолт **15→10** (config/services.php; override env `REVIVAL_DROP_THRESHOLD_PCT`/Настройки); (2) гейт — **конец дня выставления КП** `sent_at->endOfDay()` (НЕ closed_at): снижения в сам день КП клиент уже видел в цене КП (отсекаем, 4639), со следующего дня — реальные; fallback closed_at если sent_at нет. Dry-run на проде: **4 кандидата** (одно падение cat 20799 −14.5% задело M-2026-4007/4349/3653/3773), 4639 исключён. Чувствительность была (closed_at/«после дня КП», 14д): 15%→0/мало, 10%→1/4. NB: эти 4 уйдут на ближайшем часовом cron (idempotency пуста).

## ФИКС 25.06 — отслеживание по M-артикулам строк КП (commit 6133c56, на проде)
Письмо описывало позиции по `request_items`/`matched_catalog_item_id` → в текст попадало внутреннее имя каталога, которого клиент в КП не видел (кейс M-2026-4755: SKU в каталоге «Плата DOP-116», в КП «Плата вызова ВВЕРХ»). Теперь источник истины — строки самого КП: `resolveLastSentQuote` отдаёт `lines` (Quotation `snapshot_sku`/`snapshot_name` ИЛИ OutboundQuote `raw_article`/`raw_name`), `collectPriceDrops($since,$pct,$quoteLines)` резолвит каждый M-артикул в каталог по sku и следит за ценой по нему, имя берёт из КП. Без привязки к request_items/матчу. NB: точность зависит от качества разбора КП (см. [[outbound-quote-parsing]]).

## Валидация на проде 17.06
0 живых кандидатов (безопасно): единственная closed_lost(14д) с падением ≥15% — M-2026-1604 (sku M00055 −29.2%) — **без КП**, корректно отсеяна. Внимание: closed_lost за 14д ~**1080** — рассылку держит в узде именно гейт «есть КП + падение после КП»; если порог/период ослабить, объём вырастет. Пороги — `config('services.notifications.revival.*')` + settings-override. См. [[changelog-updates]] нет; связано с [[manager-analytics]] (price-changes аналитика — источник CatalogPriceChange) и [[catalog-matching]].
