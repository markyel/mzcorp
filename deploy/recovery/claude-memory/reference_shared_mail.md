---
name: shared-mail
description: "Раздел «Почта выбывших»/«Почта»: не-заявочная переписка недоступных менеджеров, назначение ответственного, ответ с его ящика"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Шаринг почты выбывших менеджеров (commit 1eadddc, 2026-06-22). Проблема: письма ЗАЯВОК делегированной заявки видны acting-менеджеру, а НЕ-заявочная переписка из личного ящика недоступного менеджера — никому не видна, пока он в отсутствии (поставщики, рассылки, разовые письма). Решение — раздел с лентой такой почты + назначение ответственного + ответ с его ящика.

**Что в ленте (`SharedMailService::baseQuery`):** входящие `email_messages`, чей `mailbox.owner_user_id` = СЕЙЧАС недоступный request-handler (`unavailableManagerIds()` = `User::role(requestHandlerRoles)->get()->filter(isUnavailable)`), `related_request_id IS NULL`, минус cross-mailbox копии (`detected_artifacts->>'cross_mailbox_copy_of' IS NULL`) и минус наши внутренние отправители (`config services.mail.internal_domains`). Живой запрос, БЕЗ материализации. На проде на старте: 219 писем (Румянцев 15 + 19 недоступны).

**Состояние — таблица `shared_mail_assignments`** (side, на email_message_id unique): `assigned_user_id` (ответственный) + `assigned_by_user_id`/`assigned_at` + `read_at`/`read_by_user_id` (ЕДИНЫЙ флаг прочитанности, сбрасываемый). Relation `EmailMessage::sharedAssignment()` (hasOne).

**UI — один компонент `Mail\AbsentInbox` (роль-aware), роут `mail.absent` (`/dashboard/mail/absent`, manager + privileged):**
- РОП/директор/секретарь: «**Почта выбывших**» (sub-link из `/dashboard/mail`), фильтр по выбывшему менеджеру; **назначение ответственного** (select в строке) — ТОЛЬКО привилегированные (`canAssign` = head_of_sales/director/admin).
- Менеджер: рейл-пункт «**Почта**» (left-rail: `manager` → `mail.absent`, у него нет общей `mail.index`); по умолчанию фильтр **«Назначенные мне»** (`assignmentFilter='mine'`); назначенные ему помечены, может отвечать.
- Фильтры: выбывший менеджер / назначение (все/мне/без ответственного) / прочитанность / поиск. Открытие письма (`toggleExpand`) → `markRead`. Кнопка «↺ снять прочитанность».
- **Ответ** (`SharedMailService::sendReply`): ТОЛЬКО назначенный менеджер (`canReplyTo` = sharedAssignment.assigned_user_id == me), строит reply-draft (threading `in_reply_to`/`references`, recipient = отправитель оригинала) из `$manager->primaryOutboundMailbox()` и шлёт через `OutgoingMailSender::sendDraft` (паттерн как [[suppliers-module]] SupplierProcurementDispatchService::createDraft). Отправка с ЕГО ящика.

Контраст: делегирование ЗАЯВОК (заявочные письма) — [[request-access-delegation]]; это про НЕ-заявочную переписку. rail active `mail.*` в layouts/app.blade.

**Тред-вид + вложения/CC в ответе (commit 0d30e74, 2026-06-22, на проде):**
- **Тред:** раскрытие письма (`toggleExpand`) показывает ВСЮ цепочку (`SharedMailService::threadFor` — computed `thread` в компоненте), а не одно письмо. Связь по threading-заголовкам (`message_id`/`in_reply_to`/`references_header`), НЕ по заявке (related_request_id IS NULL). Исключает `is_draft` и cross-mailbox-копии; **дедуп по Message-ID** (одно физ. письмо лежит в Inbox+Sent → задвоилось бы; uniq mailbox_id+folder+message_id); лимит 50; порядок sent_at ASC. Blade рисует входящие («входящее») + наши ответы («наш ответ»), вложения каждого письма ссылками на `attachments.download`.
- **Цитата уже уходила** — `OutgoingMailMimeBuilder::composeFinalBody` клеит quote из `in_reply_to`+подпись (НЕ было новой работой); добавлена лишь пометка в форму.
- **Вложения в ответе:** `sendReply($original,$manager,$body,$uploadedFiles=[],$cc=[])` материализует `EmailAttachment` на draft ДО `sendDraft` (паттерн ComposeForm; `materializeAttachments`); при провале отправки draft+файлы чистятся (`discardFailedDraft`). Компонент: `WithFileUploads`, `newFiles`/`replyCc`, валидация `file|max:25600`, `removeNewFile`, `@set_time_limit(180)`.
- **CC:** опц. поле «Копия» → `parseRecipients` (как ComposeForm).
- **Доступ к вложениям:** `AttachmentController::authorizeAccess` — для писем без заявки доступ у назначенного (`sharedAssignment.assigned_user_id`) + автора нашего исходящего (`direction=outbound && draft_author_user_id`). Privileged — как раньше. **Ограничение v1:** входящее ПРОДОЛЖЕНИЕ треда без своего назначения → 403 у менеджера (privileged видят); назначь его себе из ленты.
- **РЕАЛЬНУЮ отправку с вложениями по-прежнему НЕ тестировали** (уходит наружу).

**Ответ директора/админа без назначения (commit 7aefdf8, 2026-06-22, на проде):** директорат и админ могут отвечать на письмо, НЕ назначая ответственного. `AbsentInbox::canReplyUnassigned` (director/admin) + `canReplyTo` пускает их; blade — кнопка/форма + динамичная подсказка. Выбор ящика в `sendReply($original,$actor,...)`: если отвечает НАЗНАЧЕННЫЙ — со СВОЕГО ящика, от своего имени/подписи (как было); если директор/админ БЕЗ назначения — с **ЯЩИКА ВЫБЫВШЕГО** (`$original->mailbox`, адрес на который писал клиент): непрерывность, `from_name`+подпись = выбывшего (`draft_author_user_id`=owner для composeFinalBody), реальный автор в `X-MyLift-Author-User-Id` header (аудит), `markRead` read_by=actor. Заказчик выбрал «ящик выбывшего» (а не свой). РОП (head_of_sales) шорткат НЕ получил — только director/admin.
