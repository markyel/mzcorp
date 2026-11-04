---
name: forwarded-requests
description: "Пересланные заявки (noreply@myzip.ru): извлечение реального отправителя из блока пересылки в client_email/name"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Заявки клиентов иногда ПЕРЕСЫЛАЮТ на info@ с технического ящика `noreply@myzip.ru` (вручную/автопереслом). Тогда `EmailMessage.from_email = noreply@myzip.ru`, а реальный отправитель — в блоке пересылки в теле («-------- Перенаправленное сообщение --------\n…\nОт: Имя <email>\n…»). Раньше `Request.client_email = noreply@myzip.ru` (технический), клиент терялся. Кейс M-2026-5120 (реальный клиент `<адрес>`). Реализовано 2026-06-23, commit b7f5702.

**`App\Services\Mail\ForwardedRequestParser`** (аналог [[WebFormSubmissionParser]], но для произвольной пересылки, а не HTML-формы сайта):
- `forwarderSenders()` ← `config('services.mail.forwarder_senders')` (env `MAIL_FORWARDER_SENDERS`, default `noreply@myzip.ru`).
- `isForwarded(msg)` — from_email в списке форвардеров.
- `parse(msg)` → `{email, name}|null`: regex по строке «От:/От кого:/Отправитель:/From:/Sender:» (НЕ «Отвечать:»/«Кому:»), приоритет `Имя <email>`, берёт ПЕРВОЕ совпадение (внешний оригинальный отправитель). Гарды: валидный email + НЕ внутренний домен (`internal_domains`) — иначе null (не подменяем на noreply@). body_plain приоритетно, fallback strip_tags(html).

**Хуки (оба пути создания заявки):**
- `IncomingMailProcessor::buildClientFields` — после web-form ветки добавлена forwarded-ветка (авто-создание; но noreply@ обычно `irrelevant` из-за внутреннего домена, так что чаще срабатывает промоутер).
- `EmailToRequestPromoter::promote` — `resolveClient()` (5120 создана именно ручным «создать заявку из письма», category=irrelevant). **Это основной путь** для noreply@.
- `EmailDraftService::createReply` — гард расширен: `isWebFormSubmission || isForwarded` → ответ уходит на `Request.client_email` (реальный клиент), а не на форвардер.

**Смежное (commit c82dca4, 2026-07-09, кейс M-2026-5226)**: последний inbound треда может быть ВНУТРЕННИМ письмом коллеги (@myzip.ru, «Заявка mzcorp M-…») — кнопка «✉ Ответить» якорилась на него, computeRecipients ставил коллегу в To, dedup вырезал его как «наш» ящик → «Кому» ПУСТОЕ. Фиксы: (1) detail.blade `$lastInbound` предпочитает последнее входящее от ВНЕШНЕГО отправителя (internal_domains), fallback — любое; (2) `createReply` страховка: To пустой → client_email заявки (покрывает и reply на собственное outbound).

**Бэкфилл:** `requests:backfill-forwarded-clients [--apply] [--all]` (dry-run по умолчанию; аналог requests:backfill-web-form-clients). На проде применён `--all --apply`: 12 заявок исправлено (все 12 распарсились, вкл. 5120).

**НЕ трогалось:** категоризация/`internal_sender_allowlist` (среди noreply@ много реально нерелевантного — «Заказ приза», «Вопрос по бонусной программе»; авто-создавать их нельзя). Фича только извлекает отправителя ПРИ создании заявки, не плодит заявки. 51 входящее с noreply@ всего, ~24 стали заявками (промоут).

Тест: `tests/Feature/Services/Mail/ForwardedRequestParserTest` (7 кейсов, вкл. реальное тело 5120, англ. From, «Отвечать» не перебивает «От», голый email, внутренний-домен-reject).
