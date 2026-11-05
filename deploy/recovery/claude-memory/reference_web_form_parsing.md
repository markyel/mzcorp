---
name: web-form-parsing
description: "Две веб-формы сайта myzip.ru: order@ (структурная, метки/mailto) и «Вопрос с сайта» (info@, display «Веб сайт», голый email в теле). Клиент — из тела, не из From."
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-08-25T18:06:08.339Z
---

Заявки с сайта myzip.ru приходят с ТЕХНИЧЕСКОГО ящика, реальный клиент — в ТЕЛЕ. `IncomingMailProcessor::buildClientFields` → `WebFormSubmissionParser` подменяет Request.client_* на настоящего клиента (иначе переписка/оповещения уходят на наш же ящик). ДВЕ формы:

1. **Структурная заказ-форма** (relay `<адрес>`, config `services.mail.web_form_senders`): HTML с метками «Организация:/Контактное лицо:/E-mail: <mailto>» + таблица позиций. Детект по from_email∈web_form_senders; парс по меткам/mailto.

2. **«Вопрос с сайта MyZip»** (fix 2026-08-25, кейс M-2026-13360): приходит с **info@myzip.ru** (наш общий ящик!), display-name **«Веб сайт»**, тема «Вопрос с сайта MyZip». Тело БЕЗ меток: `товар<br><br>Имя<br>голыйemail`. По from_email не отличить (info@ в relay добавлять НЕЛЬЗЯ). Детект: config `web_form_name_markers` (='Веб сайт', точное совпадение display-name) + `web_form_subject_markers`. Парс: `<br>→\n` ДО strip_tags; email — `firstExternalEmail` (первый НЕ наш домен: internal_domains+mzcorp.ru); имя — `nameBeforeEmail` (непустая строка перед строкой с email, без ':'). Кейс: клиент стал info@myzip.ru/«Веб сайт», оповещение ушло на info@ → фикс дал <адрес>/Виталий. Бэкфилл: 5 историч. заявок (0853/1893/0660/9392/13360) поправлены; 2 из 7 не чинибельны (в теле нет внешнего email).

Обе формы: если parse вернул null (нет валидного клиентского email) — оставляем From, заявка не теряется (РОП разберётся). Позиции парсит штатный ParseRequestItemsJob (парсер тела), не этот сервис. Смежные ящики: forwarded (noreply@, ForwardedRequestParser — блок пересылки). Бэкфилл-команда: RequestsBackfillWebFormClientsCommand.
