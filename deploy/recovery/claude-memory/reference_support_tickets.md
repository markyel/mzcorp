---
name: support-tickets
description: "Тикеты «связь с создателем»: email-контур (дайджест вместо письма на каждый ответ, resolved-mail вбирает неотправленные), in-app нотификации, гарды почтового пайплайна"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Модуль «связь с создателем» (support_tickets / support_ticket_messages): `SupportTicketService`, страницы Support\MyTickets (жирный непрочитанный + флажок 🚩 «вернуться позже», flagged_at) и /support (admin).

## Email-контур (переделан на дайджест, commit a9c6074, 2026-07-08)
Жалоба: по тикету #70 автору пришло 3 почти одинаковых письма за 15 минут (2 ответа + «решено»). Теперь:
- **Письмо на каждый ответ НЕ шлётся.** `notifyOnReply` даёт только in-app `SupportTicketReplyNotification` (bell, badge, жирный в «Моих обращениях») — мгновенно. Email-сообщения копятся: `support_ticket_messages.emailed_at IS NULL`.
- **Крон `support:email-pending-replies`** (everyFiveMinutes, есть --dry-run): группирует неотправленные по (тикет × сторона), шлёт ОДНО письмо `SupportTicketDigestMail` на пачку после «тихого окна» `support.digest_quiet_minutes` (=5, env SUPPORT_DIGEST_QUIET_MINUTES) от ПОСЛЕДНЕГО сообщения пачки. Стороны: ответы создателя → автору тикета; реплики автора → `support.developer_email`. Нет получателя → штампует без отправки (не копить вечно). Сбой SMTP → не штампует, ретрай следующим прогоном.
- **`changeStatus` → resolved/closed**: письмо «решено» (`SupportTicketResolvedMail`, принимает Collection $answers) вбирает ВСЕ неотправленные ответы админа и штампует их → «ответил и сразу закрыл» = одно письмо. Если всё уже уходило — прикладывает последний ответ.
- Backfill в миграции: вся история emailed_at=created_at, первый прогон ничего старого не шлёт.
- `SupportTicketCreatedMail` разработчику при создании тикета — по-прежнему сразу (одно событие = одно письмо).
- `App\Mail\SupportTicketReplyMail` остался в коде, но больше не используется (заменён дайджестом).

## Гарды почтового пайплайна
Все письма через `SystemNotificationMailer` (SMTP общего ящика info@myzip.ru, MAIL_MAILER=log на проде) с заголовком `X-MyLift-System-Notification`; `MailRouter::route()` первой строкой отбрасывает их (category=irrelevant), mail-review исключает через `jsonb_exists(headers, 'x_mylift_system_notification')` (не оператор `?` — конфликт с PDO).
