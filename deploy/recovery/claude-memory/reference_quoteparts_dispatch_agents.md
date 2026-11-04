---
name: quoteparts-dispatch-agents
description: "QuoteParts (markyel/quoteparts, C:\\Work\\quoteparts) — один запрос = один ящик (явный адрес из mzcorp, режимы primary/rotation, эскалации нет), агенты (UniSystem) с origin_supplier, ложная пауза позиции, доступ к БД платформы"
metadata:
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-10-26T17:28:33.268Z
---

QuoteParts — отдельная платформа (quoteparts.ru), репо `markyel/quoteparts`, локально `C:\Work\quoteparts`; на проде `/var/www/quoteparts`, пользователь `quoteparts`, БД — Beget PG (креды в .env, читать через psql с `PGOPTIONS=-c default_transaction_read_only=on` по ssh root@<IP>). App timezone UTC (sent_at в UTC!), mzcorp — МСК. Деплой и тесты на PG — в её CLAUDE.md; после деплоя перезагружать php8.3-fpm (старый opcache + новый enum = 500).

**Рассылка (26.10.2026):** один запрос уходит на один ящик и никогда повторно на другой. mzcorp передаёт отмеченные менеджером адреса (`addresses` в POST /rfqs → `rfq_recipients.targets`), запрос и напоминания — только туда. Без адреса (автоматический `procurement:price-monitors`, ручной запрос в платформе) — режим связи: `primary` (главный = первый по порядку) или `rotation` (новые запросы по кругу). Эскалацию «при молчании — следующему» удалили (из-за неё UniSystem получал одно и то же на info@/alex@/sales1@ через 48 ч).

**Агенты:** `supplier_links.is_agent` (в mzcorp — «Агент» в карточке поставщика, `suppliers.quoteparts_agent`). Цена агента несёт `origin_supplier` (реальный поставщик: Sicor Italy, DONATI SPA); замена цены — только в пределах того же источника, «не указан» — отдельная группа. Разбор письма агента: строка на поставщика; комплект (деталь + опора) = одна цена-сумма с разбивкой в note. Отказ vision-модели из-за фото → повтор только по тексту. В 1С контрагент — агент, источник — в комментарии.

**Грабля — ложная пауза позиции:** ItemSyncService ставит `paused` при «существенном» изменении карточки; смена основного артикула между двумя своими же артикулами (M15565: LED115000A14→LED1150019) тоже считается существенной → платформа молча не принимает ни одной цены (уходят кандидатами «The item is not in work (paused)»). Снимается решением «Тот же товар» на странице позиции. Исправлено 26.10.2026 (`ItemChangeClassifier`, выкачено в b6ecf4b): свои part_numbers/бренды в названии и смена mpn на свой же номер (старый остаётся в part_numbers) — cosmetic; бренды одного производителя — `config quoteparts.items.brand_families` (TKE/LiftEquip, OTIS/XIZI Otis; Sigma, METEOR, ExpressLift — отдельно), новое семейство = правка конфига + config:cache. Уже стоящие паузы фикс не снимает (M17877, M30189 — вручную). Проверка пауз: прогон item_changes с прода через локальный классификатор.

**PDF в ответах поставщиков (26.10.2026, d8b9bcc):** текст PDF — `pdftotext -layout` (config `services.pdftotext.binary`), Smalot только запасной: он склеивал таблицу в «282,60 269,10 13,50» без подписей. Строка сбора в таблице (упаковка 5%, доставка) — не цена, уходит в note; комплект суммирует только детали. Кейс COAM/UniSystem QP-WQ1O22: 282,60 (с упаковкой) → 269,10. Правку промпта проверять пробным разбором на проде (скрипт: ParseReplyPrompt с переименованным классом в /tmp + OpenAIChatService, без записи в БД), прогон 3× и регресс на письмах 62/63/65; числа из проверяемого кейса в примеры промпта не вписывать.

См. также [[reference_suppliers_module]], [[reference_prod_deploy]].
