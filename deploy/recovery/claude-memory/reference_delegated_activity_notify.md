---
name: delegated-activity-notify
description: "Уведомление acting-менеджеров о новых событиях по делегированным заявкам: колокольчик + email со ссылкой, троттлинг 30мин"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-08-31T10:45:58.078Z
---

Заявки отсутствующего менеджера делегируются другим (active delegation, `request_delegations.acting_user_id`, `ended_at IS NULL`; `Request::activeDelegations()`/`isDelegatedTo`). Раньше acting-менеджеры (новые ответственные) НЕ получали уведомлений о событиях по этим заявкам. Реализовано (2026-08-26): на новое письмо КЛИЕНТА по делегированной заявке acting получает **колокольчик + email со ссылкой**.

- Хук: `MailRouter` сразу после `attention->onClientReplied($linkedRequest)` (только `!$internalOnly` — реальное внешнее письмо клиента), зовёт `DelegatedRequestNotifier::notifyActingManagers($request)`.
- `DelegatedRequestNotifier`: по `activeDelegations()->actingUser`, дедуп + троттлинг **30 мин** на (acting+заявка) через запрос к `notifications` (`data->>'kind'='delegated_activity' AND data->>'request_id'=id`). Оригинальному владельцу (assigned) не дублирует.
- Bell: `RequestDelegatedActivityNotification` (database-канал, kind `delegated_activity`, data request_id/internal_code/subject/client_name). Рендер в `bell.blade.php` (иконка 🤝, ссылка по request_id → `requests.show`).
- Email: `DelegatedRequestActivityMail` (Mailable) через `SystemNotificationMailer::sendMailable` (per-mailbox SMTP shared-ящик, т.к. **MAIL_MAILER=log** — стандартный Laravel mail НЕ шлёт). Текст «По делегированной вам заявке {код} новое сообщение от клиента» + кнопка/URL `route('requests.show')` (абсолютный, APP_URL=mzcorp.ru). ГРАБЛЯ: свойство Mailable нельзя звать `$subject` (конфликт с базовым `Mailable::$subject`) → назвал `$reqSubject`.

Существующие manager-уведомления (RequestAssignedNotification, RequestAttentionOverdueNotification) — только database. Это первое с email. Связано: [[support-tickets]] (SystemNotificationMailer path).

## Авто-снятие делегаций при возврате (fix 2026-08-31, кейс Румянцев id15)
Делегирование: `ManagerUnavailabilityService::markUnavailable`/`delegateActiveRequests` (создаёт `request_delegations`), `markAvailable` (чистит `unavailable_*` + `update(ended_at=now)` по всем active делегациям original_user). ГРАБЛЯ: `markAvailable` звался ТОЛЬКО вручную (кнопка «Сделать доступным», `Admin/Managers/Index::markAvailable`). Если отсутствие задано датой и `unavailable_until` просто ИСТЁК — `available()` авто-true, но делегации НЕ закрывались (никто не жал кнопку). Румянцев: until=28.08 прошёл, 194 висячих делегации, заявки видели acting'и. У других это выглядело «автоматически», но фактически РОП жал кнопку (у закрытых `unavailable_until=null`, закрытие батчем в одну секунду). Фикс: крон-команда `users:revoke-returned-delegations` (hourly, routes/console.php, симметрична `users:apply-planned-unavailability`) — для менеджеров с истёкшим unavailable_until и живыми делегациями зовёт markAvailable. Диагностика: batch-ended_at = ручной markAvailable; null unavailable_until = markAvailable отработал.
