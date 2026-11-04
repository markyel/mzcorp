---
name: references-comma-bug
description: "Битые References с запятыми (mail.ru) роняли ВСЮ исходящую отправку ящика: «Email \",\" does not comply with addr-spec»; санитизация message-id в 3 местах + удаление автоматного драфта при сбое"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Симптом (2026-07-09): менеджер жмёт «Отправить» в переписке заявки → «Не удалось отправить через SMTP — попробуйте ещё раз»; в логе `OutgoingMailSender: SMTP send failed ... Email "," does not comply with addr-spec of RFC 2822`. Параллельно каждый час падал revival_offer по той же цепочке.

**Причина**: mail.ru разделяет заголовок References запятыми (не пробелами по RFC). `MessagePersister::extractReferences` резал только по пробелам → в `email_messages.references_header` попадали токены с хвостовой `,` и голая `","`. При ответе `EmailDraftService::mergeReferences` копировал их в драфт, а Symfony `IdentificationHeader` валидирует каждый ID как addr-spec → один битый токен роняет весь `build()`/send. Диагностика: воспроизводить `OutgoingMailMimeBuilder::build($draft, $mailbox)` в tinker — стек показывает точный заголовок.

**Фикс (commits 21aba3b, db66ce1, 32c19c8)**:
1. `OutgoingMailMimeBuilder::sanitizeMessageIds` — чистка In-Reply-To/References при сборке MIME (лечит уже сохранённые грязные драфты);
2. `EmailDraftService::mergeReferences` — санитизация при создании reply-драфта;
3. `MessagePersister::extractReferences` — на входе режем по `[\s,;]+`, требуем `@`;
4. `ClientNotificationService`: при сбое sendDraft автоматный драфт УДАЛЯЕТСЯ (иначе почасовой ретрай копит дубли: заявка 5426 — 257 драфтов за 11 дней; всего вычищено 1650 автоматных драфтов, отличие от ручных: у ручных ComposeForm `body_html=''`, у автоматных html непустой + created_at в HH:00).
