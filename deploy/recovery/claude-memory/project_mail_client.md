---
name: mail-client-feature
description: "Новый раздел «Почта» — почтовый клиент для менеджеров (не org-wide /dashboard/mail); решения, фазы, что переиспользуем"
metadata: 
  node_type: memory
  type: project
  originSessionId: <скрыто>
  modified: 2026-09-02T08:56:16.494Z
---

Строим раздел **«Почта»** — привычный почтовый клиент для менеджеров (3 панели: ящики+папки | список тредов | чтение + плавающий композер). Это НЕ существующий `/dashboard/mail` (тот — org-wide read-only админ-витрина всех ящиков для head_of_sales/secretary/director/admin; менеджеры туда 403 на роуте `role:...` + `abort_unless` в `Mail\Index::mount`).

**Продуктовые решения (утв. пользователем):**
- Полноценный клиент: чтение + ответ/всем/переслать/написать + прочитано/флаги/папки.
- Все доступные менеджеру ящики: личный (`mailbox.owner_user_id=me`) + общие (info@/order@) + делегированные/shared.
- Гибрид: письмо привязано к заявке (`related_request_id`) → отправка ведёт пайплайн (детекторы/статусы), как в `ComposeForm`; свободная переписка → чистая почта без вмешательства в заявки.
- Композер — **богатый HTML-редактор** по макету (B/I/ссылка/список, хранить+санитайзить body_html) — на Фазе 2.
- Массовые действия (bulk) — Фаза 3 (полировка).
- Роут `/dashboard/mail/inbox`, доступ **manager + admin** (можно расширить на РОП/директора).

**Дизайн-эталон:** `10-mail-client.html` из дизайн-системы (загруженный zip `MyZip__MyLift_Design_System`, каталог `ui_kits/crm/`), на токенах `colors_and_type.css`. Топбар+rail+3-pane mail shell, папки Входящие/Отправленные/Черновики/Помеченные/С заявкой/Без заявки + «Другие ящики». Карта экранов — артефакт `mail-client-screens.md`. Стенд-ины по SKILL: Inter / Lucide / реконстр. лого.

**Переиспуем (готово, не переписываем):** `EmailMessage` + `scopeVisibleTo`; треды `SharedMailService::threadFor` (по RFC-заголовкам) или по `related_request_id`; отправка `EmailDraftService`+`OutgoingMailboxResolver`+`OutgoingMailSender::sendDraft`; рендер тела трейт `RendersEmailBody` + роут `attachments.inline`; поиск `Mail\Index::buildQuery` + trgm-индексы; дизайн `ds-card`/`chip`.

**Новое (пробелы):** `MailboxAccessService` (какие ящики видит менеджер); абстракция папок (смарт-виды поверх `direction`/`folder`/`is_draft`, без миграции папок); таблица `email_message_user_states` (`user_id,email_message_id,read_at,flagged_at`) — прочитано+флаг на пользователя (IMAP `\Seen` НЕ трогаем, правило CLAUDE.md §8); режим `forward` в композере.

**Фазы:** P1 чтение (роут+доступ, MailboxAccessService, папки, список тредов, панель чтения, read-state). P2 запись (ответ/всем/переслать/написать + богатый редактор + гибрид-хук + Черновики). P3 флаги/помеченные, bulk, поиск, счётчики, горячие клавиши.

## Статус деплоя (прод, ветка main)
- **P1 (чтение) — ЗАДЕПЛОЕНО и работает.** Грабли по пути: (1) computed переименован openMessage→openAnchor, blade ссылался на старое имя → 500; (2) `$req->status` уже enum RequestStatus — нельзя `tryFrom()` от enum; (3) тред по заявке двоился — добавлен фильтр `cross_mailbox_copy_of` + `unique(message_id)` (как в SharedMailService::threadFor).
- **P2a (композер: ответ/всем/написать) — ЗАДЕПЛОЕНО, проверено серверным Livewire-рендером (без реальной отправки).** Вынес `OutboundReplyHooks` (пост-хуки статусов из ComposeForm, чистая экстракция, User явно; ComposeForm делегирует). `EmailDraftService`: createDraft request-nullable + `createReplyFree`/`createComposeFree`. `Livewire\Mail\Composer` (+blade, plain-текст) встроен в Client, открывается событиями `mail-open-reply`/`mail-open-reply-all`/`mail-open-compose`/`mail-open-draft`; гибрид — заявка→createReply+хуки, свободное→createReplyFree. Черновик открывается в композере (Client::openMessage: is_draft→dispatch mail-open-draft). ГРАБЛИ: (a) приватный метод нельзя звать `hydrate` (зарезервированный lifecycle-хук Livewire!) → переименовал в `fillFromDraft`; (b) `email_messages.mailbox_id` NOT NULL — если резолвер не нашёл ящик отправки, createDraft падает на insert → обёртка `createOrToast()` даёт тост вместо 500 (как try/catch в ComposeForm::openCompose).
- **P2 drag/resize:** окно композера перемещаемое/растягиваемое (Alpine :style объектом + x-teleport в body, localStorage `mylift.mail.composer.geom`), как ComposeForm заявки.
- **P2b — ЗАДЕПЛОЕНО, проверено.** (1) **Пересылка**: `EmailDraftService::createForward` (Fwd: с де-дублем префикса, тело=attribution+body_plain, копирование файлов вложений), Composer::openForward (событие mail-open-forward), кнопка «Переслать». (2) **Богатый редактор**: contenteditable+тулбар (B/I/U, списки, ссылка, removeFormat) под `wire:ignore`+Alpine sync в `$wire.bodyHtml` (execCommand); `HtmlSanitizer` (allowlist DOMDocument: p/br/div/span/b/strong/i/em/u/a/ul/ol/li/blockquote/h3/h4; a[href] только http(s)/mailto +target/rel; вырезает script/style/on*/прочие атрибуты). body_html уходит как HTML-часть — `OutgoingMailMimeBuilder::composeFinalBody` УЖЕ берёт непустой body_html как есть (подпись/цитата/футер доклеиваются), body_plain — производный (htmlToPlain: br/p→\n, strip_tags). fillFromDraft сидит редактор из body_html, для forward/plain — nl2br(e(body_plain)).
- **НЕ протестирована реальная SMTP-отправка** из клиента (не слал живому клиенту) — путь тот же OutgoingMailSender::sendDraft + хуки, но end-to-end подтвердить живым письмом (важно: проверить, что body_html корректно долетает + статус заявки).
- **P3 (осталось):** флаги-папка «Помеченные» уже есть; bulk-действия, кэш счётчиков для общих ящиков (сейчас 6 count-запросов на большом ящике), горячие клавиши, «Показать ещё»→норм пагинация, дедуп списка тредов (сейчас плоский список писем).

Связано: [[shared-mail]] (SharedMailService, shared_mail_assignments), [[floating-composer]] (ComposeForm), [[request-access-delegation]] (isAccessibleBy).
