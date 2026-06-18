# MEMORY.md — Текущий контекст MyLift

> Этот файл — рабочая память между сессиями. Перед началом работы — прочитай его целиком. После значимых изменений — обнови.

## Архитектурные принципы (обновлено 2026-05-15)

**Implicit-state** — статус заявки выводится из активности менеджера, а не выставляется вручную:

| Действие менеджера | Триггер | Статус |
|---|---|---|
| Открыл карточку (owner/acting) | `Detail::mount` → `transitionTo` | `Assigned/New → InProgress` |
| Отправил batch уточнений | `ComposeForm::applyPostSendHooks` | `* → AwaitingClientClarification` |
| Выслал КП (PDF/XLSX или body-keyword) | `OutboundDocumentDetector` + LLM-fallback → `AiDecisionService` | `* → Quoted` (semi-auto через AI-плашку) |
| Поставил флаг внимания | `AttentionService::setManual` | attention.reason=`Manual` |
| Поставил на паузу | `RequestPauseService` | `* → Paused` |

**Inbound события клиента**:
- Клиент ответил в треде → `MailRouter::route` inbound → `AttentionService::onClientReplied` (info-flag «есть ответ») + `InboundIntentClassifier` (gpt-4o-mini, 6 интентов) → AiDecision (under_review / postponement / invoice_request / decline / clarification_response / unclear)

**Кнопки в action-panel убраны**: «▶ Начать работу», «📨 КП отправлено», «❓ Жду уточнение клиента», «📑 Клиент на согласовании», «⏰ Клиент отложил», «💵 Запросил счёт». Остались semi-manual: «↩ Вернуться к работе», «✓ Клиент ответил» (override), «💴 Счёт отправлен» / «💰 Оплачено» / «✓ Закрыть как успех» (outbound события менеджера/бухгалтерии — не intent), «❌ Закрыть как потеря» (с reason-taxonomy), pause/resume. Все inbound intent-переходы (UnderReview / PostponedUntil / AwaitingInvoice / ClosedLost suggestion) идут через `InboundIntentClassifier` → AiDecision-плашку. PostponeDialog оставлен как dead-UI компонент (никто не диспатчит `open-postpone-dialog`) — на случай будущего «Manual override для semi-auto статусов».

**Mail-pipeline для нового inbound**:
0. `MailRouter::route` — **SenderBlocklistService::isBlocked** (ДО LLM, ДО reply-linker): если from_email или его домен в `sender_blocklist` → category=irrelevant + `category_reasoning="Blocked by sender_blocklist..."` + `routed_mails.action_taken=blocklist_skipped`, выходим. Inc `hit_count`/`last_hit_at` на матчнувшей записи. Defense-in-depth повторно в `IncomingMailProcessor::processIfRequest` (для cron-команд минующих router). См. секцию «Sender Blocklist» ниже.
1. `MailRouter::route` — cross-mailbox дедуп ДО LLM: если message_id уже у нас в БД с related_request_id → наследуем категорию + Request, выходим (защита L2)
2. `InternalSenderDetector` (наш домен / Mailbox / User → category=irrelevant, без LLM)
3. `TrustedPartnerOverride` (Liftway-saas → category=client_request, без LLM)
4. `MailCategoryClassifier` (gpt-4o → client_request / thread_reply / irrelevant)
5. `InboundReplyLinker` — Level 0: same message_id linked (защита L3) → Level 1-5: In-Reply-To / References / subject `M-2026-NNNN` / external `LZ-REQ-NNNN` / from_email+open / AI
6. `IncomingMailProcessor::processIfRequest` (если category=client_request И не linked И не empty-body → Request::create)
7. `AssignmentService::autoAssign` (sticky + round-robin, role=manager OR head_of_sales)
8. `MailFolderRouter::routeToManager` (IMAP COPY в `MZ|<Фамилия>` общего ящика — для секретаря)
9. `DeliverToManagerInboxJob` (IMAP APPEND полного RFC822 в INBOX личного ящика менеджера + pre-create EmailMessage row с cross_mailbox_copy_of marker — защита L1 от sync-дублей)
10. `ParseRequestItemsJob` (Vision + text парсер позиций, KB resolve, catalog match A/B/C)

**Cross-mailbox copy маркер**: `email_messages.detected_artifacts.cross_mailbox_copy_of` = id оригинальной записи. UI thread (Detail::mount) фильтрует такие row'ы — показывается только оригинал. MessagePersister при sync обновляет imap_uid+flags+raw_source у pre-created row, MailRouter не запускается.

**Mail-pipeline для outbound** (менеджер ответил через Yandex web UI):
1. `OutgoingMailLinker` (5 уровней, аналогично inbound) → линкует к Request
2. `OutboundDocumentDetector` rule-based (filename regex / keyword) → AiDecision
3. Если null → `OutboundDocumentClassifier` LLM (gpt-4o-mini, 4 типа) → AiDecision
4. `RequestActivityService::touch` → `ManagerReplied` (silences ClientReplied / FreshAssignment)

## Текущая фаза

**Фаза 1 (MVP) — чтение и классификация писем.** Стартовала после полного развёртывания инфраструктуры.

Цель: к концу фазы команда видит, что система разбирает поток почты, ставит IMAP-метки в Yandex 360, форвардит на нужные адреса. Заявки попадают в систему, обрабатываются минимально (без полного KB и sticky-распределения — это Фаза 2).

### Декомпозиция Фазы 1

| # | Задача | Источник | Статус |
|---|---|---|---|
| 1.1 | Документация: `CLAUDE.md`, `MEMORY.md`, `README.md` | новое | done |
| 1.2 | Auth + 4 роли через `spatie/laravel-permission` (Breeze blade) | новое | done |
| 1.3 | Модели email-инфры: `Mailbox`, `EmailMessage`, `EmailAttachment` + миграции | новое | done |
| 1.4 | `webklex/laravel-imap` + OAuth XOAUTH2 (Yandex 360), `MailboxConnector`, `SyncMailboxJob`, `mail:sync`, `mail:add`, `mail:test`, `mail:oauth` | новое | done |
| 1.5 | `MailRoutingRule` + `RoutedMail` + Engine + Label + Forwarder + Router + CLI + UI `/dashboard/mail-rules` (объединил 1.5 и 1.7) | новое | done |
| 1.6 | AI-классификация писем (`gpt-4o-mini`): `request \| reclamation \| accounting \| general_question \| spam \| other` | `OpenAIChatService` drop-in из LazyLift + новый промпт | done |
| 1.7 | (объединён в 1.5) | — | done |
| 1.8 | Минимальный `Request` + `IncomingMailProcessor` + round-robin `AssignmentService` + 165 заявок backfilled | базовая модель из LazyLift в урезанном виде | done |
| 1.8b | Content-driven парсинг позиций (`RequestItemParsingService` drop-in) + `RequestItem` + `RequestItemPersister` + `MailFolderRouter` (COPY в `MZ\|{Lastname}` подпапку для секретаря) + CLI `requests:parse-items` | drop-in из LazyLift @ 25b59645 + новые сервисы | done (CLI-only, MailRouter-интеграция отложена) |
| 1.8c | Phase 1.8c email categorizer (`MailCategoryClassifier`, gpt-4o, 3 категории `client_request \| thread_reply \| irrelevant`) + `email_messages.category` + проброшен в `MailRouter::route` | n8n Flow 1 v9.2 → `CategorizeIncomingPrompt` | done |
| 1.8d | UI redesign: design tokens + 48px topbar + Pool с фильтрами + RequestDetail с 7 табами + Dashboard | `design/ui_kits/crm/` | done (`3604f50`..`0b4f652`) |
| 1.8d-pending | `RequestStatus::Pending` + `ParseRequestItemsJob` (auto-parse по dispatch) + менеджер не видит pending в пуле | новое | done (`d32c7c2`) |
| 1.8e | LazyLift v5 промпт парсера (PHP-порт `Code: Prepare Parse Input`) + `EmailTextCleanerService` (dequote→forward→removeSignature) + `htmlToText` для HTML-only писем + few-shot positives + structured user-prompt | n8n Flow 1 v9.2 → `ParseItemsPrompt` | done (`c18279f`..`78ec391`) |
| 1.9-inbound | `InboundReplyLinker` (5 уровней: In-Reply-To / References / subject `M-2026-NNNN` / from_email + open Requests / AI multi-choice через `ThreadClarificationAi` gpt-4o-mini) + парсинг reply'ев с force=true + suspicious-link warning | n8n `AI Agent: Process Clarification` + новое | done (`9353df7`, `f7aefe9`, `57915de`) |
| 1.9-outbound | `OutgoingMailLinker` (4 уровня линковки исходящих к Request: In-Reply-To / References / subject `M-2026-NNNN` / to_recipients + open) + branch в MailRouter | новое | done (`6932d2e`) |
| 1.10 | UI `/dashboard/requests` — пул менеджера + карточка заявки + inline attachments | новое (Livewire 4) | done (Phase 1.10 → пересобрано в Phase 1.8d с 7 табами) |
| 1.11 | Дашборд РОПа v0: KPI, AI breakdown, manager load, mailbox health, последние пересылки | новое | done |
| 1.12 | Полный redesign UI по `design/ui_kits/crm/` + `colors_and_type.css` | дизайн-система | done (закрыто как Phase 1.8d) |
| 1.13 | Pool redesign + topbar v2 (`03-requests.html`) + email-style isolation iframe srcdoc + image preview/lightbox + RFC 6266 attachment download + UI управления менеджерами (CRUD + soft-archive + OAuth verification_code attach) + manual reassign в карточке заявки | новое | done (`f2a778e`..`a06fccd`) |
| 1.13a | Sticky-snapshot links в карточке заявки: `AssignmentService::pickStickyManager` пишет JSON в `request_assignments.reason` (`auto_sticky:{linked:[id...]}`), Detail::sticky() парсит, Hero status-row показывает chip с `wire:navigate`-якорями | новое | done (`b1db73f`) |
| 2.0 | KB drop-in из LazyLift @ 7fee1f77: 11 таблиц (manufacturer_brands/equipment_categories/identification_parameters/rules/extractors/request_context/kb_audit_log) + 11 моделей + 10 сервисов (BrandResolution/CategoryRefinement/QualityAssessment/RequestContextAnalysis/...) + 2 промпта + 11 сидеров с JSON-данными + ResolveKbJob + KbResolveCommand + UI chips в табе Позиции + интеграция в RequestItemPersister | LazyLift drop-in + adapt | done (`d5ea03c`..`c16d26a`) |
| 2.0-fixes | SupplierResolverService stub (DI требует) + insufficient-when-brand-known (вместо not_covered) + synonym word-boundary fix через `\p{L}\p{N}_` lookarounds (LazyLift substring match ловит «ОС» в «пост») + `--reset-categories` флаг для пересчёта preserve'нутых items + internal MyLift SKU detection (`M\d{4,}` → `internal_catalog_pending`, без LLM-цепочки) | новое | done |
| 2.1 | Bugfix 500 на табе «Позиции»: `RequestItem::category()` relation коллидировал со строковой колонкой → rename в `kbCategory()` | новое | done (`ffa2f9a`) |
| 2.2 | Web-fetch URL'ов из тела письма: `inbound_url_fetches` + SSRF guard + DOMDocument-based extraction + cache 7д + sync intеграция в parseItemsFromInboundMessage | новое | done (`1d5c703`) |
| 2.3 | `--reset` флаг для force re-parse — стирает RequestItem'ы перед persist | новое | done (`e63feba`) |
| 2.4 | Привязка фото-вложений к позициям через Vision `image_index` → `request_items.image_attachment_id` + thumbnail+lightbox в UI | новое | done (`cca3109`) |
| 2.4a | Vision dup-mapping fix: (1) `RequestItemEditor::rebindPhoto` + `ItemPhotoRebindDialog` (grid превью всех image-вложений письма + «без фото», audit в `payload.manual_edits[]` с `action=rebind_photo`); (2) Vision-промпт двухшаговый CoT — `photo_descriptions[]` сначала (main_subject / secondary_items / has_readable_marking), потом `image_index` с явными правилами (главный объект а не «виден в кадре», предпочтение closeup, запрет дубликатов кроме group-shot, «лучше null чем угадывать»); max_tokens 4096→6144; (3) `MessagePersister` filename fallback (trim + synthesize `<disposition>-<random>.<ext>` через `guessExtension` MIME-таблицу) + CLI `mail:backfill-attachment-names` для исторических ''-filename | новое | done (`c331c7d`, `a39e314`, `9709eaa`) |
| 2.5 | Thread-aware clarifications queue: 2-й LLM-проход (`DecideClarificationsPrompt`) на reply'е разделяет «new positions» vs «article clarifications»; `requests.pending_clarifications` (jsonb) + UI Apply/Reject | новое | done (`1e5640c`) |
| 2.6 | Каталог Liftway.ru drop-in: 30649 строк, POST /api/catalog/import (token-auth, hash-aware upsert, soft-delete, min_full_rows guard), CLI `catalog:import file.csv` (cp1251 autodetect) | новое | done (`a3a2d8f`..`94c5ef8`) |
| 2.6-A | Use-case A: M-SKU resolve через CatalogResolutionService::resolveItem → status=sufficient + payload.catalog | новое | done |
| 2.6-B | Use-case B: brand_article match через `brand_article_normalized` + normalize-симметричный normalizeArticle | новое | done |
| 2.6-C | Use-case C: vector match через pgvector(1536) HNSW + 3-stage retrieval (article check + LLM validation gpt-4o-mini + retry+sleep + llm_fail_action=reject) | новое | done |
| 2.7 | Hero «Сумма» / «Сматчено N/M (%)» + НДС 22% configurable | новое | done (`086c55b`, `6729fc6`) |
| 2.8 | UI «Настройки» (`/dashboard/settings`): `app_settings` override config + Livewire admin страница (7 параметров в 3 группах, role-gate head_of_sales/director) | новое | done (`44f68b1`..`94c5ef8`) |
| 2.9 | Vision-промпт: numbered text list authoritative count of positions (фикс M-2026-0512 — клиент пишет «1. Левая, 2. Правая», Vision сливал) | новое | done (`a12e20d`) |
| 1.9-ui | UI-переписка из карточки заявки: Livewire `ComposeForm` (reply / reply-all / compose) + `WithFileUploads` + drag&drop + drafts с auto-save + auto-attach + Apple-Mail RU цитата + подпись из `users.email_signature` + IMAP APPEND в Sent + дедуп по `X-MyLift-Reply` при Sent-sync + ProfileUpdate UI | новое (Phase 1.9) | done (`6864812`..`5afd521`) |
| 1.10p1 | **Priority 1** ручное управление позициями + каталогом: `RequestItemEditor` (editFields/softDelete/restore/unbind/linkToCatalog/refreshFromCatalog/markCatalogNotFound/mergeIntoExisting) + `CatalogSearchService` + enum `QualityAssessmentStatus` + `internal_catalog_not_found` миграция + UI dropdown «⋮» с действиями + `ItemEditDialog` / `ItemCatalogLinkDialog` (text + similar вкладки) + audit в `payload.manual_edits[]` + toggle «Показать удалённые» + bulk «Refresh всех» + ручной merge «🔗 Это уточнение позиции…» | новое | done (`259d0ed`..`9117c2d`) |
| 1.10 | Минимальная state-machine (Foundation §5.2): 11 новых статусов (`in_progress`, `awaiting_client_clarification`, `quoted`, `under_review`, `postponed_until`, `awaiting_invoice`, `invoiced`, `paid`, `paused`, `closed_won`, `closed_lost`) + `ClosedLostReason` taxonomy (10) + `RequestStateService` + `RequestPauseService` + audit `request_state_changes` + cron `requests:resume-paused` (dailyAt 06:00) + UI action-panel с allowedTransitions + `PauseDialog` / `CloseLostDialog` + Activity-tab merge state-changes/assignments + Pool bucket-фильтры (active/paused/closed/all) + chip-фильтры внутри bucket + auto-сброс stale URL-фильтра при mount | новое | done (`00a806b`, `49e78a7`, `9522c17`) |
| 1.10-fixes | Sticky-navigate ломал Detail-page (wire:navigate → SPA-state stuck); + кириллический «М» (U+041C) в M-SKU detector через новый helper `CatalogImportService::cyrillicLookalikeFold()` (11 пар lookalike-букв) — applied в `detectInternalCatalogSku` / `extractSku` / `normalizeArticle`; + toggle «📎 Sticky-позиции» в табе Позиции (forward + reverse-search в `request_assignments.reason`) с тем же layout что main-list через partial `_item-row.blade.php` | новое | done (`a86323f`, `7ccb044`, `1e9e558`) |
| 1.11 | **Attention-механизм** (Foundation §5.3 + §5.5): `requests.attention_required_at` + `attention_reason` (enum `AttentionReason`, 7 значений) + денорм `attention_level` (0/1 overdue) + composite index `(assigned_user_id, attention_level DESC, attention_required_at NULLS LAST)` + partial index по overdue; `AttentionService::recompute() / clear() / sweepOverdue()` + business-hours helper (Пн-Пт 9-18 Europe/Moscow); интеграция в `RequestStateService::transitionTo()` + `recordSystemInitial()` + `RequestPauseService::pauseUntil()/resume()` (resume → attention_required_at=now, level=1, reason=postponed_resume); cron `requests:check-attention` everyFifteenMinutes (sweep level); Pool — orderBy attention для bucket=active/overdue + новый bucket «Просрочено» (flat-list без status-groups) + красный chip когда count > 0; row-tint `--red-50` + left-border accent для overdue; attention badge в title-cell (icon + «через 4ч» / «просрочено 2д»); Settings UI — 8 редактируемых дедлайнов в группе «Attention · дедлайны»; Dashboard РОПа — 2 KPI-плашки «Просрочено» / «Дедлайн сегодня» с кликом на pool?bucket=overdue; `PostponeDialog` — date + comment, пишет `payload.postponed_until` в state-change | новое | done |
| 4.0 | **DocumentDetector** (Foundation §7) — auto-переходы статусов по детекту КП/счёта/clarification в outbound и intent-классификатора reply'ев клиента в inbound. Schema: `ai_decisions` таблица (detector_type, status enum suggested→auto_applied/manually_confirmed/manually_overridden/dismissed/failed + confidence + payload + applied_by audit), `requests.closed_lost_quote` + `closed_lost_source_message_id` (Foundation §7.4). Enums `DetectorType` (10 значений с `targetStatus()` map), `AiDecisionStatus`. `AiDecisionService::recordSuggestion / apply / override / dismiss` — single source of truth для жизненного цикла, идемпотентен по (email_message_id, detector_type). `OutboundDocumentDetector` rule-based (filename regex + body keywords RU/EN; priority invoice > quotation > clarification; confidence 0.6/0.9/0.95 от комбинации сигналов). `InboundIntentClassifier` (gpt-4o-mini, `ClassifyClientResponsePrompt`) — 6 intent'ов с suggested_resume_date / suggested_closed_lost_reason / cited_phrase извлечением. Triggers в MailRouter: outbound-ветка после OutgoingMailLinker + inbound после InboundReplyLinker если статус Request в {quoted, under_review, postponed, awaiting_clarification}. UI plashka в Detail action-panel — иконка + label + confidence% + target-status + cited_phrase (italic blockquote) или reasoning + apply/dismiss кнопки. Apply disabled если переход не разрешён из текущего статуса. Foundation §7.3 validation framework: 8 toggle'ов auto_mode + общий `confidence_threshold` (default 0.85) в Settings; auto-apply gate в recordSuggestion (если включён И ≥ threshold → apply(auto=true) сразу). Dashboard РОПа — таблица «AI quality (30 дн.)» с counts (auto/confirmed/overridden/dismissed/pending) и correctness% per type, цветовая индикация (≥90 emerald, ≥70 amber, <70 red). | новое | done (`b876244`, `9fb94f5`, `cefd439`, `5ebe671`) |
| 5.2 | **Reanimate closed** (Foundation §5.2) — `closed_lost` заявки реанимируются автоматически при ответе клиента, без создания дубликата. Migration: `requests.reanimated_at` + `reanimated_count`. `RequestStateService::reanimate(Request, ?User, EmailMessage)` — guard ClosedLost only (ClosedWon никогда — сделка состоялась), сохраняет snapshot closed_at/reason/comment/quote в `state_change.payload.restored_from`, очищает closed_lost_* поля, status → InProgress, reanimated_at = now(), reanimated_count++, event=`reanimate`, AttentionService::recompute. `InboundReplyLinker`: header-threading (levels 1-3) реанимирует любой ClosedLost (явный thread-link); level 4-bis (from_email + closed_lost) с фильтрами — только silent reasons (`no_client_response_to_clarification/quote`, `off_topic`, `manual_other`) + lookback 180 дн. (декларативные decline'ы price/timing/competitor НЕ реанимируются по этому уровню). ClosedWon match → linker возвращает null, IncomingMailProcessor создаёт новую Request. UI Hero: violet chip «↻ реанимирована ×N · M дн.» рядом со статус-чипом если reanimated_count>0 + tooltip. Activity-tab: новый kind `state-reanimate` с violet dot + иконкой ↻ + by-line «InboundReplyLinker · автоматически». | новое | done (`6e51dc4`) |
| Ф2-add | **Доделки Foundation Фазы 2:** (1) **Менеджер «недоступен»** — `users.unavailable_until` + `unavailable_reason` + `scopeAvailable`; `AssignmentService::autoAssign` использует `available()` вместо `active()`; новый `ManagerUnavailabilityService` (markUnavailable / markAvailable / reassignActiveRequests — bulk через autoAssign + sticky/round-robin); UI в Admin/Managers: chip-warn «недоступен до DD.MM.YYYY» + tooltip с reason; диалог `UnavailabilityDialog` (date + reason + checkbox «передать активные заявки»). (2) **Auto-rejection irrelevant + reopen UI** — экран `/dashboard/mail-review` (role:head_of_sales,director): listing inbound писем где `ai_classification IN (irrelevant, reclamation, accounting, general_question, spam, other)` AND `related_request_id IS NULL`; фильтры по периоду (today/7d/30d/90d/all) + категориям chip-row с counters; action «↻ Это заявка» создаёт Request (Pending) + dispatch ParseRequestItemsJob, audit `manual_reopen_as_request` в detected_artifacts; action «✓ Согласен» audit `manual_confirm_rejection`. Nav-link «Авто-отклонённые» в topbar. (3) **In-app notifications** — стандартная Laravel notifications table (UUID + morphs notifiable + data jsonb); два класса `RequestAssignedNotification` (диспатчится из AssignmentService::autoAssign) и `RequestAttentionOverdueNotification` (диспатчится из AttentionService::sweepOverdue только на переход level 0→1, anti-spam); Livewire Bell-component в topbar (🔔 + red badge unread count + dropdown 8 последних, unread сверху, wire:poll.30s, markRead/markAllRead). Email digest — Phase 6. | новое | done (`c4326ee`, `ccb01e7`, `1b2b917`) |
| Ф2-deleg | **Delegation вместо reassignment** (пересмотр Ф2-add после боевого тестирования) — заявка ОСТАЁТСЯ за оригинальным менеджером (`assigned_user_id` не меняется), но на время его отсутствия другой менеджер получает временный доступ. При возвращении (markAvailable) — доступ закрывается, оригинал продолжает работу. Новая таблица `request_delegations` (request_id, original_user_id, acting_user_id, started_at, ended_at nullable, reason) + 3 индекса. Request helpers `isOwnedBy / isDelegatedTo / isAccessibleBy` используются в Detail::canManage / RequestStateService::ensureCanTransition / RequestPauseService::ensureCanPause / RequestItemEditor::ensureCanEdit — acting получает те же права что owner. `ManagerUnavailabilityService::reassignActiveRequests` → `delegateActiveRequests` (round-robin acting'ов по available менеджерам). `markAvailable` закрывает все active delegations этого менеджера (`ended_at = now()`). Pool::render scope=mine: `(assigned_user_id = me) OR (EXISTS active delegation acting_user_id = me)`. Counter «Мои» в navigation использует тот же предикат для согласованности. `AttentionService::sweepOverdue`: notification target = acting если active delegation, иначе assigned. UI: badge «↺ открыто мне» (amber) в Pool row для acting'а; badge «↺ acting: <name>» в Detail Hero рядом со статусом менеджера; UnavailabilityDialog text — «Открыть активные заявки коллегам на время отсутствия». Старые reassign'нутые заявки (через предыдущую версию `reassignActiveRequests`) НЕ откатываются автоматически — оператор может вручную через UI «переподчинить». | новое | done (`4832778`) |
| Ф2-plan | **Планируемая недоступность** — РОП может заранее поставить период отсутствия (С DD.MM ПО DD.MM). Migration: `users.unavailable_from` (timestamp nullable) + `unavailable_auto_delegate` (bool default false). `User::scopeAvailable` расширен: available iff `archived_at IS NULL AND (unavailable_until IS NULL OR <= now() OR unavailable_from > now())`. `User::isUnavailable()` — true только когда период идёт (from<=now AND until>now); `User::isUnavailabilityPlanned()` — true когда from>now. `ManagerUnavailabilityService::markUnavailable($user, ?Carbon $from, Carbon $until, $reason, $byUser, $autoDelegate)` — NULL from = сразу, future from = план. UI Dialog: два date-input'а («С» опционально, «По» required) + checkbox «открыть заявки коллегам». При сохранении: from <= now → синхронно `delegateActiveRequests`; from > now → план, отложено до cron'а. CLI `users:apply-planned-unavailability` (hourly cron) ищет менеджеров где `unavailable_from <= now AND unavailable_until > now AND auto_delegate=true` AND нет active delegations → dispatch `delegateActiveRequests`. Idempotent. UI Admin/Managers: новый chip-info «план: DD.MM – DD.MM.YYYY» для запланированной недоступности; кнопка «Отменить план» для планов / «Доступен» для активной недоступности. **Гарантия:** `AssignmentService::autoAssign` использует `available()` — менеджер во время отсутствия (текущего ИЛИ наступившего из плана) НЕ получает новые заявки. | новое | done (`4ca969a`) |
| Ф2-fix-reply | **Detail::composeReply** — кнопка «✉ Ответить» в action-panel не работала, потому что ComposeForm зарегистрирован только в табе «Переписка», а из других табов dispatch'енный `open-reply` event никто не слушал. Action `Detail::composeReply(?int $messageId)` сначала переключает `$tab = 'thread'` (ComposeForm рендерится при ре-рендере), потом диспатчит `open-reply` / `open-compose`. Livewire гарантирует порядок: patch DOM → dispatch event. | bug fix | done (`f65554c`) |
| §6.2 | **Структурированные уточняющие вопросы клиенту** (Foundation §6.2 + LazyLift ClarificationRequest). Новые таблицы `clarification_batches` (id, request_id, status drafted/sent/answered/cancelled, general_question, draft_email_id, sent_message_id, sent_at, answered_at, created_by_user_id) + `clarification_questions` (id, batch_id, request_item_id nullable=общий вопрос, question, answer/answered_at/answered_via_message_id для Phase B KB enrichment). Livewire `ClarificationPanel` в табе «Позиции» под таблицей: накопительная форма с textarea «общий вопрос» + textarea per item, pending counter, кнопка «📨 Сформировать письмо (N)» создаёт ClarificationBatch + ClarificationQuestion rows, создаёт draft через `EmailDraftService::createReply` (или `createCompose` fallback) на последнее inbound клиента — даёт threading + цитату, заполняет subject + body (rendered template приветствие + список вопросов с item-info + общий вопрос), пишет marker `{type:'clarification_batch', batch_id, transition_to_status}` в `draft.detected_artifacts`, dispatch `clarification-letter-ready` → Detail переключает таб на «Переписка» + dispatch `open-draft` → ComposeForm раскрывает draft, менеджер может править. `ComposeForm::send` после `sendDraft success` вызывает `applyPostSendHooks($sent)` — читает detected_artifacts, если есть `clarification_batch` marker: (1) ClarificationBatch → status='sent' + sent_message_id + sent_at; (2) `RequestStateService::transitionTo(AwaitingClientClarification)` с event=`clarification_sent`. RequestStatus::allowedTransitions расширен: New→AwaitingClientClarification и Assigned→AwaitingClientClarification (без промежуточного InProgress). Permission: assigned manager + acting (delegation) + privileged; secretary — read-only. | новое | done (`13d9635`, `bb41d0d`, `14929a1`, `14288de`) |
| §6.2-A | **Phase A — UI history заданных вопросов** (display only). В табе «Позиции» per-item: border-left sky-300 блок с заданными вопросами по этой позиции, chip-статус (✏ черновик / ⏳ ждём ответ / ✓ ответ получен), текст вопроса, ответ клиента (emerald plaque) если есть. Над таблицей — card «История уточнений» со всеми sent/answered batches заявки (общий вопрос, число per-item, дата отправки, автор). Detail eager-load `items.clarificationQuestions.batch.createdBy` + `clarificationBatches.questions.createdBy`. | новое | done (`427979c`) |
| §6.2-BC | **Phase B+C — LLM матчинг ответов + enrichment одним кликом**. Когда клиент отвечает в треде, MailRouter inbound branch собирает все sent+не-answered ClarificationBatch заявки → async dispatch `MatchClarificationAnswersJob(message_id, batch_id)` per batch. Job вызывает `ClarificationAnswerMatcher::match`: единый LLM-вызов (gpt-4o-mini, ~3-4k токенов, ~$0.005) с `MatchClarificationAnswersPrompt` решает обе задачи: (B) сматчинг ответа на конкретные вопросы → заполняет `clarification_questions.answer + answered_at + answered_via_message_id` (не перезаписывает existing — первый ответ важнее); если все вопросы batch'а ответили → batch.status='answered' + answered_at=now(); (C) extraction enrichment_suggestions — артикул/бренд/qty из текста ответа → сохраняет в `request_items.quality_assessment_payload.enrichment_suggestions[]` (status=pending, дедуп по {field, normalized value}, confidence-floor 0.6, source_quote). UI: per-item amber-карточка под позицией с иконкой 💡 «Клиент прислал: <field> → <value> · NN%» + cited quote + кнопки «✓ Применить» / «✕ Отклонить». Apply → `RequestItemEditor::applyEnrichmentSuggestion` дёргает `editFields([field=>value])` (standard manual edit с audit в manual_edits[]) + помечает suggestion status='applied' + applied_at + applied_by. Dismiss — то же, но status='dismissed'. ShouldBeUnique 10 мин по {email_message_id, batch_id} — повторные dispatch'ы не плодят. Permission: owner/acting/privileged через ensureCanEdit. | новое | done (`02cf155`) |
| §6.2-D | **Phase D — структурный target_slot_key для каждого вопроса**. Менеджер задаёт вопрос через «+ спросить» в слоте позиции или через quick-chip → ClarificationPanel запоминает, какой slot пытались заполнить (`clarification_questions.target_slot_key` varchar(64) nullable + index): `brand`, `article`, `qty` для base-слотов или `kb:<slug>` (lift_brand, lift_series, и т.д.) для KB-параметров. `MatchClarificationAnswersPrompt` обогащён: per question — `target_slot_key`; per item — `available_slots[]` (label, current value, required flag), что разрешает LLM возвращать `field=kb:<slug>` напрямую. `ClarificationAnswerMatcher` поддерживает `kb:*` в ENRICHABLE + дедуп; `RequestItemEditor::applyEnrichmentSuggestion` обрабатывает `kb:<slug>` записью в `extracted_parameters`. Менеджер может пере-направить suggestion в другой slot вручную через dropdown «→» (`applyEnrichmentSuggestionToSlot`) — если клиент написал «марка КМЗ» в ответ на «марка лифта», операторский fallback переведёт base brand → kb:lift_brand. | Foundation §6.2 Phase D | done (`3502641`, `b069ce2`) |
| §6.2-E.1 | **Phase E.1 — top «Предложенные уточнения» с diff и bulk-actions**. Над таблицей позиций в табе «Позиции» — sblock «Предложенные уточнения» (sbh header с linear-gradient violet→surface + violet pill counter): scard per suggestion с target/diff/reason слева (item.position, field-label, было→стало с del/ins markup, cited_quote курсивом) + confbar справа (прогресс % + per-card apply/dismiss). Bulk-actions «✓ Применить все» / «✕ Отклонить все» в header. Per-card enrichment plate из _position-card.blade.php удалена — всё консолидировано вверху. | Foundation §6.2 Phase E1 + макет 04c | done (`75ffd6f`, `513beeb`) |
| §6.2-E.2 | **Phase E.2 — AI banner вверху + applyAllAndProgress + rollback**. Над hero (между subnav и контентом) — AI banner: grid `auto 1fr auto`, linear-gradient violet→surface, w-10 h-10 icon (oklch(54%) violet), inline summary + mono conf pill. Кнопки «Скрыть» (state `$aiBannerHidden`) и conditional «Открыть позиции →» (скрыта если уже в табе items). `applyAllAndProgress` — combo apply + auto-transition на `awaiting_invoice` или next-allowed. `RequestItemEditor::rollbackEnrichmentSuggestion` — null base / unset kb slot + mark suggestion status='reverted'. AI banner styled 1-в-1 с 04c CSS (несколько iteration'ов). | Foundation §6.2 Phase E2 + макет 04c | done (`51cb41d`, `ffb58d5`, `5d129aa`, `08e8424`, `2cebb18`) |
| §6.2-E.3 | **Phase E.3 — auto-apply high-confidence + applied lane**. `config/services.php`: `clarifications.auto_apply_threshold` (default 0.95) + `auto_apply_require_target` (default true). `ClarificationAnswerMatcher`: если confidence ≥ threshold AND `suggestionMatchesQuestionTarget()` → apply через editor + mark `auto_applied: true` в payload. UI lane «✓ Применено» между pending suggestions и таблицей позиций с кнопкой «откатить» per item (rollbackEnrichmentSuggestion). Фильтр legacy "null"/"none"/"—"/"-"/"n/a" answer-строк в matcher + defensive view-side filter. | Foundation §6.2 Phase E3 | done (`02b1d3f`, `d79c299`) |
| §6.2-E.4 | **Phase E.4 — combo-режим карточек позиций + история под списком**. По умолчанию позиции в компактном виде (как раньше); ❓ toggle справа разворачивает per-item панель уточнений (slots + chips + free-text textarea с «✓ Спросить»). Toggle иконка: ❓ серый/красный/amber/emerald + WhatsApp-style badge с count заданных вопросов. «История уточнений» консолидирована под таблицей в htimeline (grouped batches, blue/emerald dots, qchips) — старый top history block удалён. «Уточнено N/M» / «ждём ответ» chips удалены из status column (информация ушла в toggle icon). Layout image 52→40px in 44px column, `overflow-hidden` снят с card (фикс обрезания dropdown). | Foundation §6.2 + макеты 04b/04c | done (`7947dc5`, `884a2e6`, `0ac8abc`, `53c87e5`, `6fc6a5f`, `c481e71`, `1df6575`, `a4c5d48`) |
| 2.4b | **1×1 photo binding fallback** — если Vision вернул null `image_index` для всех позиций и в письме ровно 1 item без attachment + 1 unused image attachment → автоматически связать (handles trivial case когда LLM не определил соответствие). | bug fix (M-2026-0710) | done (`75d3cd1`) |
| 2.4c | **Vision prompt — brand-specific article patterns** — расширен Vision prompt distinctive product-code patterns: KONE `KM\d+`, OTIS `ZAA/GBA`, Schindler `ID.NR.`, Sigma `DAA/SAS`, Wittur `WL./A-`, Schneider `LAD/LC1/A-`, ЩЛЗ-/ЛП-; explicit «PRODUCT CODE / TYPE / REF» markers. Извлекает не только KM-номера KONE, но и любые артикулы производителей. | enhancement (M-2026-0710) | done (`33e6731`) |
| §reply-parse | **ReplyParseGate + confidence-based suggestion для позиций из reply'ев.** Кейс M-2026-0759: клиент прислал reply «Добрый день!» с одним фото к существующей заявке. ParseRequestItemsJob force=true запустился, Vision на attachment прочёл маркировку чуть-чуть иначе (`CD1-TV12Q` вместо `CD1-TV2 IZQ`) и создал дубликат позиции. **Часть 1 — ReplyParseGate:** перед `ParseRequestItemsJob::dispatch($id, true)` в `MailRouter::route()` проверяем сигналы новой позиции в очищенном теле reply'я (`EmailTextCleanerService::cleanInboundReferenceText` + удаление external-маркеров). Сигналы из `config('services.parser.reply_signals')`: M-SKU `m\d{4,}`, артикул-pattern `[a-z]{2,}-?\d{2,}`, qty `\d+\s*шт`, индикаторы «артикул/прошу/нужно». Ни одного сигнала → skip parsing. **Часть 2 — confidence-based suggestion:** `RequestItemPersister::persist()` для reply-context (`$existing->email_message_id !== $message->id`) считает per-item `final = vision_confidence × (1 - fuzzy_penalty)`, где penalty растёт по Levenshtein-similarity с лучшим existing article. Решение: `>= auto_threshold` (0.95) → активная; `>= suggest_threshold` (0.70) → `suggestion_status='pending'`, `is_active=false`; `< suggest` → skip. Audit state_change `items_parsed_from_reply` с counts. UI Detail: amber-плашка перед AI-suggestions «💡 Парсер увидел в ответе клиента N новых позиций» с per-item «✓ Подтвердить» / «✕ Отклонить» (handlers `applyPositionSuggestion` / `rejectPositionSuggestion` с audit `suggestion_applied/rejected`). Permission owner/acting/privileged. | новое | done |
| §merge | **Слияние заявок-дубликатов** (→ переименовано в «Объединение заявок»). Когда у одного клиента две Request про одно и то же (LZ-REQ-1208: рассылка партнёра на 4 наших адреса создала 4 параллельные Request), РОП/менеджер объединяет loser в winner. **Терминология:** «Объединение» = одна заявка клиента «расплылась» по нескольким письмам, переносим контент. «Дубликат» (как отдельная операция «пометить как дубль без переноса») — пока не реализован, будет отдельной фичей. | новое | done |
| §merge-OLD | **Слияние заявок-дубликатов.** Когда у одного клиента две Request про одно и то же (LZ-REQ-1208: рассылка партнёра на 4 наших адреса создала 4 параллельные Request), РОП/менеджер сливает loser в winner. Migration: `requests.merged_into_id` (FK без constraint, nullable) + `merged_at` (timestamp nullable) + partial index `requests_merged_into_idx`. `ClosedLostReason::Duplicate` добавлен. Новый `RequestMergeService::merge(Request $winner, Request $loser, User $by)` в транзакции: переносит `email_messages.related_request_id`, `ClarificationBatch.request_id`, копирует `RequestItem'ы` с дедупликацией по normalized `(parsed_article + parsed_name)` lowercase (replicate + ++position у winner, original у loser помечается inactive). Audit `state_changes.event = 'merge_from' / 'merged_into'` в обеих заявках. Loser → `ClosedLost` + `closed_lost_reason=duplicate` + `merged_into_id` + `merged_at`. Validate: обе active (не Paused/ClosedWon/ClosedLost/Pending/Paid), одинаковый `client_email` (case-i), $by — owner/acting обеих ИЛИ privileged. `preview(Request, Request)` для UI без записей. UI Detail: `MergeDialog` Livewire-компонент с поиском кандидатов по client_email, подсветкой выбранного, превью stats (items_to_add/skip, emails, batches). Кнопка «⊌ Слить дубликат» в action-panel для active-статусов. Hero chip «↳ слита в M-NNNN» у loser-а; chip «⊌ слитые дубликаты ×N» у winner-а. | новое | done |
| §ext-codes-cli | **CLI-инструменты для исторических данных + trusted partners.** (1) `mail:reassign-by-external-code` — backfill writes для LZ-REQ-маркеров. Первая версия (commit `9202f7c`) имела баг симметричного pingpong'а (для писем A и B с одним LZ-REQ алгоритм находил разных родителей друг для друга). **Багфикс:** pre-pass строит mapping `code → earliest_parent_request_id` ОДИН раз глобально, потом применяет target ко всем письмам с маркером — идемпотентно. **Защиты (default ON):** `--keep-outbound` (не двигать наши отправленные), `--keep-active` (не двигать письма на active Request: in_progress / assigned / quoted / under_review / awaiting_invoice / awaiting_client_clarification / invoiced / paid), `--only-if-newer` (не двигать письмо чей id меньше parent), `--code=LZ-REQ-NNNN` (точечный режим). Audit в `email_messages.detected_artifacts` (`type=manual_reassign_by_external_code, from_request_id, to_request_id, codes, reassigned_at`). (2) `mail:detect-duplicate-requests` — отчёт: ищет случаи где один LZ-REQ привязан к ≥2 Request. Только отчёт — слияние руками. (3) **TrustedPartnerOverride** + config `services.mail.trusted_partners` — pre-classifier перед LLM в `MailCategoryClassifier::categorize()`: для known партнёров (`order@liftway.store` + LZ-REQ маркер) детерминированно ставит `category=client_request`, минуя gpt-4o (категоризатор формально прав «это маркетплейс», но бизнес-факт: client_request). Решает проблему 4 сирот LZ-REQ-1330..1333 где gpt-4o пометил irrelevant. confidence=1.0, reasoning="Trusted partner override: <name>". | новое | done |
| §ext-codes | **Level 3.5 в Mail-linker'ах: маркеры партнёрских систем (LZ-REQ-NNNN).** Диагностика на 199 письмах от `order@liftway.store` показала: ~15% LZ-REQ распределены по разным MyLift-заявкам через Level 4 fallback (`from_email + open Request`) — напоминания партнёра без работающего header threading попадали к случайной open Request клиента; пример M-2026-0537 → 6 утечек с разными LZ-REQ. Также 6 копий одного письма LZ-REQ-1208 (рассылка на разные myzip-адреса) создали 6 отдельных Request. **Фикс:** новый Level 3.5 `matchByExternalCode` в `InboundReplyLinker` и `OutgoingMailLinker` — regex'ы из `config('services.mail.external_codes')` (default `/\bLZ-REQ-\d+\b/u`) ищутся в subject+body, для каждого маркера находится самое раннее `EmailMessage` с тем же маркером и непустым `related_request_id` — это «правильный родитель». Размещён ПЕРЕД Level 4 (from_email), что устраняет утечки. Побочный эффект: дедупликация — повторные копии письма с тем же маркером прицепятся к первой созданной Request, а не плодят дубликаты. **Outbound footer `№ заявки: M-2026-NNNN`** в `OutgoingMailMimeBuilder::composeFinalBody()` — невзрачный block между подписью и цитатой; safety net на случай если клиент удалит `[M-2026-NNNN]` из subject или Outlook потеряет In-Reply-To — код останется в body, regex `\bM-\d{4}-\d{4,}\b` найдёт через `matchBySubjectCode`. Backfill ранее неправильно прицепленных писем — отложен (CLI `mail:reassign-by-external-code` сделаем по запросу). | новое | done |
| §pool-event | **Колонка «Событие» в Pool + auto-silence attention.** Новый enum `RequestActivityType` (18 значений: RequestCreated, Assigned, ClientReplied, SupplierReplied, Resumed, Reanimated, ManualFlagSet — все requiresAttention; ManagerReplied, ClarificationSent, QuoteSent, InvoiceSent, SupplierInquirySent — все silencesAttention; Paid, ClosedWon, ClosedLost, Paused, StatusChange, ManualFlagCleared — нейтральные). Migration: `requests.last_activity_type` (varchar 40 nullable). `RequestActivityService::touch($req, ?RequestActivityType $type, ?Carbon $at)` теперь принимает явный тип и автоматически вызывает `AttentionService::onManagerHandled()` если `$type->silencesAttention()=true` — снимает info-flag (ClientReplied / FreshAssignment / SupplierReplied) когда менеджер передал ход. Hooks обновлены: `IncomingMailProcessor` → RequestCreated, `AssignmentService::autoAssign` → Assigned, `InboundReplyLinker` → ClientReplied (sent_at), `OutgoingMailLinker` → ManagerReplied (sent_at, силенсит ClientReplied), `RequestStateService::transitionTo` мап `to_status` → ClarificationSent / QuoteSent / InvoiceSent / Paid / ClosedWon/Lost / StatusChange, `reanimate` → Reanimated, `RequestPauseService::pauseUntil/resume` → Paused/Resumed, `Detail::toggleManualAttention` → ManualFlagSet/Cleared. Pool blade — новая колонка «событие» 170px между статусом и менеджером, chip icon+label, амбер если requiresAttention(), серый если нейтрально/silences. Новая `AttentionReason::SupplierReplied` + `onSupplierReplied()` — заложено под Phase 3 supplier flow. | новое | done |
| §pool-resort | **Pool re-sort + FreshAssignment + Manual flag.** Сортировка Pool переписана с `attention_required_at ASC` (дедлайн ближе сверху) на `last_activity_at DESC` (свежие сверху — «как в почте»). Migration: `requests.last_activity_at` (timestamp nullable, backfill из `GREATEST(updated_at, created_at)`) + `requests.attention_manual_by_user_id` (без FK constraint) + composite index `(assigned_user_id, attention_level DESC, last_activity_at DESC NULLS LAST)`. Новый `RequestActivityService::touch()` обновляет колонку. Hooks: `RequestStateService::transitionTo/reanimate`, `RequestPauseService::pauseUntil/resume`, `AssignmentService::autoAssign`, `IncomingMailProcessor::processIfRequest`, `InboundReplyLinker::tryLink` (с `message->sent_at`), `OutgoingMailLinker::tryLink` (с `message->sent_at`). Для `RequestItem` — observer `RequestItemObserver` на created/updated/deleted (12+ методов RequestItemEditor закрыты одной точкой). Новые `AttentionReason`: `FreshAssignment` 🆕 (info, ставится `AttentionService::onAssigned()` после auto-assign, снимается `onManagerOpened`) + `Manual` 🚩 (sticky info, ставится менеджером/acting/РОПом через `setManual()`, не затирается recompute/onClientReplied/onManagerOpened, снимается явным `clearManual()` или terminal-переходом через `clear()`). `recompute()` теперь пропускает sticky-reason'ы (Manual / ClientReplied / FreshAssignment). UI: Detail action-panel — кнопка «🚩 Требует внимания» / «🚩 Снять флаг внимания» (amber-стиль когда стоит) для owner/acting/privileged. Pool ORDER BY: `attention_level DESC, last_activity_at DESC NULLS LAST, id DESC` для active/overdue; `last_activity_at DESC, id DESC` для paused/closed/all. | новое | done |
| §sender-guard | **InternalSenderDetector + Empty-content guard + РОП = full request-handler.** Три фикса одной волной. **(1) Internal sender pre-classifier:** новый `app/Services/Mail/InternalSenderDetector` — детектирует from_email внутри организации (домен из `config('services.mail.internal_domains')` default `myzip.ru`, ИЛИ совпадение с `Mailbox::email` любого подключённого ящика, ИЛИ совпадение с `User::email`). Включён в `MailCategoryClassifier::categorize()` как short-circuit ДО LLM и ДО `TrustedPartnerOverride` — детерминированно `category=irrelevant`, confidence=1.0, reasoning=`Internal sender: <domain:myzip.ru|mailbox|user>`. Кейс M-2026-0161: `alexander.rodenkov@myzip.ru` (наш сотрудник) прислал «А вложения и не было )» в общий ящик, gpt-4o пометил `client_request`, создалась пустая заявка. **(2) Empty-content guard:** `IncomingMailProcessor` получил зависимость `EmailTextCleanerService` + проверку `isContentEmpty()` — если 0 attachments И `cleanInboundReferenceText(body) - external_codes` короче `config('services.mail.empty_body_guard_min_chars')` (default 40) → перезаписывает `category=irrelevant` + `category_reasoning='Empty body, no attachments — not actionable (auto-guard)'`, возвращает null. Письмо попадает в `/dashboard/mail-review` где РОП может «↻ Это заявка». **(3) РОП = request-handler:** новый helper `Role::requestHandlerRoles()` возвращает `[manager, head_of_sales]`. Заменён `User::role(RoleEnum::Manager->value)` на `User::role(RoleEnum::requestHandlerRoles())` в: `AssignmentService::autoAssign` (round-robin + sticky), `ManagerUnavailabilityService::delegateActiveRequests` (delegation pool, минус сам недоступный), `UsersApplyPlannedUnavailabilityCommand` (cron планируемой недоступности), `ReassignDialog` (manual reassign list + валидация newAssignee), `Dashboard\Index::managersLoad` (топ-5 нагрузки). `markUnavailable` guard переведён на `hasAnyRole(requestHandlerRoles)`. Существующие Request не трогаем; M-2026-0161 закрывается руками через UI. | новое | done |
| §2026-05-24 | **Большая волна 2026-05-24** — 25+ коммитов по mail/parser/perf/inheritance:<br>**Mail pipeline guards:** `UnintendedRecipientDetector` (BCC чужой переписки → irrelevant без LLM); `Detail::mount` гейт `isOwnedBy \|\| isDelegatedTo` для implicit-transition + `onManagerOpened` (РОП больше не двигает статус и не сбрасывает attention); `AssignmentService` Level 0 sticky `direct_mailbox` (письмо в личный ящик менеджера → owner override round-robin); `reanimate()` пересчёт ответственного (sig A direct_mailbox + sig B archived; unavailable не триггерит); `InboundReplyLinker` — irrelevant блокирует reanimate и Level 4; **Phase 1 inheritance:** auto-реанимация closed_lost ПОЛНОСТЬЮ выключена; **Phase 2.1 наследование:** `requests.inheritance_{group_id,role,parent_id}`, `request_item_links` таблица, `RequestInheritanceService` (suggestLinks по article+similar_text/brand-match, linkChild/unlinkChild), `InheritanceCandidateChecker` (gpt-4o-mini LLM-проверка), `CheckInheritanceJob` (async после ParseRequestItemsJob), `CheckInheritanceCandidatePrompt`, Hero чип «↻ наследник M-NNNN». Level 4-bis в linker'е: Guard A (mass-closed без `closed_lost_source_message_id` блокируется); Guard B (subject mismatch) изначально был, потом снят — LLM check сам решает по позициям. **Phase 2.2 UI наследования:** per-item чип «↻ было в M-NNNN · поз. N · qty X» (qty подсвечивается amber если изменилось), кнопка «🔗 Отвязать наследование» в action-panel. **Phase 2.3 manual reanimate:** кнопка «↻ Реанимировать» в closed_lost карточке (owner/acting/privileged, менеджер сохраняется, `reassessAssignee=false`, `event=manual_reanimate`). **Attachment 500 trinity:** `AttachmentController::sanitizeForDisposition` — все control bytes \x00-\x1F+\x7F; `asciiFallback` — экранировать `%`, `"`, `'` для RFC 6266; `MessagePersister::resolveRawFilename` (raw header в обход Webklex sanitizeName который стрипает `/` из base64 = ломал decode) + `repairCorruptedBase64` (вставляет недостающий `/` или `+`, выбирает кандидата с max кириллицей). `mail:rebuild-attachment-names` CLI (regex-парсинг raw_source, не Webklex который теряет MIME-парты, group by mime+index, looksBroken защита). **Mail flow дополнения:** `IncomingMailProcessor` — thread_reply без linker-match создаёт Request (раньше «висел»); `InternalSenderDetector` allowlist для `order@myzip.ru` (web-form ящик сайта). **Parser hardening:** `ParseItemsPrompt` правила про услуги (5.5 + критическое правило в самом верху); тело письма парсится ВСЕГДА (раньше skip при наличии attachment items); код-фильтр `RequestItemParsingService::isServiceItem` (regex prefix-patterns доставка/монтаж/упаковка/страхование/сертификация/работы; negative exclusion для «комплект/материал»); `recomputePossibleDuplicates` после persist + chip «⚠ возможно дубль #N (XX%)» в _position-card. **Perf detail page:** профайлер temp (мерил render 46-85ms — сервер быстрый); **nginx gzip для application/json** (Livewire-payload сжимается 5-10×); **lazy на 10+ dialog-компонентов** (reassign×3, merge, pause, postpone, close-lost, issue-invoice, match-request-item, item-edit, item-catalog-link, item-photo-rebind) — 252 КБ→7-18 КБ, 6.38с→169-529мс. **Catalog resolver:** `ResolvePendingFromCatalogJob` теперь chunk-dispatcher (chunkById 100→ChunkJob), `ResolvePendingChunkJob` (timeout=300, tries=1, chunk=50, обрабатывает ~70с/50items=1.4с/item). Failed jobs очищены. **Инфра:** RAM 1.9→3.8 GB (Beget upgrade), swap 2 GB (`vm.swappiness=10`), supervisor `--memory=600 --max-jobs=200 numprocs=4 stopwaitsecs=60` + лог-ротация 50 МБ × 5. | новое | done |
| §empty-pending-assign | **Auto-assignment для empty-items pending заявок в личных ящиках.** Кейс M-2026-1880: Виктория Романова (давний контакт Курзаева, romanova@liftremont.ru) пишет напрямую в его личный ящик ilya.kurzaev@myzip.ru, subject=«775» (внутренний таск-номер у клиента), body=«Илья, обнови КП №352836». Парсеру нечего извлечь. Раньше AssignmentService::autoAssign вызывался только из `RequestItemPersister.persist`, который не запускается при items=0 → Request оседал status=pending+assigned_user_id=null, менеджеру не виден (Pending скрыт от его UI). Курзаев уже ответил клиенту через Yandex web UI, но MyLift про заявку «не знал». Теперь `ParseRequestItemsJob::assignIfStuckPending` (после `tryAdoptFromInheritanceCandidate` если parent нет): вызывает `AssignmentService::autoAssign` — Level 0 sticky `direct_mailbox` для personal mailbox автоматически назначит owner, для shared — sticky-по-client_email или round-robin. Если уже assigned — просто двигаем pending→assigned. Plus MailFolderRouter::routeToManager для shared-mailbox случая. Backfill: 7 stuck заявок (1880, 1888, 1873, 1863, 1861, 1857, 1841) разобраны bulk dispatch'ем — все assigned правильным менеджерам. | bug fix | done (`8a8a86e`) |
| §adopt-from-parent | **Inheritance fallback для empty-items reminder'ов.** Liftway-партнёр шлёт `Re: Заявка на … — #LZ-REQ-NNNN` через 22 дня. Linker находит In-Reply-To → parent closed_lost → defer (auto-реанимация выключена), `IncomingMailProcessor` создаёт child Request, парсер правильно возвращает `items=[]` (промпт Пример 8: reply-напоминание не дублирует позиции). Раньше child висел в pending без позиций. Теперь: `RequestInheritanceService::adoptFromParent($child, $parent)` клонирует активные RequestItem'ы из parent (parsed_*, KB-резолв, catalog_item_id; image_attachment_id НЕ копируем — вложение принадлежит parent) + создаёт 1:1 `RequestItemLink`'и (`mapping_source=adopt_from_parent`) + ставит inheritance_group_id/role/parent_id. `data_source='inherited_from_parent'` для аудита. `ParseRequestItemsJob::tryAdoptFromInheritanceCandidate` вызывает adopt + AssignmentService::autoAssign (sticky обычно подтянет parent's manager) + MailFolderRouter::routeToManager + явный pending→assigned flip для случаев когда assigned_user_id уже стоял до adopt. 12 «висячих» reminder'ов от order@liftway.store за неделю разобраны bulk-dispatch'ем — все получили позиции + менеджера + правильный статус. | bug fix | done (`0e5bb55`, `feea160`) |
| §doc-support | **Поддержка legacy .doc вложений (binary OLE V2).** Парсер позиций знал только pdf/docx/xlsx/xls; `.doc` (MS Word ≤ 2003) тихо скипался фильтром `structuredAttachments` (`/\.(pdf\|docx\|xlsx\|xls)$/i`) → заявка терялась в 0 позиций при наличии содержательного `.doc`-вложения. Кейс M-2026-1805 (klinika «Морозовская ДГКБ» KudryavtsevaEM@zdrav.mos.ru, файл «Запрос КП запчасти.doc» 502kb с 6 позициями: частотник Macpuarsa MP, контроллеры REVECO/WITTUR, канат DRAKO, ограничитель скорости E902EMXX и т.п.) — `↻ Перезапустить парсер` не помогал. Новый `extractFromDoc` через `antiword -t -w 200` (primary, специализирован под .doc) + `catdoc -d utf-8 -w` (fallback). Оба бинарника уже стоят на VPS, никаких apt install. Regex расширен `\|doc`. **Грабли деплоя:** worker-ы держат код в opcache → после правки сервисов **обязательно** `php artisan queue:restart`, иначе первый прогон job'а отрабатывает старым кодом. Это уже не первый раз — стоит добавить в чек-лист деплоя. | bug fix | done (`3f4a1e4`) |
| §bcc-blast-fix | **Два детектора ложно резали легитимные заявки → irrelevant.** (1) `UnintendedRecipientDetector::looksLikeBccBlast` смотрел to+cc одной кучей. Если клиент отправлял BCC-рассылку (`to: <undisclosed-recipients:;>`) и держал коллегу в CC для info (`cc: av@stein.ru` — коллега того же домена), один реальный email в CC закрывал паттерн (а) и письмо помечалось `Unintended recipient: not_in_to_cc + unknown_thread`. **Фикс:** паттерн (а) теперь смотрит ТОЛЬКО в `to_recipients` (CC может содержать что угодно — реальные получатели в BCC); паттерн (б) «from в to/cc» не тронут. (2) `IncomingMailProcessor::isContentEmpty` проверял длину только в body. Кейс M-2026-1815/1816: клиент Сабиров пишет subject=`MAA250AY301 1 штука` / `GAA737AA1 6 штук` + пустой body → guard срезал как «не actionable». **Фикс:** регексы (`M\d{4,}` + новый общий артикул `[A-Z]{1,4}\d{2,}[A-Z0-9]*`) проверяются на `subject + body`. Cyrillic-only subject не триггерит (ASCII-only паттерн). Плюс новый CLI `mail:requalify-irrelevant --days=N --dry-run`: перепрогоняет MailRouter для писем с `category=irrelevant + reasoning LIKE 'Unintended recipient:%|Empty body, no%' + related_request_id IS NULL` за N дней. На первом проходе 26.05 спасены: Request 1827 (at@stein.ru OPTAMID 4 шт → sticky к Васюхно), Request 1828 (mriyaresort `26152.Запрос КП`), thread-link к Request 1761. 2 кейса корректно остались irrelevant (рекламная рассылка dkt-privod + Re[2] без контента). | bug fix | done (`4f93fb4`) |
| §pool-source | **Источник заявки в Pool-таблице.** Под `client_email` в строке Pool — третья моно-строка `← info@` / `← mail@` / `← личный` (локальная часть email общего ящика для shared, текст «личный» для personal). Tooltip раскрывает полный mailbox.email + owner для personal. Eager-load: `Request.emailMessage.mailbox + mailbox.owner`. Foundation §1 разделяет потоки общий vs личный (sticky Level 0 direct_mailbox), теперь это видно в UI без открытия карточки. | новое | done (`bdee57c`) |
| §dashboard-period | **Расширенные периоды в Dashboard.** Верхний switcher: чипы `1 дн.` (сегодня от 00:00 МСК) / `7 / 30 / 90 / период…` + inline date picker для custom. URL: `?period=1\|7\|30\|90` или `?from=&to=`. `periodStart()` → `periodRange(): [from, to]`, `funnel()` и `inflowHeatmap()` на `whereBetween`. Карточка менеджеров разделена на две: **«Менеджеры · нагрузка сейчас»** (снимок, без period: активные / слжн / hard / всего ист. / info@) + **«Менеджеры · назначено · {period}»** (4 чипа: Текущая загрузка / Сегодня / Вчера / Период…). Два новых computed: `managersCurrentLoad()` и `managersAssignedInPeriod()` (последний JOIN на email_messages.mailbox_id для info@ в период). Sparkline render для 1 точки = центрированный bullet с baseline. **Урок:** preset 1/7/30/90 ≠ rolling — для 1 дня нужно `startOfDay()` (календарный), не `subDays(1)`. | новое | done (`5ffd2bb`, `3f4acb8`, `f90fc76`) |
| §outbound-declined | **Auto-распознавание отказа «не наша номенклатура» в исходящих.** Кейс M-2026-1860, 1866: Курзаев отвечает клиенту «Не наша номенклатура.» / «Не предложим, не наша номенклатура.» через Yandex web UI. До фикса LLM пометил это как `outbound_clarification` → AwaitingClientClarification (неверно: это отказ, не уточнение). Менеджер закрывал руками. Новая `DetectorType::OutboundDeclined` → `ClosedLost / off_topic`. Два пути: (1) rule-based `OutboundDocumentDetector` — 16 фраз DECLINE_KEYWORDS + 13 anti-followup KEYWORDS («но я попробую», «пришлите фото», «предложить аналог» → НЕ срабатывает); cited_phrase = строка body с keyword'ом → закидывается в `closed_lost_quote`. (2) LLM-fallback `ClassifyOutboundDocumentPrompt` расширен с 4 до 5 категорий, добавлен `declined` с явным правилом «явный отказ без follow-up», возвращает `suggested_closed_lost_reason + cited_phrase`. MailRouter передаёт оба поля в `recordSuggestion` payload — `AiDecisionService::apply` читает их при ClosedLost-transition. Settings: новый toggle `detector.auto_mode.outbound_declined` (default OFF). **Доп. фикс (c984c9e):** `OutboundDocumentDetector::buildSearchableText` теперь чистит quoted-body через `EmailTextCleanerService::cleanInboundReferenceText` ДО keyword match — иначе keyword «коммерческое предложение» из цитируемого письма Liftway конфликтовал с decline-фразой в ответе менеджера (priority quotation > declined → ложный outbound_quotation_full). | новое | done (`285b86b`, `c984c9e`) |
| §reply-deliver-route | **Reply'и к existing Request теперь маршрутизируются менеджеру.** Кейс M-2026-1928 (pto@trastlift.ru → Васюхно): первое письмо клиента создавало Request через AssignmentService → routing+delivery срабатывали. Последующие reply'и (выставите счёт / Fwd: счёт) висели в INBOX info@ без копии в личный inbox Васюхно. Причина: `MailRouter::route` inbound branch при reply→existing Request НЕ диспатчил `RouteMailToManagerJob` и `DeliverToManagerInboxJob` — они срабатывали только из AssignmentService и из success-branch ParseRequestItemsJob (только если парсер нашёл новые позиции). Для типичных reply'ев empty-items → routing/delivery не происходили. **Фикс:** в MailRouter inbound branch когда `linkedRequest != null && assigned_user_id != null` — диспатчим **сначала Deliver, потом Route** (важный порядок: Route MOVE'нёт письмо из INBOX в MZ\|*, после этого `fetchFullRfc822` для Deliver часто падает с Yandex re-fetch failed → `cannot reconstruct RFC822, skip APPEND` без throw → нет retry'я). Backfill 5 застрявших reply'ев за 3 дня сделан вручную (msg#4712, 4806, 4835, 4970, 3014). | bug fix | done (`19e2957`, `4b726d9`) |
| §outlook-table-parse | **HTML-таблицы Outlook больше не разламываются на колонки.** Кейс M-2026-1961 (Метеор Москва, LSlobodyan@meteor.ru): заявка из Outlook с HTML-таблицей 3×2 (артикул \| описание \| qty), 2 позиции. `body_plain` (multipart alternative) — flatten'нутая по столбцам версия с пустыми строками-разделителями: «ZAA608T1\n\nДатчик LS\n\n15\n\nDAA26800GW1\n\n...». Парсер использовал body_plain (непустой, не «broken» по CSS-эвристике), LLM видел 6 блоков → делил на 4 позиции (`art=ZAA608T1 qty=1`, `art=ВЛК-РМ154 qty=15`, ...). **Фикс 1:** `EmailTextCleanerService::htmlToText` pre-process'ит каждый `<table>` regex-callback'ом ДО `strip_tags`. Cell content собирается в одну строку (без internal newlines от `<p>`), row → `cell1 \| cell2 \| cell3` одной строкой. **Фикс 2:** новый helper `htmlHasStructuredTable($html)` — детектит «настоящую» таблицу (>=2 строки <tr>, >=2 cells per row; layout-wrapper'ы Outlook не подходят). `RequestItemParsingService` теперь предпочитает htmlToText когда есть structured table, даже если body_plain непустой. После фикса LLM получает `ZAA608T1 \| Датчик LS… \| 15 / DAA26800GW1 \| Плата RS A3N200571 \| 4` и распарсивает в 2 позиции. | bug fix | done (`d5d644e`) |
| §docs-screenshots | **Авто-захват скриншотов реального UI для документации.** `scripts/capture-docs-screenshots.mjs` — Node.js + puppeteer-core. Логинится через /login, ходит по списку URL, сохраняет PNG в `public/docs/screenshots/`. На VPS установлен **Google Chrome stable** (`/usr/bin/google-chrome`) вместо snap chromium (snap требует пользовательской сессии, не работает под www-data). `puppeteer-core` как devDependency. Спец-пользователь **docs-bot@myzip.ru** (id 19) для логина — пароль `5de76cfa497628b9e3b2b3ffffad1e78`. **Полное лекарство** против попадания docs-bot в распределение: `archived_at = now()` (исключает из `scopeActive` → Dashboard / Pool sidebar / Admin Managers) + `unavailable_until = 2099-12-31` (исключает из `scopeAvailable` → AssignmentService). Login через `Auth::attempt` не проверяет ни то ни другое. MVP: 4 скрина — requests-pool, request-detail (M-2026-1928), request-positions, dashboard. CSS-класс `.doc-screenshot` с подписью data-label::before и click-to-zoom через `<a target=_blank>`. Запуск: `DOCS_USER_PASSWORD=... CHROMIUM_PATH=/usr/bin/google-chrome node scripts/capture-docs-screenshots.mjs`. Iframe-подход через `design/ui_kits/crm/*.html` мокапы был отвергнут — они wireframe'ы, отличаются от реального CRM. | новое | done (`d15a7eb`, `99e1d7f`, `494294a`, `b1e6b64`) |
| §docs-manager-workflow | **Пошаговый алгоритм работы менеджера в /docs/manager.** Раньше manager-доки описывали только интерфейс (navigation/pool/request-lifecycle). Новый `resources/docs/manager/workflow.md` (order: 5, верхняя позиция в сайдбаре) — single-page «Алгоритм работы»: 11 пунктов «что система делает сама», утренний обход пула, 7 шагов жизненного цикла одной заявки (Новая → Закрыта), 4 параллельных сценария (пауза / отпуск / делегация / реанимация), «чего не нужно делать», шпаргалка статусов. Гибрид narrative+checklist. **Уточнено по реальному UX:** Шаг 3 «Уточнили у клиента» — per-item `❓` иконка раскрывает панель с KB-слотами (бренд/серия/размер/мощность для категории), `+` рядом со слотом формирует вопрос автоматически, free-text + «✓ Спросить» для нестандартных. Иконка ❓ цвет+badge с count'ом вопросов. Кнопка «📨 Сформировать письмо (N)» над таблицей — готовый черновик в Переписке. Статусы позиций «🔍 Искать в каталоге» (internal_catalog_pending) и «❓ Нет в каталоге» (internal_catalog_not_found) разнесены: первое — действие требуется, второе — финальный статус. | новое | done (`c7eede2`, `1ed8ceb`, `97b0c4a`) |
| §F1.5-linker | **Foundation §1.5 «личный ящик X → Request у X» расширен на reply'и.** Раньше sticky direct_mailbox работало только в `AssignmentService::pickStickyManager` Level 0 при создании новой Request. Для reply'ев, привязанных linker'ом к существующей Request с другим assigned, правило игнорировалось — assigned оставался прежним, мы APPEND'или копию (commit 19e2957). Кейс M-2026-1651/msg#5298 (greenliftsnab → Курзаев → APPEND копии Головневу). Три слоя защиты: (А) Guard в `MailDeliverToManagerService::deliver` — origin Personal + owner≠target → skip + audit `origin_in_another_personal` (commit `0993e83`); (Б) `MailRouter::applyStickyDirectMailboxOnReply` после linker — если origin Personal, owner active+available, текущий assigned≠owner → reassign на owner через ReassignService (с null actor + reason='sticky_direct_mailbox_on_reply'), commit `c317c52`; (В) `InboundReplyLinker.tryLink` scope-фильтр для personal-mailbox — все matched Request'ы должны быть из пула менеджера-владельца ящика (текущий или историч. assigned через request_assignments). Применён к Level 1 (in_reply_to/references — happy path + terminal-parent) и финально перед возвратом. Если ни один уровень не нашёл Request в scope → linker возвращает null → IncomingMailProcessor создаёт новую Request у этого менеджера. Commit `efd5206`. ReassignService::reassign теперь принимает nullable $by (для system-actor), reason prefix 'manual_reassign'/'system_reassign'. | новое | done (`0993e83`, `c317c52`, `efd5206`) |
| Phase 6 | **Автоматические уведомления клиенту.** 6 типов: OrderReceived (sync hook AssignmentService, только новые с нуля заявки), ClarificationReminder / QuoteFollowupReminder / InvoiceExpiringSoon / InvoiceExpired (cron `notifications:dispatch-client` hourly), OrderClosedLost (sync hook RequestStateService::transitionTo при ClosedLost, guard: skip если detector_type=outbound_declined — менеджер уже написал отказ). Архитектура: Enum ClientNotificationType + 2 таблицы (`client_notification_templates` row per type + `client_notifications_sent` uniq(request_id, type, scope_key) для идемпотентности). ClientNotificationService: Markdown via league/commonmark → wrap в `resources/views/emails/notification_wrap.blade.php` (брендинг MyZip) → reply в тред заявки через EmailDraftService::createReply + OutgoingMailSender. Sender — ящик origin-письма. Conditional placeholder `manager_intro`: для shared (info@) — «Ответственный менеджер: Имя (email).», для personal — пустая строка. UI `/dashboard/settings` → подпункт «Уведомления клиенту» (НЕ в топ-меню) с toggle + edit (Markdown + preview через iframe srcdoc на реальной заявке) + список placeholder'ов. Permission head_of_sales/director/admin. Cron hourly. Все toggle'ы по умолчанию выключены — admin включает после ревью. Seeder `firstOrCreate` — не перетирает admin-правки. | новое | done (`48157bf`, `f01ee47`, `7e64d33`, `78faec4`, `7bf456e`) |
| §parser-doc | **Unified Vision-call расширен на .doc/.docx + cross-article dedupe в split.** Кейс M-2026-1953/msg#5469 «Цикин Василий, плата VQC»: фото + .doc вложение → парсер шёл split path (structured_count=1), photo-vision видел внутренний код шильдика OA8522.5A/3874W7/81, text-parser извлекал M22198 из .doc → 2 позиции вместо одной. Фикс: structuredAttachments разделены на lightweight (.doc/.docx — текстовый контент) и heavy (.pdf/.xls/.xlsx — tabular). Heavy блокируют unified path, lightweight extract'ятся через extractTextFromFile и подмешиваются в body перед parseItemsUnified. Параллельно crossArticleDedupe (второй проход после dedupeWithinList): если 2 позиции совпали по (name_lower + qty + invoice_index), одна имеет M-SKU паттерн `M\d{4,}` (с поддержкой кириллической М через str_replace), а другая нет → merge non-M-SKU в M-SKU вариант (catalog-matchable). | bug fix | done (`f41f022`) |
| §decline-fix | **Differentiate decline vs correction + auto-reanimate при outbound на closed_lost.** Кейс M-2026-2102: клиент ответил «Такая не подходит, у нас общая длина примерно 928мм, а ваша 1128» — корректировка спецификации, не отказ. LLM-классификатор (gpt-4o-mini в InboundIntentClassifier) пометил как `inbound_decline` conf=0.9 → auto-applied → ClosedLost. Менеджер прислал новое КП → AiDecision outbound_quotation_full на closed_lost → state machine отказала → Dismissed → заявка осталась закрытой. (1) `ClassifyClientResponsePrompt` расширен раздел «ЭТО НЕ decline»: технический mismatch + клиент даёт свои параметры, запрос альтернативы, указание на ошибку в КП → intent=unclear. Правило отличия: «клиент даёт нам ШАНС → negotiation/clarification; декларативный отказ без альтернативы → decline». (2) `AiDecisionService::apply` auto-reanimate если Request в ClosedLost + outbound (quotation/invoice/clarification, НЕ outbound_declined): перед transitionTo вызывает `stateService->reanimate(reassessAssignee=false, event='auto_reanimate_for_outbound')`. Менеджер сохраняется. (3) Восстановил M-2026-2102 руками: closed_lost → InProgress → Quoted. | bug fix | done (`4ee37da`) |
| §circuit-breaker-recovery | **Mail recovery после OpenAI circuit breaker.** OpenAI 503 → MailCategoryClassifier circuit breaker open 30 мин → sync письма категоризуются как null → IncomingMailProcessor гейтится по `category===ClientRequest` → Request не создаётся. Фоновый cron `mail:categorize` (отдельный job) дозаполняет category позже, но НЕ запускает IncomingMailProcessor → 7 заявок 27.05 повисли как orphan. Фикс: MailCategorizeCommand::processBulk после категоризации, если client_request/thread_reply + related_request_id IS NULL → InboundReplyLinker::tryLink, иначе IncomingMailProcessor::processIfRequest. Новый флаг `--include-orphans` для bulk-query: захватывает уже-категоризованные orphan'ы для backfill. Scheduler: `mail:categorize --all --limit=50 --include-orphans` каждые 5 минут. Восстановлены руками 7 заявок: M-2026-2189..2195. | bug fix | done (`c62b72c`) |
| §paid-to-won | **Auto-close Paid → ClosedWon.** Кейс M-2026-1496: статус Paid, менеджеры воспринимают «оплачено» = «успех», но в state machine это разные статусы → заявки висят в Paid вечно. Hook в RequestStateService::transitionTo: после успешного перехода в Paid сразу вызывает transitionTo(ClosedWon, event='auto_close_won_after_paid'). Guard от рекурсии: `$to === Paid && $request->status === Paid`. Audit-trail цепочка Invoiced→Paid→ClosedWon в request_state_changes. Грабли: `request_state_changes.event` varchar(32) — длинные snapshot-имена (например `manual_backfill_paid_to_closed_won` = 33 символа) падают на INSERT. | новое | done (`10796f2`) |
| §mail-class | **Удаление 2-го уровня AI-классификации почты.** Mail-review показал систему: 43% (20 из 46 за 7 дн.) `accounting`-писем без Request на самом деле помечены Level-1 категоризатором (gpt-4o, EmailCategory) как `client_request` — но Level-2 классификатор (gpt-4o-mini, EmailClassification) их переписывал в `accounting` (слово «счёт» в теме). `IncomingMailProcessor` гейтил создание Request по Level-2 → 20 заявок в неделю терялись. На проде боевых `MailRoutingRule` ноль, regex-режим вообще не использовался. Удалены: `MailClassifierService`, `ClassifyIncomingPrompt`, `MailClassifyCommand`, enum `EmailClassification`, `MailRuleMatchMode::AiClassified` case, AI-fallback ветка в `MailRouter::route()`. `IncomingMailProcessor::processIfRequest()` guard переведён на `category === EmailCategory::ClientRequest`. `RoutedMail.ai_classified_as` теперь пишется значением `category` (колонка БД оставлена, переинтерпретирована). `MailReview/Index` упрощён: фильтр `category=irrelevant AND categorized_at IS NOT NULL`, удалены classifications-chip фильтры/counters, добавлен column «Причина AI» с `category_reasoning`. Dashboard: `aiBreakdown`/`aiCoverage` → `categoryBreakdown`/`categoryCoverage` по `categorized_at`. `MailCreateRequestsCommand` и `MailExportForAnalysisCommand` переведены на `category`. UI `RuleEditor` — режим «AI-классификация» удалён (только any_of / all_of). Колонки `email_messages.ai_classification`, `ai_classification_confidence`, `classified_at` оставлены в БД (исторические данные не теряем, миграции нет). 20 ложно-отклонённых писем команда пройдёт вручную через mail-review «↻ Это заявка». | новое | done |

| §2026-06-11 | **Реальный срок действия счёта из письма/документа (фикс преждевременных напоминаний).** Кейс M-2026-3307: счёт №5903 с резервом «до 16.06» получал авто-напоминание «счёт истекает 11.06 (через 0 дн.)». Причина: `expires_at` ВСЕГДА вычислялся как `document_date + 5 раб. дней` (`services.invoices.default_validity_business_days`), а реальный срок резерва/«действителен до» из счёта/письма нигде не извлекался и не хранился (ни в `Invoice`, ни в `OutboundQuote`, ни в промпте парсера). **Фикс:** миграция `outbound_quotes.valid_until` (date, nullable). Парсер `OutboundQuoteParsingService` теперь (а) получает тело письма (`ParseOutboundQuoteJob::extractEmailBody` — `body_plain`, иначе грубый `strip_tags(body_html)`) как ВТОРОЙ источник (срок резерва часто в теле, а не в счёте) и (б) извлекает `document.valid_until` — ТОЛЬКО явную календарную дату («счёт действителен до / срок действия счёта / оплатить до / резерв до / срок резерва / позиции зарезервированы до / предложение действительно до»); период «N дней» без даты → null (считает система). `ParseOutboundQuoteJob` пишет `valid_until` с guard'ом формата `^\d{4}-\d{2}-\d{2}$` (защита date-cast от мусора). **`InvoiceService::computeExpiry()`** (static, pure, unit-tested): `valid_until ≥ issued` → `expires_at` = конец того дня + `validity_days` = раб. дни между датами; иначе fallback `+5 раб. дней` (по решению пользователя — молча). Подключена в `autoIssueFromOutboundQuote` + `refreshFromNewerQuote`. Новый публичный `applyValidityFromQuote()` (Pending-only, **в обход** `isNewerSource` — тот же источник) для backfill. CLI `invoices:backfill-validity` (2 шага: `--reparse --apply` dispatch'ит `ParseOutboundQuoteJob(force)` Vision-переразбор для заполнения `valid_until`; затем `--apply` применяет к счёту; `--request=`/`--limit=`, dry-run по умолчанию). Unit `InvoiceServiceExpiryTest` (4 кейса; весь Unit-suite 75 passed). Рассылку `notifications:dispatch-client` и `invoices:check-expiry` НЕ трогали — читают `expires_at`, который теперь корректен. **M-2026-3307 вылечен вручную:** reparse извлёк `valid_until=2026-06-16` из счёта/письма от 8 июня → `expires_at` 11.06→16.06. **Решение:** остальные ~170 pending исторических счетов НЕ бэкфиллим (новые фиксятся автоматически; domain-факт от пользователя — «срок действия в счёте указывается всегда», поэтому пустой `valid_until` после reparse = сигнал «парсер не дочитал», а не «срока нет»). **Грабли деплоя (повтор регламента §2026-06-03(5)):** каталог `tests/Unit/Services/Invoices` был во владении **root** (прошлый scp), `git pull` под www-data упал на создании нового тест-файла, применившись частично (HEAD не сдвинулся, 6 файлов осели как «local changes»). Лечение: `chown -R www-data:www-data tests/Unit/Services/Invoices` → `git checkout -- <4 модиф.>` + `rm <2 новых>` → чистый `pull` → `composer dump-autoload --optimize` (новые классы) → `migrate --force` → `config/route/view:cache` → `queue:restart`. | feat + bug fix | done (`d973a9d`) |

| §2026-06-03 | **Волна 2026-06-03 — авто-закрытие по молчанию / массовая оплата / разъединение заявки / oklch / инфра.** **(1) Авто-закрытие заявок по бездействию клиента (главное):** новая команда `requests:auto-close-inactive {--dry-run}{--limit}` (3 правила) + крон `dailyAt('08:00')` Europe/Moscow. Пороги в админке `/dashboard/settings` (группа «Авто-закрытие по молчанию»), календарные дни, **0=выкл**: `auto_close.clarification_days` (4), `auto_close.quote_days` (5), `auto_close.invoice_days` (5, отсчёт **от issued_at**). Правила: уточнение sent+`answered_at IS NULL`+sent_at старше N → `no_client_response_to_clarification`; статус Quoted + `COALESCE(last_activity_at,updated_at)` старше N → `no_client_response_to_quote`; счёт Pending/Expired+`paid_at IS NULL`+issued_at старше N И у заявки нет Paid-счёта → `invoice_unpaid`. Закрытие через `RequestStateService::systemCloseLost` (идемпотентно, аудит `system_close_lost`, attention снят), скип Paused/PostponedUntil/terminal. После закрытия best-effort `ClientNotificationService::sendOrderClosedLost` — по умолчанию шаблон OrderClosedLost выключен → писем нет. Восстановление — существующей ручной «↻ Реанимировать» (отдельный recovery-пул НЕ делали; пул `parser_no_content` не трогали). **Грабли/риск:** первый прогон закрывает накопленный backlog СКОПОМ — сперва `--dry-run`. **Расчистка backlog: разово закрыто 215 заявок (0 уточнение / 116 КП / 99 счетов); повторный dry-run = 0.** **(2) Массовая оплата счетов** (`1820bc6`): кнопка «✓ Массовая оплата» на `/dashboard/invoices` → вставка списка номеров → превью (категоризация к оплате/пропуск/не найдено, регистронезависимо, менеджер видит только свои) → `confirmBulk` → `InvoiceService::bulkMarkPaid`. `markPaid` расширен на **Expired** (оплата с опозданием), новый const `PAYABLE_STATUSES=[Pending,Expired]`. Доступ как у одиночной кнопки (privileged — любые, менеджер — свои). **(3) Ручное разъединение заявки (split/un-merge)** (`0e0aff3`,`40acaf5`,`87ba62e`): когда linker склеил два разных потока клиента в одну заявку (старый баг до scope-гарда `efd5206` 27.05; пример M-2026-1752 — актуатор Ильи #3933 + КВШ/Коуш/Канат Дмитрия #4355). Провенанс позиция→письмо: новая колонка `request_items.source_email_message_id` (заполняет `RequestItemPersister`; бэкфилл-команда `requests:backfill-item-source-email` по времени, исключая cross-mailbox копии — расставлено 6473 позиции). `RequestSplitService` (обратный merge: переносит письма+позиции+КП+clarification-batches, аудит `split_into`/`split_from`, назначение auto/manager). `SplitDialog` (lazy, фикс-шапка/подвал+скролл) + бейдж «источник N поз.» в треде. Доступ admin/director/head_of_sales. **(4) UI/CSS:** managers-сайдбар выровнен под общий паттерн секций; **диагностика «у менеджера всё ч/б» = старый Chrome без поддержки `oklch()`** (вся палитра `design-tokens.css` на oklch, выживает только hex `--red-600`/`--accent`); добавлены недостающие ступени `--amber-100/300/500/800`, `--sky-300` (бесцветные бейджи у всех ролей) (`f4f45ed`). **(5) Инфра-грабли (важно для деплоя):** 97 файлов в `app/`+`resources/` (и `routes/console.php`) оказались во владении **root** — следствие прошлых аварийных scp-деплоев под root. `sudo -u www-data git pull` падал `error: unable to unlink old '...': Permission denied` и зависал в полуприменённом состоянии (HEAD не двигался, рабочее дерево грязное). Лечение: `chown www-data:www-data` всех аномальных файлов (приложение и так работает/деплоится под www-data) → `git checkout -- <полуприменённый>` → повторный pull. **Регламент: после scp под root ВСЕГДА `chown www-data:www-data <файлы>`, иначе следующий pull под www-data ломается.** | feat + bug fix | done (`f4f45ed`, `0e0aff3`, `40acaf5`, `87ba62e`, `1820bc6`, `820aaab`) |

| §2026-06-03-b | **Волна 2026-06-03 (2) — харднинг авто-закрытия + расширение vs новая заявка.** Триггер: авто-закрытие (волна выше) ошибочно закрыло **M-2026-2538** — клиент по той же заявке запросил вторую позицию и общий счёт, но правило `invoice_unpaid` смотрело только на `issued_at`. **(1) Харднинг авто-закрытия** (`500973a`): пороги теперь в **РАБОЧИХ днях** (`RussianWorkingDayService::addBusinessDays`, не календарных) — дешёвый календарный пред-фильтр `subDays(N)` (superset) + точный раб.-дневной дедлайн `now >= addBusinessDays(anchor, N)` в PHP; **гард активности** — заявка НЕ закрывается, если клиент слал входящее письмо (без cross-mailbox копий) в последние N раб. дней (`clientEngagedRecently`); настройки переименованы «раб. дней». M-2026-2538 реанимирована вручную (`reanimate`, менеджер сохранён). Dry-run после фикса: 1 кандидат вместо 215. **Точные статусы-мишени (подтверждено):** уточнение → только `AwaitingClientClarification`; КП → только `Quoted`; счёт → `Invoiced` **И** `AwaitingInvoice` (решение пользователя — оставить оба, ловит «счёт истёк неоплаченным → вернулись в Ждём счёт»). **`InProgress` («В работе») не закрывается ни одним правилом** — расширение специально переводит туда, чтобы вывести из-под авто-закрытия. **(2) Расширение заявки vs новая заявка в том же треде** (`231d02a`, выбор пользователя — LLM): когда клиент в ответе по заявке с КП/счётом присылает НОВЫЕ позиции — `InboundIntentClassifier` (+ `ClassifyClientResponsePrompt`, теперь получает позиции заявки в контексте) различает 2 новых intent'а: `additional_items` → `DetectorType::InboundExtension` (→ InProgress, suggestion/auto по `detector.auto_mode.inbound_extension`, по умолч. подсказка «➕ Клиент: добавил позиции») и `new_request` → спец-сигнал (type=null, payload.intent). `MailRouter` inbound-ветка переупорядочена: классифицируем ОДИН раз ДО `ParseRequestItemsJob`; при `new_request` (confidence ≥ 0.8 + есть сигналы позиций) `RequestExtensionService::spinOffNewRequest` отвязывает письмо (related_request_id=null, category=client_request) и гоняет штатный `IncomingMailProcessor` → отдельная заявка с авто-назначением, старая не трогается; аудит `created_from_thread_reply`. Обе ошибки LLM обратимы: merge (склеить назад) / split (разъединить). Иконка `inbound_extension`→'➕' в AI-плашке Detail (match с default-фоллбеком — не падает). **Грабли:** `ParseRequestItemsJob` диспатчился ДО классификатора — для `new_request` важно НЕ отправить parse в старую заявку, поэтому классификацию перенесли ПЕРЕД dispatch. | feat + bug fix | done (`500973a`, `231d02a`) |

| §2026-06-03-c | **Волна 2026-06-03 (3) — счётчики/просрочка/sticky/UI + авто-закрытие по отказу менеджера.** **(1) «Все» = Активные + Закрытые + На паузе** (`59d1af9`,`5399250`): чип «Все» в пуле считал все статусы вкл. `Pending` («в разборе»), не входящий ни в одну корзину → сумма не сходилась. Теперь `Pending` исключён и из счётчика `bucketCounts['all']`, и из списка bucket=all. **(2) «Просрочено» = строго SLA-breach** (`6c16aae`,`7fe461e`): дашборд считал `attention_level=1` вкл. `client_replied` (490), а чип в пуле исключал его (390) — рассинхрон. По решению пользователя «Просрочено» теперь = `attention_level=1 AND attention_reason=sla_breach` ВЕЗДЕ (дашборд `Dashboard::requestCounts`, чип `Pool::bucketCounts['overdue']`, список bucket=overdue) → ~153. Событийные attention (`fresh_assignment` ~235, `client_replied` ~100, `postponed_resume`) — это «требует внимания», НЕ просрочка (см. `AttentionService::compute` — дедлайн-reason всегда SlaBreach; остальные reason'ы выставляются событиями). **(3) Двунаправленные sticky-связи** (`d540126`): в hero карточки была только forward-ссылка (`sticky['links']`), с заявки-«якоря» вернуться нельзя. Добавлен `Detail::stickyConnections()` (forward + reverse через тот же LIKE по `request_assignments.reason."linked"`), в hero — обе стороны со стрелками (→ эта прилеплена к той, ← та к этой, ↔ взаимно), кликабельно; terminal-связанные приглушены. Пример: M-2026-2828 (`linked=[2574]`) ↔ M-2026-2574. **(4) Дата/время закрытия в списке** (`cc90cae`): у «Закрыто · успех/потеря» в пуле под чипом выводится `closed_at` (как в карточке). **(5) Авто-закрытие по отказу МЕНЕДЖЕРА** (`b531e9e` + операц.): выяснили, что `detector.auto_mode.outbound_declined` был ЕДИНСТВЕННЫМ выключенным детектором (поэтому отказ клиента `inbound_decline` авто-закрывал, а «менеджер не сможем поставить» — нет; кейс M-2026-3126). **Включили** `detector.auto_mode.outbound_declined=true` (порог 0.85); `AiDecisionService::apply` теперь дефолтит reason для outbound_declined → `we_cant_offer` (если детектор не прислал свой; не требует комментария). Разово применили 56 существующих suggested ≥0.85 → **закрыто 32**, пропущено 24 (уже закрыты). Грабли: детектор часто кладёт `suggested_closed_lost_reason=off_topic` в payload → реальные закрытия получили ярлык `off_topic`, хотя по смыслу `we_cant_offer` (наш дефолт срабатывает только при отсутствии reason в payload). M-2026-1752 (кандидат на split) тоже закрылась так. **(6) Персональный порядок писем в «Переписке»** (`c6cb49e`): кнопка «Сначала старые ↑ / Сначала новые ↓» в табе thread; выбор сохраняется per-user в `users.thread_sort_order` (миграция, default `asc`), применяется ко всем заявкам; рендер `$thread->reverse()` только в этом табе (остальная логика опирается на хронологический `$thread`). | feat + bug fix | done (`59d1af9`,`5399250`,`6c16aae`,`7fe461e`,`d540126`,`cc90cae`,`b531e9e`,`c6cb49e`) |

| §2026-06-02 | **Волна 2026-06-02 — счета/уведомления/детектор/доки/mail-review.** **(1) Аннулирование счетов при закрытии как потеря:** хук в `RequestStateService::transitionTo` при переходе в ClosedLost зовёт `InvoiceService::cancel()` для всех pending-счетов заявки (статус уже terminal → `maybeTransitionToAwaitingInvoice` не вернёт назад). CLI `invoices:cancel-closed-lost --dry-run/--apply` для бэкфилла. Исторически аннулированы №5649 (M-2026-2389), №5694 (M-2026-2520). **(2) Авто-уведомления клиенту — 3 бага:** (a) тред — `ClientNotificationService` затирал `Re: <тема клиента>` шаблонным subject → Gmail отрывал в новый тред; теперь сохраняем reply-subject от `createReply`. (b) Markdown не рендерился — `OutgoingMailMimeBuilder::composeFinalBody` собирал тело только из `body_plain` через `plainToHtml`, готовый `body_html` игнорировался → клиент видел сырые `**`; теперь pre-rendered `body_html` используется как контент, к нему доклеиваются ОБЩИЕ подпись+футер+цитата (как у ручных писем; ручные не затронуты — у них `body_html=''`). (c) убрана «карточка»-обёртка `notification_wrap` (в ней светился `mzcorp.ru`) — уведомление = обычный ответ в треде; шаблонная подпись возвращена. (d) Дубль подписи: из всех 6 шаблонов убрано хвостовое «С уважением, {{manager_name}} {{company_name}}» (rich-подпись `EmailSignatureService` его уже даёт) — правка сидера + UPDATE 6 строк в прод-БД (`firstOrCreate` их не перезаписывает). **(3) PostSaleFulfillmentDetector:** «Прошу выставить счёт и поставить на комплектацию: M12243 — 5шт» ошибочно → post_sale. Добавлены маркеры `выставить сч…` + regex `/\d+\s*шт/u` (склеенное «5шт.»), unit-тесты. Потерянное письмо #9904 переобработано → заявка M-2026-2953. **(4) Mail-review:** доступ роли `secretary` (роут перенесён в группу с секретарём + пункт меню); хронологическая сортировка по `sent_at` (явные кнопки «Сначала новые/старые», URL `?sort=`, NULLS LAST). **(5) Документация:** `director`+`head_of_sales` видят все разделы как admin (`DocPage::FULL_ACCESS_ROLES`); `DocsService::findPage` больше не гейтит по роли (только наличие файла) — кросс-ссылки `common/*`→`manager/*`/`rop/*` больше не дают 404, ролевой фильтр остался только в сайдбаре (`visibleSections`). **(6) Инфра:** настроен 2-й SSH deploy-ключ `id_ed25519_mzcorp` (см. § SSH-доступ). | bug fix + feat | done (`33b8632`, `114fa8e`, `05a62bc`, `942f087`, `40526f7`, `dfd7742`, `4fbb053`, `6bbd522`, `5017e71`, `7c0604e`) |

KB drop-in, sticky-snapshot, catalog (A+B+C), settings UI — **закрыты в Фазе 2 (2026-05-08, 2026-05-12).**
Phase 1.9 UI-переписка, Priority 1 ручное управление позициями, Phase 1.10 state-machine — **закрыты 2026-05-14, 2026-05-15.**
Phase 1.11 Attention-механизм — **закрыт 2026-05-16.**
Phase 4.0 DocumentDetector — **закрыт 2026-05-17.**
Phase 5.2 Reanimate closed — **закрыт 2026-05-18.**
Foundation Фаза 2 хвост (unavailable / mail-review / notifications) + delegation rework + планируемая недоступность — **закрыт 2026-05-19.**
Foundation §6.2 структурированные уточняющие вопросы (Phase A history + Phase B/C LLM matching + enrichment) — **закрыт 2026-05-20.**
Foundation §6.2 Phase D (target_slot_key) + Phase E.1-E.4 (top suggestions block + AI banner + auto-apply + combo-режим карточек + история под списком) — **закрыт 2026-05-21.**
Vision photo-binding hardening (1×1 fallback + brand-specific article patterns) — **закрыт 2026-05-21.**
Удаление 2-го уровня AI-классификации почты (MailClassifierService / EmailClassification удалены, решение про создание Request — только по EmailCategory) — **закрыт 2026-05-22.**
Pool re-sort «как в почте» (last_activity_at DESC) + FreshAssignment + Manual flag (ручной флаг attention) — **закрыт 2026-05-22.**
Колонка «Событие» в Pool + RequestActivityType (18 значений) + auto-silence attention при передаче хода клиенту — **закрыт 2026-05-22.**
Level 3.5 matchByExternalCode в Mail-linker'ах (LZ-REQ-NNNN) + outbound footer с № заявки — **закрыт 2026-05-22.**
CLI backfill + duplicate-detector (mail:reassign-by-external-code + mail:detect-duplicate-requests) — **закрыт 2026-05-22.**
Багфикс pingpong'а в mail:reassign + защиты (--keep-outbound/--keep-active/--only-if-newer/--code) + TrustedPartnerOverride для категоризатора — **закрыт 2026-05-22.**
Слияние заявок-дубликатов (RequestMergeService + MergeDialog UI + ClosedLostReason::Duplicate) — **закрыт 2026-05-22.**
ReplyParseGate (signal-based отрезание пустых reply'ев от парсера) + confidence-based suggestion для item-create из reply (auto/suggest/skip, pending UI плашка, Activity audit) + переименование «слияние дубликата» → «объединение заявок» — **закрыт 2026-05-22.**
ItemCatalogLinkDialog UX-волна (chip-фильтры по бренду/категории/размеру/узлу, hover-preview миниатюр, compare-modal redesign по макету 05b с inverted grid, sticky-left subject, ✓ confidence на cell-уровне) + дилер-флаг автомат (`dealer_emails` таблица + DealerEmailService + skip client-sticky 1b) + Дашборд РОПа v1 (period switcher 7/30/90, funnel received→quoted→won/lost, conversion%, heatmap inflow-by-hour DOW×HOUR Europe/Moscow, sparklines per менеджер 14-дн SVG) + clarifications confidence high/low (auto-apply без ручного review для high) + **Приоритет 4 — регулярный sync MDB → прод** (catalog:sync-from-url: public HTTP pull + HEAD/SHA-256 change-detect + mdb-export → CSV → catalog:import, scheduler `0 3,7,11,15,19,23 * * *` MSK, mdbtools на проде) — **закрыт 2026-05-19.**
Activity-расширение (все письма треда + новые типы events: merge_from/merged_into / items_parsed_from_reply / suggestion_applied/rejected) + placeholder safety net в ClarificationPanel + расширенный blockquote-selector в collapseQuotedBlocks (Gmail/Yahoo/fallback any blockquote) — **закрыт 2026-05-22.**
InternalSenderDetector + Empty-content guard + РОП = full request-handler (Role::requestHandlerRoles в AssignmentService/ManagerUnavailabilityService/ReassignDialog/Dashboard/cron) — **закрыт 2026-05-15.**
RouteMailToManagerJob (IMAP-move в очередь, reassign в UI мгновенный вместо 5–10s sync wait) + shortName fallback на email local-part когда транслитерация без латинских букв (фикс «MZ|3» для РОП'а с именем типа «РОП 3») — **закрыт 2026-05-15.**
ReassignDialog event-flow (request-state-changed dispatch вместо $this->redirect navigate:true) + удалён standalone Alpine из app.js (Livewire 3 поставляется со своим, double-instance ломал entangle / navigate / @click.outside на notifications-bell) — **закрыт 2026-05-15.**
MailDeliverToManagerService + DeliverToManagerInboxJob (IMAP APPEND оригинала .eml в INBOX личного ящика assigned-менеджера; идемпотентен через detected_artifacts.inbox_deliveries[]) + триггеры в AssignmentService::autoAssign и ReassignService::reassign + CLI mail:deliver-backfill для существующих заявок — **закрыт 2026-05-15.**
MailDeliverToManagerService::fetchFullRfc822 (re-fetch полного RFC822 headers+body из source IMAP вместо body-only raw_source — фикс «Без отправителя/темы» при APPEND) — **закрыт 2026-05-15.**
OutboundDocumentClassifier (LLM gpt-4o-mini) как fallback после rule-based OutboundDocumentDetector + ClassifyOutboundDocumentPrompt (4 типа: quotation/invoice/clarification/other) + MessagePersister::decodeMimeHeader robust к рваным encoded-word'ам (regex fallback) + filename `... pdf` → `...pdf` нормализация + CLI mail:redecode-attachment-names для backfill старых битых filename'ов. Кейс: PDF «Предложение МЗ-355319.pdf» с body=«КП» через Liftway portal — раньше detector видел «=?UTF-8?B?...?= pdf» и body=0chars, теперь декодит filename и LLM-fallback классифицирует — **закрыт 2026-05-15.**
AttentionService onManagerOpened/onManagerHandled теперь СНАЧАЛА обнуляет sticky reason (forceFill) и потом recompute(fresh) — раньше recompute exit'ил рано через isStickyReason и info-флаги залипали навсегда. Pool blade: новый $isInfoFlag (ClientReplied|FreshAssignment|Manual|SupplierReplied) — info-флаги не подсвечиваются красным «просрочено N», вместо этого амбер с человечным текстом («новая» / «есть ответ» / «ответ поставщика» / «🚩 пометка») — **закрыт 2026-05-15.**
RequestStatus::allowedTransitions расширены — New/Assigned/AwaitingClientClarification → Quoted напрямую (раньше требовался промежуточный InProgress). UI: «📦 «<catalog name>»» в _position-card и _item-row после M-SKU ссылки на mylift.ru (название из catalog_items.name, было до Phase 1.10p1 редизайна) — **закрыт 2026-05-15.**
Implicit-state — auto-transition Assigned/New → InProgress в Detail::mount когда viewer=ответственный менеджер или acting (isAccessibleBy). РОП/директор/секретарь НЕ триггерят. Event=auto_first_open. Кнопка «▶ Начать работу» убрана из action-panel для Assigned/New (теперь implicit) — **закрыт 2026-05-15.**
Semi-auto переходы — убраны кнопки «📨 КП отправлено» и «❓ Жду уточнение клиента». Quoted ставится через OutboundDocumentDetector + LLM-fallback (AI-плашка «Применить» одним кликом). AwaitingClientClarification ставится через ClarificationPanel post-send hook. Implicit-state матрица: открыл → InProgress, отправил batch → AwaitingClientClarification, выслал КП → Quoted, получил «принимаем» → AwaitingInvoice, поставил флаг → Manual attention — **закрыт 2026-05-15.**
collapseQuotedBlocks теперь сворачивает не только blockquote, но и attribution-headers ПЕРЕД blockquote (Yandex-формат «Кому:/Тема:/От:/Дата:/«DD.MM.YYYY, NAME wrote:»»). Новый helper looksLikeQuoteAttribution с 10 RU/EN regex-якорями. Кейс: reply через Yandex web UI оставлял attribution видимым над «показать цитату» — **закрыт 2026-05-15.**
Pool group bucket merge: Assigned + InProgress объединены в один header «В РАБОТЕ» (Assigned эфемерный из-за implicit-state). groupBy в Pool::render маппит Assigned → InProgress bucket-key, $statusLabel override убран. Чип в строке всё ещё показывает реальный enum status — **закрыт 2026-05-15.**
Cross-mailbox дедуп — 3 защитных слоя против Request-дублей при IMAP APPEND в личный ящик менеджера. **L1 (главный, pre-create):** `MailDeliverToManagerService::deliver` сразу после `appendMessage` пишет placeholder-row в `email_messages` для целевого ящика (mailbox_id=manager, folder=INBOX, message_id, related_request_id, direction=inbound, imap_uid=null, detected_artifacts.cross_mailbox_copy_of=original.id). Sync личного ящика через MessagePersister находит existing → только заполняет imap_uid+flags+raw_source → return null → MailRouter НЕ вызывается → 0 LLM-вызовов. **L2 (MailRouter pre-check):** в начале inbound-ветки проверка по message_id — если existing с related_request_id, наследуем category, выходим. **L3 (InboundReplyLinker Level 0):** last-resort match по message_id перед всеми остальными уровнями. UI: Detail::mount thread query фильтрует cross-mailbox копии (WHERE detected_artifacts->>'cross_mailbox_copy_of' IS NULL) — показывает только оригинал. Кейс: M-2026-0911 + M-2026-0912 дубль (sync личного ящика подобрал APPEND'нутую копию как новое inbound) — **закрыт 2026-05-15.**
Большая волна 2026-05-24 (25+ коммитов): UnintendedRecipientDetector + Detail::mount гейт owner/acting + Level 0 direct_mailbox sticky + reanimate reassessment + irrelevant блокирует linker + Phase 1/2.1/2.2/2.3 inheritance (auto-reanimate выключен → наследование через LLM check + UI item-mapping + manual reanimate) + AttachmentController + MessagePersister filename fix через raw header (resolveRawFilename + repairCorruptedBase64) + mail:rebuild-attachment-names CLI + thread_reply без linker-match создаёт Request + order@myzip.ru allowlist + ParseItemsPrompt service guards + isServiceItem код-фильтр + recomputePossibleDuplicates UI chip + nginx gzip JSON + lazy на 10+ dialog-компонентов (Detail page 252 КБ→7-18 КБ, 6с→0.5с) + ResolvePendingFromCatalogJob chunk-dispatch + RAM 1.9→3.8 GB + swap 2GB + supervisor --memory=600 --max-jobs=200 — **закрыт 2026-05-24.**

Экспорт в 1С, KB curator UI, PriceRefreshService — **за пределами текущей фазы.**

## Открытые вопросы / TODO

### Закрыто 2026-06-18
- ✅ **Унификация стоп-листа и поставщиков — переписку поставщиков читаем** (`c345061`). Заказчик: `sender_blocklist` = пул поставщиков (чтобы их ответы не плодили фейк-заявки), переписку с ними НУЖНО ЧИТАТЬ; кнопка «📦 Это запрос поставщику» дублировала — УДАЛЕНА (+ Detail::markAsSupplierInquiry). `sender_blocklist.kind` (BlocklistKind spam|supplier); все 14 текущих → supplier (диагностика out_rfq≥2 у 13/14). supplier-kind: письмо не создаёт заявку, но `SupplierInquiryService::ingestSupplierMessage` прикрепляет как переписку (category=supplier_reply) → читаемо в /dashboard/suppliers; spam-kind — отбрасывается как раньше. `SenderBlocklistService::match()` (вместо только isBlocked); ветвление в MailRouter+IncomingMailProcessor. Единый вход — `CloseLostDialog`: причина «Переписка с поставщиком» + чекбокс «занести в стоп-лист (весь ящик)». Да → block(kind=Supplier)+реестр (вся почта=переписка); Нет → markFromRequest (только тред, адрес ещё может слать заявки). BlocklistIndex UI: вид (поставщик/спам) + колонка. **Прод: миграция DONE, 14 записей→supplier, 658 исторических писем привязаны как переписка (читаемы), 16 inquiries (steven 280/eve 127/paulschaab 98/unisystem 78), реестр suppliers=62.**
- ✅ **Фаза 3.1 — bootstrap реестра поставщиков из истории исходящих** (`fb83579`). CLI `suppliers:mine-from-outbound {--apply}{--limit}{--min-rfq=2}`: кандидаты = получатели исходящих с RFQ-сигнатурой темы; **дискриминатор поставщик≠клиент структурный** — нет входящих `client_request` (+ доменный гард, порог 3: домен с client_request = клиентский, напр. meteor.ru). Контент-LLM не годился (партнёр liftway 5/5 ложно YES; поставщики размывались логистикой). Для подтверждённых: registerEmail + LLM-описание из номенклатуры запросов (`SummarizeAssortmentFromRfqPrompt`) → SupplierMatrixBuilder→36 категорий. Идемпотентно (ручное описание не трёт). **Прод: 499 кандидатов→53 поставщика зарегистрировано с описаниями+матрицами (393 клиента отсеяно). Покрытие 30/36 категорий; 6 без поставщика (нишевые эскалаторные/аварийные).** Качество проверено на 5 (описания читаемые, бренды снапнуты к нашим 43, категории к 36).
- ✅ **Фаза 3.1 — выравнивание покрытия матчинга** (`9b406d6`). Проверка соотношения матрицы с нашей таксономией (запрос заказчика «чтобы не было неохваченных»). Диагностика прода: каталог категоризирован на 91% (36 KB-категорий, 233 part_type), НО 55% активных позиций заявок (5841/10568) БЕЗ KB-категории — зато 70% привязаны к каталогу; без обоих (категория+бренд) лишь 6% (650). Матрица раньше строилась словами поставщика («лебёдки» — а в наших 36 категориях её нет, это «Двигатель главного привода»). **Fix A:** BuildSupplierMatrixPrompt грунтит на наши 36 категорий (закрытый список) + 43 бренда, LLM маппит на дословные имена, SupplierMatrixBuilder снапит к каноническим (категории строго из 36). **Fix B:** SupplierMatchService берёт категорию/бренд с фоллбэком из catalogItem (equipmentCategory/brand) → «без категории» 55%→~13%. Проверено на проде end-to-end (грунтинг + catalog-fallback). Остаток ~6% — вручную. Параллельно: KB-категория у 55% позиций отсутствует (задача покрытия KbResolve).
- ✅ **Фаза 3.1 — профиль поставщика + матчинг под позицию** (`8145cab`, фикс `7cce0a4`). Старт Фазы 3 (заказчик выбрал 3.1 первым; RFQ позже из ЛИЧНОГО ящика менеджера). Foundation §4.2. `suppliers` += phone/assortment_description/assortment_matrix(jsonb {brands,categories,pairs})/matrix_built_at/matrix_built_with_model. `SupplierMatrixBuilder`(gpt-4o-mini)+`BuildSupplierMatrixPrompt`: описание → матрица (fail-safe). `SupplierMatchService::relevantSuppliers(RequestItem)`/`matches()` — подбор brand∩category: бренд exact-norm, **категория пословный СТЕМ-матч по общему префиксу** (≥4 и ≥minlen−2 — ловит «лебёдка↔лебёдки»; подстрочный матч НЕ ловил, багфикс `7cce0a4`) + substring; categoryTerms = KB name+synonyms. UI карточка поставщика `Suppliers\SupplierEdit` (роут `suppliers.registry-edit`, до `{inquiry}`) + «↻ Пересобрать матрицу» + ссылки «профиль» из вкладки «Реестр». Проверено на проде (temp-поставщик): матрица собирается, KONE+лебёдка=yes / OTIS+двери=yes / Schindler+лебёдка=no / KONE+канат=no. **Контракт для 3.2** (группировка supplier×items при dispatch). Решено: переиспользовать `SupplierInquiry` как batch-якорь (не отдельный PriceRefreshBatch). `RequestStatus::pending_price_refresh` ещё НЕ добавлен; `AttentionReason::AwaitingSupplier` уже есть. Донора LazyLift на машине НЕТ → парсер ответов (3.3) пишем с нуля.
- ✅ **Модуль поставщиков — send-time детект RFQ (главный механизм)** (`91119aa`). Уточнение заказчика к `6991a14`: нужен НЕ постмодерейшн, а ловить ОТПРАВЛЕННЫЕ письма (хоть из системы, хоть из почтового клиента — UI создания письма вне заявки нет) и помечать тред в момент отправки, без ручной работы менеджера. Данные с прода: наши исходящие поставщику синкаются из Sent даже из Яндекса; ответ поставщика точно ссылается на `message_id` нашего исходящего. Сигнал распознавания (по решению заказчика): **реестр поставщиков + LLM-проверка** (контрагент бывает и клиентом → подтверждаем, что письмо реально RFQ со списком номенклатуры, а не ответ ему как клиенту). Реализация: таблица `suppliers`(email/домен/name) + `App\Models\Supplier` + `SupplierRegistry`(isSupplier по email|домену); `SupplierRfqClassifier`(gpt-4o-mini)+`ClassifySupplierRfqPrompt`(is_rfq+confidence, fail-safe <0.6→не RFQ); `SupplierInquiryService::createFromOutbound`(идемпотентно по thread_root_id=message_id + bootstrap реестра); хук в **MailRouter outbound-ветке** (получатель в реестре + LLM=RFQ → createFromOutbound). Ответ поставщика (In-Reply-To на наш message_id) ловит существующий `matchInbound` → переписка category=supplier_reply, заявка НЕ создаётся. UI: вкладка «Реестр» в /dashboard/suppliers (add email/домен/list/remove). Кнопка «📦 Это запрос поставщику» на заявке оставлена fallback'ом (мимо-MyLift письма + bootstrap реестра). Прод: миграция DONE, `0028087@mail.ru` добавлен в реестр, LLM на реальном RFQ «[358274] запрос от Мой Лифт»→is_rfq=true conf=0.9, воркеры перезапущены. **Прошлые 15 исходящих RFQ НЕ переобрабатываются** (ловим только новые отправки). Phase 3 (упомянул заказчик): формировать RFQ на конкретные позиции из системы.
- ✅ **Модуль поставщиков (фундамент) — ответы поставщика больше не плодят фантомные заявки** (`6991a14`). Кейс `0028087@mail.ru` (Ринат Калимуллин): контрагент и клиент, и поставщик, но 42/42 входящих = ответы на наши треды (0 свежих) → чистый поставщик. Менеджер шлёт запрос расценки поставщику («Запрос наличия/стоимости/сроков поставки», «[NNNNNN] запрос от Мой Лифт») часто из Яндекса напрямую (исходящего в БД нет), поставщик отвечает (category=`thread_reply`, In-Reply-To на `@myzip.ru`), линкер не находит родителя → `IncomingMailProcessor` плодил фантомные клиентские заявки (8 шт у 570, одна доведена до closed_won). **Решение (тред-центрично, КОНСЕРВАТИВНО по решению заказчика — подавление только в ЯВНО помеченных тредах):** таблица `supplier_inquiries` (supplier_email/name, subject, `thread_root_id` для матчинга, related_request_id опц., status, notes) + `email_messages.supplier_inquiry_id` + `EmailCategory::SupplierReply` + `ClosedLostReason::SupplierReply`. `SupplierInquiryService`: `matchInbound` СТРОГО по цепочке треда (in_reply_to/references ∩ {thread_root_id} ∪ {message_id прикреплённых}), `markFromRequest`, `attachMessage` (category=supplier_reply + categorized_at, не утекает в /mail-review). Гард в `MailRouter` (до LLM, до linker) + defense-in-depth в `IncomingMailProcessor` (cron-пути): совпало → письмо прицепляется как переписка, заявка НЕ создаётся. Кнопка «📦 Это запрос поставщику» на карточке заявки (`Detail::markAsSupplierInquiry`, owner/acting/privileged, активные) → создаёт inquiry + прицепляет письма + закрывает заявку (closed_lost reason supplier_reply, fallback systemCloseLost). Раздел `/dashboard/suppliers` (Index+Show, все роли) + rail «◇ Поставщики» (активирован из placeholder) + блок «Запросы поставщику» на карточке контакта в «Клиентах». **Первый ответ в НЕпомеченном треде всё ещё создаёт фантом** (until marked) — это и есть «только помеченные треды». **8 существующих фантомов 570 НЕ тронуты** (по решению заказчика — пометит кнопкой при необходимости). Прод: миграции DONE, count=0, matchInbound на реальном письме=null (ok), воркеры перезапущены. Открыто: авто-подсказка «похоже на поставщика» (отклонена), полный Phase 3 (suppliers-таблица, оферты, отправка запросов из MyLift), таб «supplier» в Detail::TABS (заготовка, не задействован).
- ✅ **Точная привязка заявки к организации — `requests.organization_id`** (`a96df34`). Раньше связь заявки с организацией выводилась только косвенно (`requests.client_email` ∈ email'ы контактов орг.) — неточно (один email может быть у нескольких орг.) и не правится руками. Миграция: `requests.organization_id` (nullable FK → organizations, `nullOnDelete`, индекс) + `Request::organization()` / `Organization::requests()`. Новый `App\Services\Clients\RequestOrganizationResolver` (консервативный, не гадает): `resolve()` по `client_company` (точное совпадение ровно с одной орг.) → по `client_email` (контакт ровно с одной орг.), иначе null; `attach()` (если ещё не задан); `backfillForEmailLink(org,email)` (set-based UPDATE ещё не привязанных заявок email при появлении связи email↔орг). Авто-привязка во ВСЕХ точках создания заявки: `IncomingMailProcessor`, `EmailToRequestPromoter`, `RequestSplitService` (наследует organization_id родителя), `Clients\Show::addContact`, `clients:backfill` (новый шаг 4 `linkRequestsToOrgs`) + `clients:extract-requisites`. Карточка организации (`Clients\Show`) — статистика по `organization_id` **+ fallback по email** для ещё не привязанных (без регресса до бэкфилла, без двойного счёта: заявки, явно привязанные к другой орг., в fallback не попадают) + блок «Заявки организации»; список орг. — столбец «Заявок» (`withCount('requests')`). **Прод-бэкфилл:** 2660/4973 заявок привязано к 220 орг. (здоровый long-tail; топ «Метеор Лифт Москва» 324). Остальные null — email не привязан ни к одной орг., растёт по мере `extract-requisites`. **Грабля:** у части орг. ИНН реальный, имя мусорное (артикул, «ип DDE со шкивом V» — 239 заявок) — привязка по ИНН корректна, имена чинить руками/`extract-requisites`. **Локально БД недоступна** (TCP-порт открыт, но сессия зависает rc=124 — только прод), миграция+бэкфилл прогнаны на проде. **Не сделано (фоллоу-ап):** авто-создание ClientContact при создании заявки (тогда реестр живой для новых клиентов, привязка не ждёт бэкфилла); ручной override привязки в карточке ЗАЯВКИ.

### Закрыто 2026-06-16
- ✅ **Письмо терялось из-за краша MIME-декода на неизвестном charset** (`99b3820`+`b582560`). Кейс Боева: клиент `VasilievDY@trc-nora.ru` «Тяговые канаты» (счёт на тяговый канат M05822) пришёл на info@, но в mzcorp его не было. Причина: тема/имя MIME-закодированы в **KS C 5601 (= EUC-KR, блок 0xAC содержит кириллицу)** с меткой `ks_c_5601-1987`, которую mbstring НЕ знает → `mb_convert_encoding` кидает **`ValueError`**, а оператор `@` его НЕ глушит (только warnings) → `MessagePersister::decodeMimeHeader` падал, письмо не персистилось и тихо выпадало из pipeline (ни Request, ни лога об ошибке). Фикс: `safeConvertToUtf8()` — try/catch ValueError + **алиас-мап** (`ks_c_5601-1987`→`EUC-KR` и др.) + эвристика (валидный UTF-8 как есть → Windows-1251); прогнал через неё все 4 динамических `mb_convert_encoding($x,'UTF-8',$charset)` (тема/имена файлов). **Восстановление:** письмо физически лежало в INBOX info@ UID=251685, но НИЖЕ watermark синка (251700) — появилось в папке позже соседей (вероятно придержал спам-фильтр Yandex), инкрементальный синк его «перешагнул». Откат watermark гонялся с кроном — поэтому за-persistил UID 251685 напрямую (`persist`+`MailRouter::route`) после фикса декода → Request **M-2026-4742**, позиция M05822, назначен **Румянцеву**, routed MZ\|Rumyantsev. Битые subject/from_name/client_name в #22621 и 4742 поправил реверсом mojibake→байты→EUC-KR. **Грабли на будущее:** (1) `@` НЕ глушит ValueError/Error в PHP 8 — для внешних данных нужен try/catch; (2) письмо может появиться в INBOX с UID ниже watermark (спам-фильтр/задержка) и тихо пропасть — отдельный нерешённый риск, лечится full-rescan при жалобе.
- ✅ **UI к истории цен: страница аналитики + бейдж в карточке каталога** (`6545344`). Аналитика → «Изменения цен» (`/dashboard/analytics/price-changes`, Livewire `Analytics\PriceChanges`, фильтры период/направление/SKU, пагинация, было→стало+Δ+%; доступ head_of_sales/director/secretary/admin; ссылка с `analytics.index`). В карточке каталога (`/dashboard/catalog/search`, блок «Цена и наличие») строка «Динамика цены»: ▲ подорожал / ▼ подешевел на N ₽ (%) по последнему `catalog_price_changes` (преподгрузка `Search::lastPriceChangeByCatalogId`, паттерн как `iqotByCatalogId`) + ссылка «История цен по позиции →» (`analytics.price-changes?q=SKU`, только привилегированным ролям, т.к. аналитика гейтится). На дашборд НЕ выносили (по просьбе). **Грабли деплоя:** роуты кэшированы — после деплоя `route:clear`+`view:cache`.
- ✅ **Логирование изменения цен каталога (было → стало)** (`54a770f`). Каталог = read-only master data из MDB, тренды цен не фиксировались. Таблица `catalog_price_changes` (catalog_item_id, sku, old/new_price, old/new_price_min, import_id, changed_at) + модель `CatalogPriceChange`. Хук в `CatalogImportService::import()`: в UPDATE-ветке (source_hash изменился) сравниваем старую `price`/`price_min` (подгрузили в `$existing`-select) с новой; при расхождении пишем строку (батчем, внутри импорт-транзакции). Все пути импорта идут через `import()` (`sync-from-url` → `Artisan::call('catalog:import')`), так что покрыты все снапшоты. Отчёт `catalog:price-changes {--days=30}{--sku=}{--direction=up|down}{--limit=}` — было→стало, Δ, %. Проверено транзакцией-с-откатом: M08800 6855.03→6966.14 зафиксировалось (Δ+111.11), прод не тронут. Реальные данные накопятся со следующего слота `catalog:sync` (11/15/19/23/03/07 MSK). **Не логируем:** новые SKU (нет «было»), и step C «marked_unavailable» цену не трогает (только is_price_actual/stock).
- ✅ **Корень грабли: наше исходящее из CC больше не оседает rel=null orphan'ом** (`49159a2`). Прямой cross-mailbox дедуп в `MailRouter::route()` (стр.184) односторонний — линкует входящую копию, только если залинкованный двойник с тем же message_id УЖЕ есть. Наше исходящее, попавшее в CC во внутренний ящик коллеги (Ежов), синкается отдельным inbound с тем же message_id; если копия пришла раньше, чем outbound получил `related_request_id`, дедуп её пропускал → `irrelevant` + rel=null → ложный orphan-defer линкера → пустая заявка. Фикс: `MailRouter::backfillCrossMailboxCopies()` — когда исходящее залинковано (outbound-ветка), ретроактивно подшиваем все rel=null копии того же message_id к той же заявке + `cross_mailbox_copy_of` (message_id глобально уникален → все совпадения = копии одного письма). **Разовая чистка: 13 накопленных orphan-копий подшиты (включая #21584→3965), осталось 0.** Вместе с гардом `43b6821` (гасит пустой reply на закрытый тред) грабля закрыта с двух сторон.
- ✅ **Пустой thread-reply на ЗАКРЫТЫЙ тред больше не плодит заявку-шум** (`43b6821`). Кейс M-2026-4688/4546: клиент Савоськин ответил «так выгрузили доки или ещё нет?» в тред закрытой (won) **M-2026-3965** (чужой менеджер user14), письмо пришло в личный ящик Курзаева. `InboundReplyLinker::route()` не залинковал: единственный реальный header-родитель (#21585→3965) **closed_won И out-of-scope** Курзаева (scope-гард справедливо блокирует чужую закрытую), плюс на тот же In-Reply-To висел `irrelevant`-дубль #21584 (копия нашего же исходящего, прилетевшая Ежову из CC, rel=null). Линкер ушёл в orphan-defer (вернул null), а `IncomingMailProcessor` по фоллбэку «висящий reply» создал пустую заявку, `assignIfStuckPending` назначил её Курзаеву. Фикс: `InboundReplyLinker::findHeaderParentRequest()` (родитель по In-Reply-To/References независимо от статуса/scope) + гард в `ParseRequestItemsJob` (ветка empty-items, ПОСЛЕ adopt-from-parent, ДО assignIfStuckPending): если `thread_reply` + items=0 + header-родитель **терминальный** → `systemCloseLost(ParserNoContent)` вместо создания/назначения пустышки. Узко: открытые треды не трогаем (reply к ним должен линковаться), adopt-from-parent не задет (он выше, items>0). Бэкфилл: 4688/4546 закрыты вручную (`systemCloseLost`, Duplicate). **Грабли на будущее:** наши исходящие из CC оседают `irrelevant`-копией с тем же message_id и rel=null — orphan-defer на них ложно срабатывает; здесь обошли через terminal-parent гард, но корневой дубль остаётся.

### Закрыто 2026-06-15
- ✅ **Стоп-лист авто-уведомлений по e-mail клиента** (`4989f71`). Клиент попросил не слать авто-уведомления — теперь админ (РОП/директор/админ) на `/dashboard/notification-optouts` (ссылка с `/dashboard/notifications`) вводит e-mail и чекбоксами отмечает типы, которые ОСТАВИТЬ; остальное глушится. Таблица `client_notification_optouts` (`email` uniq + `suppressed_types` jsonb — храним явный список заглушённых, UI=«всё минус оставленные», чтобы новый тип не глушился задним числом). Гард — `ClientNotificationOptoutService::isSuppressed` в `ClientNotificationService::dispatch` (единая точка всех 6 типов: cron + sync-хуки). Касается ТОЛЬКО авто-уведомлений; ручные ответы менеджера (`createReply`) не трогаются. Livewire `Admin\Notifications\Optouts` — add-форма + пер-строчные чекбоксы (toggle оставить↔заглушить) + delete. **Грабли деплоя:** роуты на проде оказались закэшированы (новый маршрут не виден до `route:clear`); роуты с замыканиями нельзя `route:cache`, так что прод работает без route-кэша — после деплоя достаточно `route:clear`+`view:cache`.
- ✅ **OpenAI 429 морозил почту: оба структурных фикса применены** (`5415477`). (Раньше было «⏳ под наблюдением» — см. блок ниже про диагностику.) **Фикс №1 — разнос воркеров:** supervisor разделён на два изолированных пула — `mzcorp-worker` (`--queue=mail-sync,default`, numprocs=4) и `mzcorp-catalog-worker` (`--queue=catalog-resolve`, numprocs=1). Теперь каталожные `ResolvePendingChunkJob`, виснущие до timeout=600с под 429, физически НЕ могут занять почтовые воркеры (приоритет очередей этого не давал — он не вытесняет уже бегущую джобу). **Фикс №2 — `App\Services\AI\OpenAIRetry`:** задержка ретраев уважает `Retry-After` / `x-ratelimit-reset-*` (кап 8–10с) + экспоненциальный бэкофф с джиттером; подключён в `OpenAIChatService` (3 попытки, база 2с) и `OpenAIEmbeddingService` (3 попытки, база 1с). Раньше фикс 500мс/2000мс игнорировали подсказку сервера и жгли попытки в тот же 429. **Деплой:** `cp deploy/supervisor/mzcorp-worker.conf /etc/supervisor/conf.d/ && supervisorctl reread && update`. Проверено: 4×mail + 1×catalog RUNNING. **Наблюдение продолжается** — чистый стресс-тест на утреннем пике 07:00–11:00 (16.06): не должно быть ни заморозок почты, ни кластеров «4 воркера на ResolvePendingChunkJob FAIL» (теперь воркер каталога один, так что кластеров по 4 быть не может в принципе; следим за backlog `mail:diag-inbox` и задержкой autoAssign).
- ✅ **Автонапоминания клиенту: реальный номер/сумма КП + тред в ветку документа** (`2742526`). Жалоба менеджера: `quote_followup_reminder` показывал код заявки и «на сумму —» (захардкожено), без реального номера КП, плюс уходил отдельной веткой. Причина: `collectQuoteFollowupReminders` не обращался ни к `Quotation`, ни к `OutboundQuote`, а якорь треда = последнее ВХОДЯЩЕЕ письмо клиента. Фикс: `resolveLastSentQuote()` тянет реально отправленное КП из `Quotation`(status=Sent, UI) ИЛИ `OutboundQuote`(`outbound_quotation_full|partial`, внешнее), даёт `quote_number`/`quote_amount`/`quote_date` + исходящее письмо КП как якорь. Счёт-напоминания — якорь = `Invoice.email_message_id` (номер/сумма уже были реальные). `ClientNotificationService::dispatch` теперь жёстко ставит `to_recipients = client_email` (расцепил адресата и якорь — можно якорить на наше исходящее, не ломая `computeRecipients`). Новые плейсхолдеры + текст шаблона (сидер + прод-row обновлён вручную, был старый дефолт). **Грабли:** парсер кладёт `document_number` без префикса «МЗ-» (показывается «357197»). **Sync-хуки (OrderReceived/ClosedLost) живут в очереди → после деплоя рестартил воркеры.**
- ✅ **Заявки с сайта: реальный клиент вместо order@myzip.ru** (`4397035`). Веб-форма шлёт на info@ с релея `order@myzip.ru`, реальный клиент — в HTML-теле (Организация/Адрес/Контактное лицо/Телефон/E-mail). `IncomingMailProcessor:104` писал `client_email = order@myzip.ru` → переписка/уведомления шли на технический ящик. Фикс: `WebFormSubmissionParser` (детект релей-отправителей из `services.mail.web_form_senders`, дефолт order@myzip.ru; парсинг полей из тела — **после двоеточия только горизонтальные пробелы `[^\S\r\n]*`, иначе при пустом поле regex перепрыгивал на следующую строку**). `IncomingMailProcessor` пишет реальные `client_*` (fallback на From если тело не распарсилось). `EmailDraftService::createReply` — гард: при ответе на письмо веб-релея «Кому» = `client_email` заявки (иначе ручной ответ ушёл бы на order@; авто-уведомления уже чинит подмена client_email + `to_recipients` форс). Новые колонки `client_phone/client_company/client_address` + показ в карточке. `requests:backfill-web-form-clients` — **бэкфилл: 6 открытых заявок обновлены, осталось с order@ = 0.** Теперь sticky «один клиент→один менеджер» работает по реальному клиенту (order@ в `non_sticky` оставлен).

### ✅ ЗАКРЫТО — OpenAI 429 морозил разбор почты (диагностика 15.06, фиксы 15.06, ПОДТВЕРЖДЕНО 16.06)

**Верификация на пике 16.06 07:00–15:00 MSK (первый полный день с разносом воркеров):** задержка autoAssign ровная весь день avg 0.1–0.3 / max <1 мин (15.06 был пик 15:00 avg 5.4/max 10.2, часы 13–14 заморожены); каталожные `ResolvePendingChunkJob` **49 DONE / 0 FAIL** (15.06 было 43+ FAIL + кластеры «4 воркера разом»); `ResolvePendingChunkJob` в почтовом `worker.log` = **0** (полная изоляция); незакреплённых заявок 0; 429 ровно 16–26/час без всплесков. **Вывод:** фикс №1 (разнос воркеров) убрал заморозки, фикс №2 (Retry-After бэкофф) дал бонус — каталожные джобы теперь успевают (0 тайм-аутов). Режим отказа закрыт. Ниже — исходная диагностика для истории.



**Симптом (2026-06-15 ~13:40–15:11):** на общем ящике info@ копились неразобранные письма — Request создавались, но без менеджера (status=pending, assigned_user_id=null), висели в общем INBOX. `mail:diag-inbox --since=24` в 15:11 показал 45 `client_request_pending` + 9 `thread_reply_no_manager`. К 15:13 backlog сам разгрёбся до 2 (возраст <1 мин). Failed_jobs за сегодня нет, воркеры/крон живы, пул менеджеров здоров (7 available).

**Корневая причина (цепочка):**
1. OpenAI режет по **rate-limit (429 `rate_limit_exceeded`, НЕ `insufficient_quota`)** — ~3000+ 429/день, фоном весь день, пик 15:00–15:01 (50+29 шт) на слоте каталожного `catalog:embed` в 15:00.
2. `ResolvePendingChunkJob` (очередь `catalog-resolve`, timeout=600, tries=1, ≤50 items × эмбеддинг+rerank) под 429-штормом прожигает ретраи по всему чанку → суммарно >600с → `TimeoutExceededException` → FAIL (сегодня 43 FAIL).
3. **Все 4 воркера слушают общий `--queue=mail-sync,default,catalog-resolve`.** Когда все 4 разом захватывают висящие каталожные джобы (кластеры по 4 одновременно: 00:14/00:35/00:47/00:58/01:09/01:19/01:30/15:11), очередь `default` (категоризация→Request→парсинг→autoAssign) встаёт на ~10 мин → почта копится. В 15:11 джобы отвалились по таймауту → воркеры бурстом разгребли (61 Request за час 15, avg задержка 5.4 мин).

**Важно:** «изоляция catalog-resolve приоритетом очередей» (коммент в supervisor) НЕ защищает — приоритет решает что взять у свободного воркера, но не вытесняет уже выполняющуюся 10-мин джобу. Это та же природа, что post-mortem 2026-05-28 (см. `## Queue topology`), но теперь триггер — rate-limit, а не зацикливание.

**Слабые места в коде (для будущего фикса, НЕ трогал):**
- `OpenAIEmbeddingService::embedBatch` — `->retry(2, 500, ...)`, фикс 500мс, **игнорит `Retry-After`**.
- `OpenAIChatService` — `->retry(3, 2000, ...)`, фикс 2с, тоже **без `Retry-After`**.
- `ResolvePendingChunkJob` — нет троттлинга/уважения rate-limit окна.

**Связь с прошлой записью:** сессия от (см. line ~608) фиксировала «429 **insufficient_quota** — основной фактор хаоса» — сегодня тип другой (`rate_limit_exceeded`). Баланс пополнен оператором ~15:14; OpenAI поднимает tier (RPM/TPM) с суммой оплаты → пополнение может реально снизить 429. После 15:15 429 = 0.

**Что наблюдаем до завтра (решение примем 2026-06-16):**
- [ ] Следующие каталожные слоты **19:00 / 23:00 / 03:00 / 07:00 MSK** — вспыхнет ли 429 снова? (`grep 429 storage/logs/laravel.log | grep -oE 'HH:MM'`). Если нет — пополнение/tier помогли, структурный фикс можно отложить.
- [ ] Повторяется ли «4 воркера разом на ResolvePendingChunkJob FAIL» (`grep ResolvePendingChunkJob.*FAIL /var/log/mzcorp/worker.log`).
- [ ] Накапливается ли снова `mail:diag-inbox` backlog утром.

**Кандидаты фикса (если затор повторится):** (1) отдельный supervisor-воркер под `catalog-resolve`, убрать его из почтового пула; (2) уважать `Retry-After` + экспоненциальный бэкофф в обоих OpenAI-сервисах; (3) троттлинг параллелизма каталожных эмбеддингов. Приоритет — (1). Диффы показать на ревью перед прод-деплоем.

### Закрыто 2026-05-15
- ✅ **Filename `=?UTF-8?B?...` corruption** — фикснут через regex-fallback decode в `MessagePersister::decodeMimeHeader` + CLI `mail:redecode-attachment-names` для backfill.
- ✅ **Quotation в compose ломается** — частично: `collapseQuotedBlocks` теперь сворачивает Yandex-attribution (Кому/Тема/Дата) вместе с blockquote. Если ещё ломается на каких-то клиентах — диагностика по конкретному эталону.

### Закрыто 2026-05-25

- ✅ **M-2026-1495 (парсер дробил параметрический кластер)** — закрыт через 3 коммита: правило о параметрических кластерах в `ParseItemsPrompt` (`64be09f`), дублирование в inline Vision-промпт (`a06ed62`), **unified Vision-call для ≤3 фото** (`f37d5c4`) — один LLM-вызов видит subject + body + linked URLs + все фото сразу, исключает дубли между text и photo парсерами. Кейс 1495: было 4 позиции мусора, стало 1 идеальная с артикулом «ФАИ.0001010.05» прочитанным с шильдика.
- ✅ **BCC-рассылки → не irrelevant**: `undisclosed-recipients:;` (meteor.ru) → bypass `UnintendedRecipientDetector` через `looksLikeBccBlast()` (`4950f99`); `from == to` (Абасов: клиент сам себе + нас в BCC) — расширение того же helper'а (`5f2f3db`). Грань с M-2026-1491 (info@unisystem.si → valentina.larosa@moris.it) сохранена.
- ✅ **`routeToManager` для shared mailbox** — гард `mailbox.type === Shared` (`9d5e452`). Cross-mailbox copy в personal mailbox больше не триггерит создание `MZ|<Lastname>` в личном ящике менеджера. Уже накопленные 180 закопанных писем оставлены — менеджеры разберут вручную.
- ✅ **OutgoingMailLinker L4 — subject-similarity** (`fedcadf`). Раньше выбирал любую открытую заявку клиента → false-link (M-2026-1549: КП на «Фотобарьер» прилеплен к «Блок управления»). Теперь: НЕ-архивные заявки клиента + Jaccard ≥ 0.5 (config: `outbound_link_subject_similarity_threshold`). Иначе refuse → менеджер ручную.
- ✅ **State-machine: прямой `→ Invoiced`** из active-статусов (`3280282`). Кейс M-2026-1525: detector распознал счёт, но `assigned → invoiced` был запрещён → dismiss. Симметрично с уже разрешённым прямым `→ AwaitingInvoice`.
- ✅ **Pool: дубль status-chip + activity-row** (`671f3fa`). `RequestActivityType::isRedundantWithStatus()` прячет activity-row когда оно дублирует status (Quoted+QuoteSent, Invoiced+InvoiceSent, etc).
- ✅ **Detector: LLM-fallback при слабом rule-based confidence** (`316c6c3`). Раньше LLM звался только если rule-based вернул null. Теперь — также если `confidence < auto-apply threshold`. Кейс M-2026-1589: rule-based 0.6 → LLM-фолбэк бустит до 0.85+ → auto-apply сработает.
- ✅ **Detector: quote-parser boost AiDecision** (`35b31ea`). После `ParseOutboundQuoteJob::match()` если `matched_request > 0` — boost соответствующего AiDecision.confidence до 0.95 + trigger auto-apply. Самый сильный сигнал (позиции реально сматчились с RequestItem'ами заявки).
- ✅ **MessagePersister: RFC 2231 continuation в filename** (`12edaeb`). `resolveRawFilename` теперь использует `parseRfc2231Filename` как primary (handles `filename*0*=`, `*1*=`, ...). Кейс M-2026-1589/att#4312: «Предложение МЗ-356207...pdf» был сломан в `Д\`4-t-4.?/?...`. CLI `mail:redecode-attachment-names-from-raw --name-prefix='Предложение МЗ|Счёт МЗ|...'` (`a930869`) — safety-фильтр для backfill. Прогон сделал 48 файлам.
- ✅ **Empty-body-guard: M-артикул override** (`ab17283`). `IncomingMailProcessor::isContentEmpty` теперь возвращает false если в теле есть `M\d{4,}`. Кейс Liftway: `subject=«Счёт», body=«M04990 - 1шт.»` (12 симв < threshold 40) — раньше irrelevant, теперь Request → каталог-матч M04990 «КВШ для Sigma TKL/TKM» sufficient.
- ✅ **Dashboard РОПа: колонки «всего» / «info@»** (`0666896`). В таблице менеджеров теперь видно `сейчас / всего (за все время) / info@ (из них через info@myzip.ru)` — для понимания распределения.
- ✅ **Round-robin: least-loaded + LRU tiebreak вместо weighted random** (`da9d4e3`). Старый weighted random + boost давал stat-разброс 14 vs 4 при N≈50. Новый алгоритм детерминированный: min(load) → LRU tiebreak. `assignment.newbie_boost` setting больше не используется.
- ✅ **Cross-mailbox delivery — backfill CLI + scheduler** (`42e1bb0` + `95bf2e2`). `DeliverToManagerInboxJob` иногда теряется (queue:restart, worker crash, manual processIfRequest). Команда `mail:backfill-manager-deliveries --apply` ищет активные Request без `inbox_deliveries[user_id]` artifact'а и вызывает `MailDeliverToManagerService::deliver()` напрямую. Поставлена на scheduler каждые 30 минут. Сегодня закрыло 16 пропущенных доставок.
- ✅ **Status-chip vs activity-row семантика разделена** (`778b46d`). Раньше `displayedStatusBadge` накладывал activity-overlay (ClientReplied → «Ответ клиента») поверх chip'а, затирая реальный статус (M-2026-1549: chip показывал «Ответ клиента» вместо «Счёт отправлен»). Теперь chip ВСЕГДА показывает статус по воронке, activity-row рендерится отдельно с amber-стилизацией для requiresAttention.
- ✅ **AwaitingInvoice label: «Согласован / ждёт счёт»** вместо «Ждём счёт» (`1829b8e`). Новый текст явно говорит что КЛИЕНТ ждёт, не мы.
- ✅ **Имена аттачей читаем из raw RFC822 body, минуя Webklex sanitizeName** (`4227c94`). Корень многолетней проблемы «`Д\`4-t-4.?/?-?-t/t.4-H4'4%?L?M???4/?\`??.pdf`» в outbound (Thunderbird/Apple Mail): Webklex `Attachment::sanitizeName()` стрипает `/` из base64 в MIME-encoded именах (`=?UTF-8?B?...?=`) ещё ДО того как мы видим — длина перестаёт быть кратной 4, base64_decode выдаёт мусор. Плюс `$att->getHeader()` для sync из Sent возвращает NULL, поэтому весь fallback через `resolveRawFilename` не запускался — падали в getName() с уже-испорченным name. **Новый главный путь:** `MessagePersister::extractFilenamesFromRawBody($rawBody)` парсит multipart boundaries напрямую в raw_source, для каждого attachment-like парта вытаскивает имя через `parseRfc2231Filename`. В `persist()` имена матчатся к webklex'овым attachment'ам по document-ордеру с гардом `count(parsed) === count(atts)` — отрезает forwarded `message/rfc822` где ordinal ненадёжен. Заодно фикс ветки (2) `parseRfc2231Filename`: regex `[^;]+` → `[^;\r\n]+` (Apple Mail `filename*=utf-8''...` без trailing `;` — ел через перевод строки в следующий header). CLI `mail:rebuild-attachment-names` переписан с фрагильного mime-group bucket'а на чистый ordinal-match. Прогон на проде: **235 имён починилось** из 4733 attachments (5%), 115 пропустили (rfc822 forwards), 14 не смогли (US-ASCII + отправитель прислал уже-битое). Ноль регрессов. Все 4 прежних CLI (`mail:redecode-attachment-names`, `mail:backfill-attachment-names`, `mail:redecode-attachment-names-from-raw`, `mail:rebuild-attachment-names`) кроме последнего deprecated — оставлены для совместимости, новые письма починятся автоматически через persist().

### Закрыто 2026-05-26

- ✅ **При reassign — копия из INBOX бывшего менеджера уходит в `MZ/Reassigned` + STORE \Seen** (`43c5e36`). Раньше письмо оставалось у старого менеджера в INBOX непрочитанным, он мог случайно ответить клиенту. Новый `MailReassignArchiverService` + `ArchiveFromOldManagerInboxJob`: UID MOVE из INBOX личного ящика в подпапку MZ/Reassigned + STORE \Seen (Yandex quirk: оригинал может остаться в INBOX но как прочитанный — счётчик непрочитанных падает). Helper'ы `ensureFolder`/`detectDelimiter`/`parseCopyUid` в MailFolderRouter сделаны public для переиспользования. Гарды: нет личного ящика → skip; копия не подобрана sync'ом (imap_uid=null) → skip с retry; folder ≠ INBOX (менеджер сам переложил) → skip; уже archived → skip (идемпотентность через `inbox_deliveries[i].archived_at`).
- ✅ **LLM-валидатор перед автозакрытием unassigned заявок** (`df75f65`). До: `RequestsRecoverUnassignedCommand` безусловно закрывал Pending+0items+старше 2ч как `parser_no_content`. Среди этих 78 в день попадались реальные запросы (Vision промахнулся, скан, нестандартный формат). Теперь финальный gpt-4o-mini check через `AutoCloseDecisionService` + `AutoCloseDecisionPrompt`: verdict=close (conf≥0.6) → systemCloseLost + payload c LLM reasoning; verdict=keep / low-conf close → autoAssign + state_change event=`auto_recovery_llm_kept`; LLM error → keep (safe fallback). Принцип сомнения: при confidence<0.6 close флипается в keep — менеджер потратит 30 сек на «не заявка», но потерянный запрос дороже. **На тестовых 3 кейсах** LLM правильно пометил все как keep с reasoning по конкретным сигналам.
- ✅ **Пул `/dashboard/requests/auto-closed`** для head_of_sales / director / admin / secretary. Livewire-страница со списком автозакрытых (filter: `assigned_user_id IS NULL` + `status=ClosedLost` + `closed_lost_reason=ParserNoContent`), отображает LLM verdict / confidence / reasoning из state_change.payload. Кнопка «↻ Восстановить» переводит ClosedLost → Pending + AssignmentService::autoAssign, event=`manual_restore_auto_closed`.
- ✅ **Фильтр «Нераспределённые» теперь учитывает только реально открытые заявки** (`43538f2`). До: `assigned_user_id IS NULL` без фильтра по статусу — 78 уже-закрытых системой заявок (ClosedLost + parser_no_content) подсвечивались как «нераспределённые», хотя их не нужно никому назначать. Теперь и Pool query, и counter в nav-bar фильтруют через `RequestStatus::isTerminal() = false` — terminal-статусы видны только в отдельном пуле «Автозакрытые».
- ✅ **500 на /dashboard/requests/auto-closed** (`6344717`). Route `/dashboard/requests/{request}` определён ВЫШЕ статичного `auto-closed` → Laravel матчил wildcard, Postgres падал на `WHERE id = 'auto-closed'` (invalid integer syntax). Фикс: статичный роут объявлен ПЕРЕД `{request}`-биндингом, плюс abort_unless внутри для проверки роли head_of_sales/director/admin/secretary.
- ✅ **Размещение пункта «Автозакрытые» в UI** (эволюция, `df75f65` → `6344717` → `a0bfb1a` → `b3f9929`). Прошёл через 3 итерации: (1) топбар с amber-badge — отвергнут «не нужно в верхнем меню»; (2) левый rail с иконкой ↺ и badge — отвергнут «не в рейл»; (3) **финальное место** — внутри серого sidebar страницы «Заявки», в самом низу под секцией «Сохранённые виды», после `border-t` разделителя. Counter `totals['auto_closed']` за 30 дней передаётся из Pool.php. Видно только привилегированным ролям. **Грабли:** `mt-auto` НЕ работает в `<aside class="overflow-y-auto">` — прижимает к низу прокручиваемого контента, а не видимой области; используем простой `mt-3 border-t`.

### Закрыто 2026-05-28

- ✅ **Sender Blocklist** (полная фича) — модель + сервис + врезка ДО LLM + CRUD `/dashboard/sender-blocklist` + ClosedLostReason::Spam в карточке заявки + гайды. См. секцию MEMORY § «Sender Blocklist (стоп-лист отправителей, 2026-05-28)».
- ✅ **nginx 403 на `/docs/`** — убрал `$uri/` из `try_files` (физическая папка `public/docs/screenshots/` блокировала Laravel-роут).
- ✅ **Support ticket 500 на вложении** — `getSize()/getMimeType()` ПОСЛЕ `storeAs()` падал (Livewire `TemporaryUploadedFile` теряет связь). Read metadata BEFORE storeAs().
- ✅ **`<select>` на `/dashboard/mail` обрезка букв** — `@tailwindcss/forms` `padding` + `line-height` на `h-[26px]`. Base CSS `padding-y: 0 !important; line-height: 1 !important`. (3 ложных шага по color/contrast — урок: «обрезано» ≠ «бледно»).
- ✅ **«← К списку» терял фильтры/пагинацию** — Alpine `x-init` с `document.referrer` перезаписывает href, URL-state уже синхронизирован Livewire `#[Url]`.
- ✅ **M-2026-2102 не парсилась** — 4-слойный фикс: add-item-form в empty-state, `parsing_meta.reparse_dispatched_at` для feedback на non-Pending, whitelist «Паллета траволатора» в `ParseItemsUnifiedPrompt`, симметричный whitelist в `RequestItemParsingService::isServiceItem()`. **Двойной слой фильтрации — править оба.**
- ✅ **Catalog search не находил в description/comment** — расширил code-token + trigram на `lower(description)/lower(comment)` через GIN trgm индексы. Запрос «B157AAUX01» теперь находит замены в comment'ах. Vector embedding не трогал (35K эмбеддингов перегенерять не стал).
- ✅ **`wire:click.self` закрывал модалки при drag-select текста** — заменил на `wire:mousedown.self` в 12 модалках. Click срабатывает на общем предке; mousedown — только на точке нажатия.
- ✅ **Pool: дата + время** в колонке кода заявки (`d.m.y H:i`).
- ✅ **Production incident: info@ stuck >1ч** — `ResolvePendingChunkJob` зацикливался по PCNTL timeout race + UUID-collision в `failed_jobs`. **Архитектурный фикс:** три очереди (`mail-sync`, `default`, `catalog-resolve`), supervisor `--queue=mail-sync,default,catalog-resolve`. Job-классы: queue через `$this->onQueue()` в `__construct` (НЕ class-level — PHP 8 trait composition Fatal). `ResolvePendingChunkJob` timeout 300→600, ShouldBeUnique, failOnTimeout. См. MEMORY § «Queue topology (2026-05-28 post-mortem)».
- ✅ **Manager workflow.md обновлён** — преамбула + расширены шаги (⋮ меню / Похожие из каталога / per-item уточнения / полноценный КП-flow / счёт с напоминаниями) + 11 свежих скринов.
- ✅ **Dashboard: timeseries chart** — поток заявок по дням с тремя сериями (personal/shared/total), inline SVG, без npm-deps.
- ✅ **10 свежих граблей в MEMORY § «Известные грабли»** — PHP 8 trait composition, worker class cache, не лупить supervisorctl restart, двойной слой фильтрации, PCNTL timeout race, shell quoting, click.self vs mousedown.self, forms+h-[26px] обрезка, TemporaryUploadedFile после storeAs, document.referrer возврат.

**Открыто:**

- ⏳ **Корневая причина `ResolvePendingChunkJob` timeout** — после архитектурного фикса инцидент изолирован, но цикл может вернуться. Spawn-task создан. Нужно понять: медленный LLM? n+1 в matchOrResolve? memory leak на cursor? Либо явный `delete()` row при битых входах.
- ⏳ **Cron `queue:prune-failed --hours=72`** — раз в сутки.
- ⏳ **Health-check `queue:size-check`** + telegram-alert на jobs > 200 / reserved > 30 мин.
- ⏳ **«← К списку»: scroll position** через sessionStorage (referrer даёт URL, но не якорь).
- ⏳ **Старые скрины** в `public/docs/screenshots/` (`requests-pool.png`, `request-detail.png`, `request-positions.png`, `dashboard.png`) — если нигде не используются, удалить.
- ⏳ **`mzcorp-worker.conf` синхронизация репо vs прод** — сейчас вручную, при следующем worker-deploy перепроверить.

### Закрыто 2026-05-27

- ✅ **Cross-mailbox дубли — установлен корень, не наш код** (`0993e83`, `80d156a`, `5015685`, `478e964`, `9b961d2`, `aec05dd`, `feb5c99`). Жалоба «M-2026-2049 ушла Якубовичу хотя адресована Ежову» вскрыла три независимых механизма:

  **(1) Регресс `19e2957` — наш код, фикс А (`0993e83`).** Коммит вчера добавил «доставку reply'я в личный ящик assigned» для всех inbound. Если reply пришёл в личный ящик менеджера A, а linker связал его с чужой Request (assigned=B) — мы APPEND'или копию в personal B. Оба видели одно письмо в своих Yandex. Guard в `MailDeliverToManagerService::deliver`: если origin.mailbox.type=personal и owner≠target → skip + audit reason=`origin_in_another_personal`. Для shared→personal продолжает работать (исходный M-2026-1928). Сегодня сэкономил бы 6 из 8 personal-to-personal дублей.

  **(2) Orphan-mailboxes — наш код, фикс мощнее (`5015685`).** 22.05 архивированы 3 тестовых user (#5/6/7 — man1/man2/man3), но их personal-mailboxes остались `is_active=true`. Sync продолжал тянуть, а `mail:backfill-manager-deliveries` каждые 30 минут видел active Request с `assigned_user_id=archived` → диспатчил deliver → APPEND production-писем в тестовые inboxes. За неделю накопилось **246 писем** в orphan-ящиках (man1=94, man3=81, man2=71). Три слоя защиты: `UserObserver` автоматически выставляет `is_active=false` всем personal-mailboxes user'а при archive; `MailDeliverToManagerService::deliver` гард `manager.archived_at !== null` → skip с audit reason=`manager_archived`; `MailBackfillManagerDeliveriesCommand` фильтрует через `whereHas('assignedUser', whereNull('archived_at'))`. Выключил mb#2/3/5 руками; reassigned 3 застрявшие Request (M-2026-0157 → Головнев, M-2026-0244 → Васюхно, M-2026-1451 → Якубович). MEMORY-урок: при работе с архивированием пользователей не забывать про их mailboxes.

  **(3) Yandex 360 forwarding rules — внешнее, не наш код.** `X-Yandex-Forward` header в файлах копий содержит **стабильные хэши** правил Yandex. Hash `ff167603859a021d361053c2405134c7` — company-wide rule (KSMG-scan); `77e7761423be1d20079e9c6920d97607` — distribution-group `sales-team@myzip.ru` куда подписаны все менеджеры; `51aa94cff30c49070a0a4b533e5c443a` — связь Ezhov↔Yakubovich; `582fafde415ed08adaa61c77f44f21ef` — Rumiantsev→Golovnev. CLI `mail:audit-historical-duplicates --from --to --exclude-from --csv` через IMAP идёт по active personal-mailbox, fetch'ит Message-ID за период (без БД!), cross-сравнивает пары. Прогон за 15-21.05 (до подключения боевых ящиков 22.05) — **19 cross-mailbox дублей физически в IMAP-INBOX'ах, 100% с X-Yandex-Forward**. После фильтра директорских BCC (Роденков/Боев) — 8 «настоящих» дублей: 4 от distribution-group `sales-team@myzip.ru`, 2 от multi-recipient клиентов (PaulSchaab напрямую ставит обоих в To), 2 от нестандартных rules конкретных менеджеров. **Полное доказательство: дубли существовали в Yandex годами**, MyLift подключив ящики 22.05 впервые сделал их видимыми. CSV-выгрузка для РОПа: `mail:audit-historical-duplicates ... --csv=path.csv` (UTF-8 BOM + decoded MIME headers).

  **Trace `mail_append_audit` (`80d156a`).** Audit-таблица: каждая попытка APPEND фиксируется row'ом со status pending→success/skipped/failed. Любое исключение между appendMessage() и save artifact ловится в catch с записью status=failed. Через 24-48ч cross-check с появляющимися cross_mailbox_copy_of пометками даст 100% видимость на возможные silent-failures нашего кода.

  **Грабли webklex для будущего:** для IMAP SEARCH с `whereSince`/`whereBefore` обязательно `$folder->select()` перед query (иначе «Empty response»). Дата только в формате RFC 3501 `DD-Mon-YYYY` (например `15-May-2026`), Carbon-объект через webklex `parse_date` может дать кривой формат. INTERNALDATE через webklex headers-only fetch не возвращается, использовать `Date:` header.

- ✅ **M-2026-1953: парсер unified + .doc/.docx + cross-article dedupe** (`f41f022`). Кейс «Цикин Василий, плата VQC»: фото + .doc → парсер пошёл по split path (structured_count=1), photo-vision увидел внутренний код шильдика `OA8522.5A/3874W7/81`, text-parser извлёк M22198 из .doc → 2 позиции вместо одной. Расширил unified path: `structuredAttachments` разделены на lightweight (`.doc/.docx`) и heavy (`.pdf/.xls/.xlsx`). Heavy блокируют unified (там tabular данные требуют document-extraction pipeline), lightweight — extract'ятся через `extractTextFromFile`, текст подмешивается в body перед `parseItemsUnified`. Параллельно crossArticleDedupe в split path: если позиции совпали по (name_lower + qty + invoice_index) и одна — M-SKU (`M\d{4,}` с поддержкой кириллической М через str_replace), а другая — внутренний код производителя, merge non-M-SKU в M-SKU вариант (catalog-matchable). Проверено re-парсингом #5469 → одна позиция M22198. Linker omts@ekolift.ru → splice 3 разных заказов в одну Request оставлен «не трогаем, только warning» (по выбору пользователя, бизнес-граница — клиент использует Reply для разных товаров).

- ✅ **Phase 6: Автоматические уведомления клиенту** (`48157bf`, `f01ee47`, `7e64d33`, `78faec4`, `7bf456e`). 6 типов: `OrderReceived` (sync hook в AssignmentService::autoAssign, только для нового Request: inheritance_parent_id IS NULL И origin.in_reply_to IS NULL), `ClarificationReminder` / `QuoteFollowupReminder` / `InvoiceExpiringSoon` / `InvoiceExpired` (cron `notifications:dispatch-client` hourly), `OrderClosedLost` (sync hook в RequestStateService::transitionTo при переходе в ClosedLost). Архитектура: Enum ClientNotificationType + таблицы `client_notification_templates` (1 row на тип) + `client_notifications_sent` (uniq(request_id, type, scope_key) для идемпотентности). ClientNotificationService: Markdown → HTML через league/commonmark → wrap в `resources/views/emails/notification_wrap.blade.php` (общий MyZip-шаблон с шапкой/подписью) → reply в тред заявки через EmailDraftService::createReply + OutgoingMailSender::sendDraft. Sender — ящик в который пришло origin-письмо (OutgoingMailboxResolver). Placeholder'ы рендерятся через preg_replace_callback по `{{ var }}`. Conditional `manager_intro`: для shared-mailbox (info@/mail@) → «Ответственный менеджер: **Имя** (email).», для personal → пустая строка (клиент уже знает кому пишет). Guard для OrderClosedLost: skip если `state_change.payload.detector_type === 'outbound_declined'` (менеджер уже написал клиенту отказ, не дублируем). UI: `/dashboard/settings` → подпункт «Уведомления клиенту» (вынесен из топ-меню в Settings). Список 6 типов с toggle + edit-form с превью на реальной заявке (iframe srcdoc). Все toggle'ы по умолчанию выключены — admin включает после ревью текстов. Cron register'нут в scheduler hourly. Seeder через `firstOrCreate` — повторный run не перетирает admin-правки. Грабли: вложенное `{{ '{{ '.$key.' }}' }}` в Blade ломает парсер — через `@php $sample = '{{ '.$key.' }}'; @endphp` + `{{ $sample }}`. event-name в RequestStateChange ограничен varchar(32) — длинные снапшот-имена падают.

- ✅ **Fix Б: жёсткое правило «личный ящик X → Request у X» для reply'ев** (`c317c52`). Раньше sticky direct_mailbox работало ТОЛЬКО при создании новой Request (AssignmentService Level 0). Для reply'ев, привязанных linker'ом к существующей Request с другим assigned, правило не применялось. Кейс M-2026-1651/msg#5298 (greenliftsnab → Курзаев → APPEND копии Головневу): клиент написал reply лично Курзаеву, linker по In-Reply-To нашёл Request у Головнева, мы APPEND'или копию. Новый `MailRouter::applyStickyDirectMailboxOnReply` после InboundReplyLinker::tryLink: если linked, origin Personal, owner active+available, текущий assigned ≠ owner → reassign на owner через ReassignService (с null actor + reason='sticky_direct_mailbox_on_reply'). ReassignService::reassign теперь поддерживает nullable $by (для system-actor), reason prefix 'manual_reassign' / 'system_reassign'.

- ✅ **Fix В: scope InboundReplyLinker для personal-mailbox по пулу менеджера** (`efd5206`). Раньше linker глобально искал Request по In-Reply-To/References/subject_code/external_code/from_email_open_request/AI — независимо от того, в какой ящик пришло письмо. Это создавало false-link на чужие заявки. Новый `resolveOwnerScopeUserId(message)`: для personal-mailbox возвращает owner_user_id, для shared — null (нет сужения). `isRequestInScope(request, userId)`: текущий assigned ИЛИ запись в request_assignments. Применяется к Level 1 (in_reply_to/references — happy path + terminal-parent ветка) и финально через scope-check перед возвратом (покрывает Level 2-5). Если ни один уровень не нашёл Request в scope → linker возвращает null → IncomingMailProcessor создаёт новую Request у этого менеджера через sticky direct_mailbox. Fix Б остаётся как safety net (если AI clarifier Level 5 промахнётся). Foundation §1.5 теперь работает целостно: новая заявка ИЛИ reply в личный ящик X → Request у X.

- ✅ **M-2026-2102: differentiates decline/correction + auto-reanimate при outbound на closed_lost** (`4ee37da`). Кейс: клиент в ответ на КП написал «Такая не подходит, у нас общая длина примерно 928мм, а ваша 1128» — это корректировка спецификации, не отказ. LLM-классификатор пометил как `inbound_decline` conf=0.9 → auto-applied → заявка закрылась. Затем менеджер прислал новое КП → AiDecision outbound_quotation_full → transitionTo Quoted на closed_lost → state machine отказала → Dismissed → заявка осталась закрытой. (1) `ClassifyClientResponsePrompt` расширен раздел «ЭТО НЕ decline»: технический mismatch + клиент даёт свои параметры («у нас 928, а ваша 1128»), запрос альтернативы («есть подешевле?»), указание на ошибку в КП («прислали не то»). Правило отличия: «клиент даёт нам ШАНС → negotiation/clarification → unclear; декларативный отказ без альтернативы → decline». (2) `AiDecisionService::apply` — если Request в ClosedLost И тип = outbound (quotation/invoice/clarification, НЕ outbound_declined) → перед transitionTo делает `reanimate(reassessAssignee=false, event='auto_reanimate_for_outbound')`. Менеджер сам прислал КП — владелец остаётся. Восстановил руками M-2026-2102: closed_lost → InProgress → Quoted, reanimated_count=1.

- ✅ **Mail recovery после OpenAI circuit breaker** (`c62b72c`). Кейс 27.05: OpenAI вернул 503 в 15:12, `MailCategoryClassifier` открыл circuit breaker (30 мин cooldown). При sync info@ в 15:25 MailRouter::route → categorize: skip (breaker open) → category=null. IncomingMailProcessor гейтится по `category===ClientRequest` → пропуск, 7 заявок не созданы. В 15:45 фоновый cron `mail:categorize` (отдельный job, не sync-pipeline) дозаполнил category=client_request, но НЕ запустил повторно IncomingMailProcessor. Письма повисли как orphan «категоризованы, но без Request». Менеджеры писем не видели в Pool. Фикс: MailCategorizeCommand::processBulk после категоризации, если стало client_request/thread_reply и related_request_id IS NULL → InboundReplyLinker::tryLink, иначе IncomingMailProcessor::processIfRequest. Новый флаг `--include-orphans` для bulk-query: захватывает уже-категоризованные orphan'ы (для backfill после downtime'ов OpenAI). Scheduler обновлён: `mail:categorize --all --limit=50 --include-orphans` каждые 5 минут. Восстановлены руками 7 заявок: M-2026-2189..2195.

- ✅ **Auto-close Paid → ClosedWon** (`10796f2`). Кейс M-2026-1496: статус Paid, менеджеры воспринимают «оплачено» = «успех», но в state machine это разные статусы. Заявки висели в Paid вечно. Hook в RequestStateService::transitionTo: после успешного перехода в Paid сразу вызывает transitionTo(ClosedWon, event='auto_close_won_after_paid'). Guard от рекурсии: `$to === Paid && $request->status === Paid`. Audit-trail сохраняет цепочку Invoiced→Paid→ClosedWon в request_state_changes. M-2026-1496 закрыт вручную, бэкфилл других висящих не понадобился (был только этот). Грабли: `request_state_changes.event` varchar(32), длинные snapshot-имена событий падают (например `manual_backfill_paid_to_closed_won` = 33 символа).

### Текущие открытые вопросы
- **M-2026-1494 (Ангелина) — Pending без позиций** (открыт 2026-05-24). Тело «Эскалатор КОНЕ зав 40160521» — техконтекст без позиций для заказа. Парсер вернул 0 items → Request в Pending, autoAssign не запустился, менеджер не видит в Pool. Решение: либо принудительно показывать в Pool Pending заявки от клиентов с pseudo-position «уточнить у клиента», либо эскалация менеджеру по email-notification.
- **AttentionReason taxonomy review** — пользователь считает что валидных причин внимания только две: `PostponedResume` и `SlaBreach`. Остальные (`AwaitingClient`, `AwaitingSupplier`, `QuoteFollowupDue`, `InvoiceFollowupDue`, `PartialQuoteOverdue`) — не повод подсвечивать заявку красным, скорее informational. Требует обсуждения дизайна перед изменением enum + AttentionService.
- **{position_number} placeholder leaked literal** — strtr-safety net стоит в ClarificationPanel.php. Нужна верификация на следующей живой отправке.
- **ClientReplied attention reason** — обсуждаемая идея новой причины, когда клиент ответил в треде на ClarificationBatch. Уже сделано (AttentionReason::ClientReplied + onClientReplied hook в MailRouter). Можно закрывать.
- **«Предложенные уточнения» от outbound** — на M-2026-0759 показывалась плашка enrichment_suggestions после отправки КП менеджером. Tinker показал пустой `enrichment_suggestions[]` — возможно уже applied/dismissed. Диагностику отложили. Идея на будущее: после отправки КП auto-dismiss'ить pending enrichment-suggestions (менеджер зафиксировал состав в коммерческом).
- **Парсинг M-артикулов из исходящего КП/счёта** — обсуждалось как отдельная фича. Менеджер прикладывает PDF с КП → distill M-SKU из контента и привязывать к позициям. Не реализовано, требует Vision/PDF-extract.
- **Manual override для semi-auto статусов** — после удаления кнопок «📨 КП отправлено» / «❓ Жду уточнение клиента» нет ручного fallback'а если detector промахнулся. Можно добавить «⋮ Изменить статус → ...» dropdown как safety net. Пока ждём оценки real-world precision detector'а.
- ✅ **InboundIntentClassifier auto-apply кнопок в action-panel** — закрыт 2026-05-19. Три кнопки («📑 Клиент на согласовании», «⏰ Клиент отложил», «💵 Запросил счёт») удалены из `resources/views/livewire/requests/detail.blade.php` (бывшие строки 700-716). Все три intent-перехода идут через AiDecision-плашку. PostponedUntil ставится с `suggested_resume_date` из payload без UI-диалога (`AiDecisionService::apply` line 155-161). ClosedLost оставлен как manual safety-net (требует reason из taxonomy, AI вытаскивает `suggested_closed_lost_reason` для one-click apply). Если AI промахнётся (under_review/postponed/invoice misclassified) — менеджер ждёт следующего письма от клиента / обращается к РОПу. **Потенциальный fallback** — пункт TODO «Manual override для semi-auto статусов» (dropdown «⋮ Изменить статус → …») если real-world precision окажется ниже ожиданий.
- **«Оплачено»/«Счёт отправлен» автодетекция** — `OutboundDocumentDetector` ловит invoice по filename/keyword (рудиментарно). Нет inbound-детектора оплаты (банковская выписка / клиент пишет «оплатили»). Пока manual. **Частично решено 2026-05-27:** при ручной отметке Paid (бухгалтерия) — auto-transition в ClosedWon (commit `10796f2`).
- **Снижение OpenAI circuit-breaker cooldown** — сейчас 30 минут open после первого 5xx. Это безопасно для каскадных failures, но в случае одиночного 503 (как 27.05 15:12) — все письма за 30 мин висят без категоризации. Решено бэкфилл-механизмом (`mail:categorize --include-orphans` каждые 5 мин), но можно подумать о retry-with-backoff внутри одного job'а.
- **Отдельные threshold'ы per-DetectorType** — общий `confidence_threshold` 0.85, но для `inbound_decline` (закрытие заявки) хочется выше (0.97+) или вообще отключить auto-apply по умолчанию. После решения по выбору промпта (decline vs correction) — посмотреть на real-world precision.

## Что готово (инфраструктура — Фаза 0)

- **SSH-доступ для Claude:** `ssh root@84.54.31.54` работает без пароля. Используется для прямого деплоя через `git pull` + `php artisan`, scp файлов при выпадении GitHub, диагностики через одноразовые `php` скрипты в `/tmp/`. **При деплое: `git push` → `ssh ... git pull --ff-only` → `php artisan queue:restart` + опционально `view:clear`.** Резервный путь при GitHub-сбое: `scp app/.../File.php root@84.54.31.54:/var/www/mzcorp/...` напрямую, потом `git stash + pull` когда GitHub оживёт.
  - **Два deploy-ключа в `/root/.ssh/authorized_keys`** (используются с разных рабочих мест, оба валидны, comment у обоих `claude-mzcorp-deploy@boag`):
    1. `ssh-ed25519 …nMSw` — первое рабочее место (ключ от 2026-05-24).
    2. `ssh-ed25519 …NQ7P` (отпечаток `SHA256:AEfFBPaQrPLQK4ELe21ev+EbedBPsLRKBHMHIynsTec`) — второе рабочее место (2026-06-02). Локально: приватный ключ `~/.ssh/id_ed25519_mzcorp`, алиас `mzcorp` в `~/.ssh/config` → `ssh mzcorp '...'`.
  - **Грабли при добавлении ключа (2026-06-02):** beget-access-key в `authorized_keys` был БЕЗ завершающего `\n`, поэтому `echo 'ключ' >> authorized_keys` склеил новый ключ в одну строку с beget — публичный ключ не парсился, auth падал с `Permission denied (publickey)`, хотя ключ корректно offer-ился клиентом. Лечение: `sed -i 's/<конец предыдущей строки><начало нового ключа>/…\n…/'`, проверка `cat -n authorized_keys` (каждый ключ = своя строка). На будущее: добавлять ключ через `printf '\n%s\n' 'ключ' >>` или проверять trailing newline.
- Laravel 12 skeleton развёрнут в `/var/www/mzcorp` на VPS Beget (`84.54.31.54`, домен `mzcorp.ru`).
- **VPS resources (2026-05-24):** 3.8 GB RAM + 2 GB swap (`vm.swappiness=10`). Изначально было 1.9 GB без swap → OOM-killer срабатывал на heavy jobs (Vision на PDF, KB resolve, catalog import). Upgrade сделан через Beget панель.
- **Supervisor `mzcorp-worker`** (2026-05-24 финальная конфигурация): `numprocs=4`, `--memory=600`, `--max-jobs=200`, `--max-time=3600`, `--sleep=3`, `--tries=3`, `stopwaitsecs=60`, лог-ротация `stdout_logfile_maxbytes=50MB stdout_logfile_backups=5`. SSH key для root установлен из `~/.ssh/id_ed25519.pub` от рабочей машины (`claude-mzcorp-deploy@boag`).
- **nginx gzip** включён для `application/json` + `text/css` + `application/javascript` + xml + text/xml (2026-05-24). Livewire payload сжимается 5-10× (252 КБ → 7-18 КБ).
- HTTPS работает: Let's Encrypt до 2026-08-04, redirect 80→443, auto-renew.
- PostgreSQL 16.4 на Beget Cloud DB (`10.19.0.2`, БД `MyLift`, SSL=prefer через приватку).
- pgvector 0.7.4 включён в whitelist (запросили у Beget — выполнено), sanity-test проходит.
- Базовые миграции применены (`users`, `cache`, `jobs`, `enable_pgvector_extension`).
- nginx vhost `mzcorp.ru` активирован (`deploy/nginx/mzcorp.conf`).
- Supervisor: воркер `mzcorp-worker` RUNNING, autostart, autorestart.
- Cron под `www-data`: `* * * * * php artisan schedule:run`.
- PHP 8.3.6 + расширения: `pdo_pgsql`, `mbstring`, `xml`, `bcmath`, `curl`, `intl`, `zip`, `openssl`.
- Laravel прод-кеши: `config:cache`, `route:cache`, `view:cache` прогреты.
- `psysh` HOME для www-data — `/var/www/.config/psysh` (для tinker).

### Repo

- GitHub: `https://github.com/markyel/mzcorp.git`, branch `main`.
- На VPS origin переключён на HTTPS (`git remote set-url`), pull работает без SSH-ключей.
- 6 коммитов в `main`, последний — `Add MyLift CRM Design System`.

### Дизайн-система

- Лежит в `design/` (распаковано из `MyLift_Design_System.zip`).
- Источник tokens — `design/colors_and_type.css` (brand red `#D32027`, 9-step neutrals, 4 status hues).
- HTML-макеты экранов — `design/ui_kits/crm/01-pool.html`, `02-dashboard.html`, `03-requests.html`, `04-request-detail.html`.
- Voice/copywriting/content fundamentals — `design/README.md`.
- Foundation snapshot — `design/uploads/MyLift_Foundation.md` (источник истины по продукту).

### Деплой-процесс

```bash
cd /var/www/mzcorp
sudo -u www-data git pull --ff-only origin main
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm ci
sudo -u www-data npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo supervisorctl restart mzcorp-worker:*
```

> ⚠️ `php artisan config:cache route:cache view:cache` одной командой **не
> работает** — artisan не принимает доп. аргументы и ругается «No arguments
> expected for `config:cache` command, got `route:cache`». Запускать тремя
> отдельными вызовами или через `&&`.

На VPS установлены: PHP 8.3.6 + расширения, Composer 2.x, Node.js 20 LTS (NodeSource), npm. HOME для `www-data` под кеши: `/var/www/.config/psysh`, `/var/www/.cache/composer`, `/var/www/.npm`.

Ещё не оформлено в `deploy/deploy.sh` — это TODO.

## Sender Blocklist (стоп-лист отправителей, 2026-05-28)

Фича добавлена по запросу: заявки от мусорных отправителей (рассылки, боты) не должны создаваться. Старая концепция Foundation §1.5 (`from_domain ∈ blacklist` как label_only) доведена до полноценной сущности.

**Файлы:**
- `app/Enums/BlocklistEntryType.php` (email | domain), `BlocklistEntrySource.php` (manual | from_request)
- `app/Enums/ClosedLostReason.php` — добавлен case `Spam`
- `database/migrations/2026_05_28_150000_create_sender_blocklist_table.php` (unique по (type, normalized_value), FK на users + requests с nullOnDelete)
- `app/Models/SenderBlocklistEntry.php` (table: `sender_blocklist`)
- `app/Services/Mail/SenderBlocklistService.php` — `isBlocked`, `block`, `unblock`, `bulkBlock`, `normalize*` (публичные для UI/тестов)
- `app/Services/Mail/MailRouter.php` — врезка ДО LLM-категоризатора (после loop-guard, перед cross-mailbox dedup)
- `app/Services/Mail/IncomingMailProcessor.php` — defense-in-depth (для cron `mail:create-requests` / `mail:categorize`, минующих MailRouter)
- `app/Livewire/SenderBlocklist/BlocklistIndex.php` + view → `/dashboard/sender-blocklist` (role:head_of_sales|director|admin)
- `app/Livewire/Requests/CloseLostDialog.php` — при reason=Spam radio «email/domain» с автозаполнением из `request->emailMessage->from_email`
- `resources/views/layouts/navigation.blade.php` — пункт «Стоп-лист» в топбаре
- Гайды: `resources/docs/rop/sender-blocklist.md`, `resources/docs/manager/spam-close.md`

**Семантика матчинга:**
- email — точное совпадение нормализованных адресов. Lowercase + trim + срез plus-addressing (`foo+x@a.ru` → `foo@a.ru`). Спамеры обходят простой блок через `+` — нормализация съедает.
- domain — суффикс-матч с разделителем `.`. `paulschaab.de` ловит `mail.paulschaab.de`, но НЕ `paulschaab.de.evil.com`. Покрыто тестом `test_blocks_by_domain_with_subdomain_suffix_match`.

**Решения по UX (зафиксировано):**
- **Только будущие письма.** Добавление в стоп-лист НЕ закрывает ретроактивно существующие открытые заявки от того же отправителя. Безопаснее — не позволяет одной ошибкой обнулить пачку реальных работ.
- **Manager → from_request, РОП/admin → manual.** Менеджер может закрыть СВОЮ заявку как «Спам» и автоматически попасть в стоп-лист (source=from_request, audit by_user). CRUD-таблица доступна только head_of_sales/director/admin.
- **Domain matching = exact + subdomains.** Не настраивается per-entry. Если нужен только конкретный поддомен — заводить его как отдельную domain-запись.

**Аудит:**
- `routed_mails.action_taken = 'blocklist_skipped'` для каждого отбитого письма (новое значение, не enum'нутое — string).
- `email_messages.category = irrelevant`, `category_reasoning = "Blocked by sender_blocklist (from=...)"` — видно в `/dashboard/mail-review`.
- `sender_blocklist.hit_count` + `last_hit_at` — счётчик + время последнего срабатывания, отображается в таблице.
- При закрытии заявки как Spam через CloseLostDialog: `request_state_changes.payload` содержит `closed_lost_reason=spam`; `sender_blocklist` row создаётся с `added_from_request_id=<request_id>` и `source=from_request`.

**Тесты:**
- `tests/Unit/Services/Mail/SenderBlocklistNormalizationTest.php` — 8 кейсов нормализации БЕЗ БД (PHPUnit\TestCase, не Laravel\TestCase). Прогоняется локально без коннекта к Postgres.
- `tests/Feature/Services/Mail/SenderBlocklistServiceTest.php` — 7 интеграционных (RefreshDatabase): exact email, subdomain suffix match, idempotency, hit_counter, unblock, bulkBlock, normalize persistence.

**Грабли при имплементации:**
- В `CloseLostDialog::save()` — добавление в blocklist делается ПОСЛЕ `transitionTo()`, чтобы не закрыть заявку, если blocklist отказался принять. Но `block()` обёрнут try-catch — если blocklist упадёт уже после успешного транзита, статус останется ClosedLost+Spam, в session flash появится предупреждение «не удалось — добавьте вручную». Не валим UX-flow из-за write-conflict на счётчике.
- Plus-addressing срезается при нормализации — `foo+anything@spam.com` матчится записью `foo@spam.com`. Спамеры используют `+` для обхода простых блокировок, мы этот трюк нейтрализуем.
- `domain` запись `paulschaab.de` действует на ВСЕ поддомены. Если нужно блокировать только корневой — никак, придётся завести email-записи по каждому. Это намеренно — упрощение MVP.

## Queue topology (2026-05-28 post-mortem)

**Инцидент:** входящая почта info@ не обрабатывалась >1 часа. 4 воркера на одной общей очереди `default` зациклились на `ResolvePendingChunkJob` (catalog A→B→C резолв), забили очередь, sync IMAP-job'ы ждали.

**Цепочка событий:**
1. `ResolvePendingChunkJob` обрабатывает 50 items × ~3с (pgvector HNSW + LLM rerank) = 150с в норме. В плохую минуту (OpenAI лагает) ≥ 300с timeout.
2. PCNTL alarm стреляет таймаут, Laravel пытается mark job as failed и записать в `failed_jobs`. В логах виден race: `ResolvePendingChunkJob done` + `MaxAttemptsExceeded` в одну секунду.
3. Мои частые `supervisorctl restart mzcorp-worker:*` (после каждого деплоя) — оставляли некоторые jobs в reserved-limbo. После рестарта другой воркер подбирал reserved-by-timeout, handle() запускался ВТОРОЙ раз с тем же `jobs.uuid`.
4. Второй fail → INSERT в `failed_jobs` с UUID, который уже там есть → `failed_jobs_uuid_unique` violation → exception throws везде, видна как «OpenAI chat failed» (наш logger обернул PDO-error в OpenAI-контексте).
5. На каждый цикл воркер тратил ~5 мин (timeout + failure handling), 4 воркера × 5 мин = backlog. 64 SyncMailboxFolderJob накопились без обработки.

**Архитектурный фикс — три очереди:**

| Queue | Назначение | Кто туда пишет |
|---|---|---|
| `mail-sync` | IMAP SyncMailboxFolderJob (time-critical) | `SyncMailboxFolderJob::$queue` |
| `default` | всё остальное (Parse, Deliver, Route, KB, OutgoingQuote, нотификации, AI) | implicit fallback |
| `catalog-resolve` | тяжёлая catalog/LLM/pgvector работа | `ResolvePendingChunkJob`, `ResolvePendingFromCatalogJob` |

Supervisor (все 4 воркера): `--queue=mail-sync,default,catalog-resolve`. Порядок ВАЖЕН — Laravel queue:work обрабатывает очереди слева направо, не уходит на следующую пока в текущей есть jobs. Mail-sync гарантированно не голодает даже если catalog-resolve забит на часы.

Конфиг в репо: `deploy/supervisor/mzcorp-worker.conf`. После git pull на проде: `cp deploy/supervisor/mzcorp-worker.conf /etc/supervisor/conf.d/ && sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl restart mzcorp-worker:*`.

**Дополнительные защиты в ResolvePendingChunkJob:**
- `timeout: 300 → 600` — запас на медленный LLM/pgvector.
- `ShouldBeUnique` по md5 sorted itemIds, окно 5 мин — race при reserved-reaquire отказывается.
- `failOnTimeout = true` — Laravel явно помечает failed, не оставляет reserved.

**Что нельзя делать:**
- Перезапускать воркеров `supervisorctl restart` подряд, не дав текущим job'ам graceful exit. Используйте `php artisan queue:restart` — он отправляет soft-signal «закончи текущий job и выйди», новый воркер supervisor поднимет сам. Особенно важно для in-flight catalog-resolve (5-10 мин).
- Объединять очереди обратно в одну `default`. Класс этого инцидента вернётся.

**Что хорошо бы добавить позже:**
- Cron-команда `queue:prune-failed --hours=72` (раз в день) — чтобы failed_jobs не рос.
- Health-check команда `queue:size-check` с алертом если jobs > 200 или reserved > 30 минут.

## Открытые вопросы (из Foundation)

### Критические — ждут ответа клиента

1. **Способ доступа к 1С для чтения каталога** (REST API / read replica / CSV / OData / webhook). Определит реализацию `CatalogSyncService`. **Не блокирует Фазу 1.**
2. **Способ экспорта в 1С** — желательно зеркальный способу чтения. **Не блокирует Фазу 1.**
3. **Маппинг кода заявки** (`internal_code` ↔ `corp_external_code`) — как 1С возвращает свой код.
4. **Куда приходят ответы поставщиков на refresh** — `purchase@…` или адреса менеджеров. **Не блокирует Фазу 1.**

### Технические — для Фазы 1

5. **Аутентификация ящиков** — на MVP App passwords. Менеджер генерирует app-password в настройках Yandex и передаёт админу. OAuth — на потом.
6. ~~**Формат IMAP-меток в Yandex 360**~~ — **закрыт 2026-05-07.** Эмпирически установлено: IMAP custom keywords (`STORE +FLAGS`) хранятся на сервере, но Yandex web UI их не показывает; вдобавок Yandex IMAP сбрасывает custom-keywords после `CLOSE/SELECT` (известный баг сервера). Yandex 360 REST API endpoints для меток нет. **Workaround:** вместо меток используем подпапки на root-уровне namespace — `MZ\|{Lastname}` (см. `MailFolderRouter`). Подпапки создаются и видны в Yandex web UI штатно. Foundation §1.6 «секретарь видит распределение в Yandex UI» удовлетворён через folder hierarchy.
7. **Полный набор правил маршрутизации** — какие категории помимо очевидных (рекламации, бухгалтерия) команда хочет различать. **Собираем примеры реальных писем в начале Фазы 1.**

## Распределение заявок — гладкий микс 20/40/40 (2026-06-09, коммит 27ac310)

`AssignmentService::pickBalancedManager` (бывш. `pickWeightedLeastLoadedManager`). Старый строгий least-loaded давал осцилляцию «закрыл всё → стал отстающим → лавина 40/день, у другого 2». Новый — **гладкая пропорциональная раздача** по миксу трёх нормированных сигналов (config `services.assignment.distribution.mix`, по ТЗ заказчика):
- **weight 0.2** — поровну по `load_weight` (floor: никто не в нуле);
- **load 0.4** — по текущей ВЗВЕШЕННОЙ нагрузке. Вес статуса — `services.assignment.status_load_weights` (new/assigned/in_progress=1.0, quoted=0.5, invoiced/paid=0.25 и т.д.): менеджер НЕ штрафуется за доведённые до КП/счёта сделки (кейс Якубовича — 145 open, но ~119 «припарковано» quoted/invoiced → раньше выглядел перегруженным и получал 0);
- **speed 0.4** — по скорости закрытия (`closed_won`+`closed_lost`, `closed_at` за `period_days`=14; новичкам `base_close_rate`×quota). Быстрые закрывальщики получают больше.

Каждый компонент → доля (сумма=1) → `targetWeight = 0.2·flat + 0.4·load + 0.4·speed`. Раздача через smooth-WRR: заявку получает менеджер с min(`получено_сегодня` / `targetWeight`), tiebreak — больший targetWeight, затем LRU. **Детерминированно** (не weighted-random — заказчик ранее браковал разброс рулетки 14 vs 4). Reason в `request_assignments`: `auto_round_robin:{"closes":..,"today":..,"tw":..}`. Симуляция на проде: weight-100 менеджеры 10–14/день (было 0–24/2–32), Якубович 13 вместо 0. Все пороги/доли — в config (env override), при желании выносим в UI Настройки. Грабля: load_weight=10 (Агрызков) / 50 (Головнев) сильно режут долю — это ручной множитель РОПа, не баг.

**Агрегаторные адреса — мимо client-sticky (коммит a72080f, 2026-06-09):** `order@myzip.ru` (ящик веб-формы сайта) — за одним From стоят РАЗНЫЕ конечные клиенты, поэтому Level-2 client-sticky по нему сваливал все заявки одному (видели STICKY:client→Агрызков). `pickStickyByClientEmail` возвращает null для адресов из `config services.assignment.non_sticky_client_emails` (env `ASSIGNMENT_NON_STICKY_CLIENT_EMAILS`, дефолт `order@myzip.ru`) → round-robin. Catalog (L1)/text (L3) sticky продолжают работать. Кандидат на добавление: `order@liftway.store` (маркетплейс) — НЕ добавлен (есть LZ-REQ-привязка/TrustedPartnerOverride, обсудить отдельно).

## Известные грабли

- **Sent-папку ищем по special-use `\Sent`, НЕ по имени** (2026-06-09, кейс Dmitry.Rumiantsev id=12). У Yandex-ящика рядом с СИСТЕМНОЙ `Sent` (флаг `\Sent`, 59568 писем = реальные ручные отправки менеджера) живёт ПОЛЬЗОВАТЕЛЬСКАЯ папка `Отправленные` (MUTF-7 `&BB4EQgQ,BEAEMAQyBDsENQQ9BD0ESwQ1-`, БЕЗ `\Sent`, 15641 = только автоотбивки MyLift, которые мы туда же APPEND'им). `MailboxConnector::findSent` искал по имени, в списке кандидатов `Отправленные` стояла ПЕРВОЙ → синкали кастомную, реальную `Sent` не читали вовсе. Симптом заказчика: «фиксируются входящие + автоотбивки, ручные отправки менеджера через Yandex-клиент — нет». **Фикс (`2f6d383`):** `findSent` сначала `findFolderBySpecialUse($client,'Sent')` — сырой LIST `$conn->folders('','*')->validatedData()` → ищем path с флагом `\Sent`; имя — только фолбэк (`Sent` раньше `Отправленные`). `AppendToSentFolderJob` использует тот же резолвер → копии MyLift тоже уходят в реальную Sent. Глобально для всех ящиков; смена path Sent → новый `MailboxFolderState` (folder='Sent') → first-sync watermark (история не тащится). **Урок: имя папки ненадёжно (русские дубли, кастомные папки клиента) — всегда RFC 6154 special-use.** Диагностика: дамп `$client->getConnection()->folders('','*')->validatedData()` показывает flags по каждой папке.
- **Потеря бэклога синка: брали ХВОСТ среза, двигали watermark на max** (2026-06-09, `5ef29aa`). `SyncMailboxFolderJob::fetchAndPersist` при бэклоге >100 делал `array_slice($newUids,-100)` (новейшие 100), persist'ил их, но `last_uid_seen` двигал на max обработанного — пропущенные старые UID навсегда ниже watermark, не фетчатся. Триггер: смена UIDVALIDITY (`last_uid_seen=0` → весь фолдер как «бэклог»). **Фикс:** срез берёт ГОЛОВУ (`array_slice(...,0,100)`, oldest-first) — бэклог дренится по 100/заход без потерь; UIDVALIDITY-смена теперь ре-watermark на текущий max (как first-sync), а не `=0` (иначе массовый re-ingest 167k-папки info@ + LLM). Это латентная бага, НЕ путать с причиной «не ловятся ручные отправки» (та — про `\Sent` выше).
- **PHP 8 trait composition: `public $queue` на job-классе = Fatal** (2026-05-28). `Illuminate\Bus\Queueable` trait объявляет `public $queue;` без default (null). Любое class-level `public $queue = 'mail-sync'` PHP 8 трактует как «differing definition» в trait composition → `Symfony\Component\ErrorHandler\Error\FatalError: ...define the same property ($queue) ...However, the definition differs and is considered incompatible`. Тип (`public string $queue`) — тоже Fatal, ещё раньше. **Правильно:** ставь очередь через `$this->onQueue('mail-sync')` в `__construct`. Это runtime-set на той же property из trait, без property-level конфликта. Касается и других «свойств от trait» — `$connection`, `$delay`, `$tries` (последний почему-то работает с class default — он определён в Queueable иначе). Кейс: сегодня я положил продакшен на 50 мин, дважды.
- **queue:work процессы кешируют классы в памяти** (2026-05-28). После `git pull` PHP-FPM и CLI-artisan видят новый код, но **воркеры** продолжают работать на старом. **Любое изменение в `app/Services/`, `app/Jobs/`, `app/Prompts/`, `app/Models/` требует рестарта воркеров**, иначе job'ы крутят старую логику. Дважды попался: (1) `ParseItemsUnifiedPrompt` whitelist «паллета траволатора» добавил — без рестарта воркеры гоняли старый промпт; (2) `isServiceItem` PHP-фильтр такое же. Регламент деплоя для таких файлов: `git pull` → `composer install` (если меняли autoload) → `view/route/config:cache` → **`php artisan queue:restart`** (soft, не hard `supervisorctl restart`).
- **Не делай `supervisorctl restart mzcorp-worker:*` после каждого деплоя кода** (2026-05-28). Hard restart обрывает in-flight job'ы посреди работы, оставляет `jobs.reserved_at` без cleanup. После рестарта другой воркер по reservation-timeout подбирает тот же job, handle() запускается заново с тем же `jobs.uuid`. Когда он снова падает (или timeout), INSERT в `failed_jobs` падает на `failed_jobs_uuid_unique` collision → exception bubbles наверх. Из-за этого 2026-05-28 случился час простоя info@ (см. queue topology § post-mortem). **Используй `php artisan queue:restart`** — Laravel шлёт soft-signal «закончи текущий job и выйди», supervisor поднимет нового воркера с новым кодом. Hard restart — только если queue:restart не помогает (рассинхрон, зависший процесс).
- **Двойной слой фильтрации в parser-pipeline: промпт ↔ PHP guard** (2026-05-28, кейс M-2026-2102 «паллета траволатора»). `ParseItemsUnifiedPrompt::systemMessage()` имеет blacklist услуг (доставка, упаковка, паллет, монтаж...) — LLM не извлекает такие позиции. ПАРАЛЛЕЛЬНО `RequestItemParsingService::isServiceItem()` дублирует это в PHP regex'ом — отлавливает то, что LLM всё-таки протащил. Когда правишь whitelist/blacklist в промпте — **обязательно зеркаль изменения в `SERVICE_PREFIX_PATTERNS` / negative-exclusions `isServiceItem()`**. Иначе LLM найдёт позицию, PHP её выкинет, в логах `normalizeParsedItem: service item filtered`, в UI «парсер не нашёл ничего». Тратится час на диагностику.
- **PCNTL timeout race в long-running jobs** (2026-05-28). Job завершается успешно (в логах `done`), и параллельно Laravel помечает его как failed по `MaxAttemptsExceeded`. Причина: handle() пересекает `$timeout`, PCNTL alarm стреляет `JobTimedOutException`, Laravel mark-failed + INSERT в `failed_jobs`. Но handle() **продолжает выполняться** до своего естественного завершения (alarm не убивает мгновенно). В лог попадают оба события. **Лечение:** (а) увеличить `$timeout` с запасом 2× от реального p99; (б) добавить `public bool $failOnTimeout = true` — Laravel явно помечает job failed, не оставляет в reserved; (в) сделать job идемпотентным + `ShouldBeUnique` — даже если случится повторный pickup, не отработает дважды.
- **shell quoting на ssh+`php artisan tinker --execute`** (2026-05-28). PHP-namespace separator `\` в SQL/PHP-коде, который передаётся через `ssh root@host 'php artisan tinker --execute="\App\Models\Foo::..."'` — съедается shell'ом, на серверной стороне приходит «AppModelsFoo» → ParseError. Аналогично двойные кавычки внутри JSON-path PostgreSQL (`payload::jsonb->>"key"`). **Лечение:** для tinker-скриптов с namespace или сложными кавычками — клади скрипт в файл через heredoc, потом `php artisan tinker storage/script.php`. Heredoc единичными кавычками (`<<EOPHP ... EOPHP`) сохраняет кавычки внутри. Файл должен лежать в `/var/www/mzcorp/storage/` (не `/tmp/`) и быть `chown www-data:www-data`, иначе artisan tinker от www-data не прочитает.
- **Livewire `wire:click.self` на backdrop модалки → text-selection drag закрывает окно** (2026-05-28). `click` event fires на **общем предке** mousedown и mouseup. При selection текста внутри панели + release мыши за её пределами, mouseup приходит на backdrop, click fires на нём как «общем предке» → wire:click.self → close. Бесит на длинных текстах. **Лечение:** `wire:mousedown.self="close"` — mousedown регистрируется на ТОЧКЕ нажатия, а не на ancestor. Press внутри панели → mousedown ушёл на её child, на backdrop не пришёл. Outside-click (mousedown на backdrop) работает как раньше. Применено для 12 модалок одной волной.
- **`@tailwindcss/forms` + `h-[26px]` на `<select>` = обрезка глифов** (2026-05-28). Плагин накатывает `padding: .5rem .75rem` + `line-height: 1.5rem` на все selects. С `box-sizing: border-box` height 26px - 16px padding = 10px на контент; глифы 12px × line-height 1.5 = 18px → текст обрезается сверху/снизу. На скриншоте выглядит как «контраст плохой / шрифт серый» — реально это срез. **Лечение:** в base CSS `select { padding-top: 0 !important; padding-bottom: 0 !important; line-height: 1 !important; }`. Горизонтальный padding + место под стрелку плагин сохраняет. Не гонись за color/contrast если на скрине буквы выглядят «обрезанными по горизонтали» — это геометрия.
- **Livewire `TemporaryUploadedFile` теряет связь с temp-файлом после `storeAs()`** (2026-05-28, кейс support attachment 500). После `$file->storeAs(...)` Livewire перемещает временный файл в target, и любой следующий вызов `$file->getSize()` / `getMimeType()` лезет в `FilesystemAdapter->size()` несуществующего файла → exception, транзакция откатывается, тикет/Request теряется. **Правило:** читай **все** метаданные (`original_name`, `mime_type`, `size_bytes`) в локальные переменные **ДО** `storeAs()`. После — только `$path = ...storeAs()`.
- **Document.referrer для возврата «← К списку»** (2026-05-28). Livewire `#[Url]` уже синхронизирует фильтры/пагинацию в query string, состояние есть в URL браузера на любой момент. Проблема только в return path: `<a href="{{ route('requests.index') }}">` ведёт на чистый URL без params. **Лечение через Alpine x-init:** читаем `document.referrer`, если pathname === `/dashboard/requests` — перезаписываем `$el.href = referrer`. Filters/page возвращаются автоматически. Works for SPA Livewire (referrer пишется через History API replaceState на каждое изменение).
- **docs-bot (user id 19, docs-bot@myzip.ru) — НЕ должен быть в active+available** (2026-05-27): для скриптов скриншотов через puppeteer (`scripts/capture-docs-screenshots.mjs`) создан служебный user с role `head_of_sales`. Эта роль входит в `Role::requestHandlerRoles()` — `AssignmentService::autoAssign` начал round-robin'ить новые заявки на него (10 штук прилетело за полчаса). **Полное лекарство:** `archived_at = now()` (исключает из `scopeActive` → Dashboard/Pool sidebar/Admin Managers) + `unavailable_until = 2099-12-31` (исключает из `scopeAvailable` → AssignmentService). Login для скрипта продолжает работать — `Auth::attempt` не проверяет `archived_at`. При повторном создании похожих служебных users — сразу выставлять оба поля.
- **`*/` внутри docblock'а закрывает комментарий** (Phase ItemCatalogLinkDialog 2026-05-19): В docblock'е метода `subjectDimensions` была строка `* ... через ×/x/*/Х` — подстрока `*/` посередине закрыла doc-комментарий преждевременно, дальше PHP-парсер увидел кириллическую `Х` как identifier и упал `ParseError: unexpected identifier "Х", expecting "function"`. Воспроизводилось на проде стабильно, на Windows-source PHPStorm молча подсветил docblock но не warning'нул. **Правило:** в docblock'ах НЕ писать `*/` подряд — экранировать через пробел `* /`, кавычки `"*"` или другой разделитель. Этот класс ошибок отличается от «UTF-8 кириллицы ломает парсер» — никаких байтовых проблем не было, чистая блокировка комментария. Не зацикливайся на encoding'е если в docblock'е есть звёздочка-слеш.
- **Beget DB case-sensitive** — БД создана как `MyLift` с заглавной. В `.env` `DB_DATABASE=MyLift`. Проверено.
- **pgvector whitelist Beget** — расширение должно быть в whitelist кластера. Уже сделано. Миграция активации tolerant (no-op если расширения нет).
- **`webklex/laravel-imap` — pure PHP**, не требует расширения `imap`. Это плюс на Beget VPS.
- **Yandex папки по-русски** (`Входящие`, `Отправленные`) — мониторим через IMAP special-use flags (`\Inbox`, `\Sent`), **не по именам**.
- **`UIDVALIDITY`** — при смене делаем full resync с папки.
- **Origin SSH-alias на VPS** — на VPS изначально был SSH origin с alias `github.com-mzcorp`. Под `www-data` алиас не резолвится, переключили на HTTPS. Если кто-то снова поставит SSH — напоминать сменить.
- **Yandex 360 IMAP folder ops (Phase 1.8b находки):**
  - **Намespace delimiter — `\|`**, не RFC-стандартный `/`. Webklex `Folder->delimiter` корректно репортит. Все пути строятся через detected delimiter.
  - **CREATE подпапки под INBOX запрещён** — Yandex отвечает `BAD [CLIENTBUG] CREATE cannot apply to INBOX subfolder`. Все «Мои папки» живут на root-уровне параллельно с INBOX (`MZ`, `MZ\|Ivanov`, не `INBOX\|MZ\|Ivanov`).
  - **Имена папок в MUTF-7 (RFC 3501 §5.1.3)**: webklex auto-encode для `createFolder`, но не для `copy/move`. Двойной encode даёт литерал `&BBgEMgQwBD0EPgQy-`. **Решение:** имена папок только ASCII, через транслитерацию `cyrillicToLatin()` (`Иванов` → `Ivanov`, ICU `transliterator_transliterate` + fallback map).
  - **MOVE / COPY+EXPUNGE не работают**: Yandex даёт `BAD [CLIENTBUG] EXPUNGE Wrong session state for command` если webklex после COPY пытается EXPUNGE. **Решение:** только `$msg->copy($path, expunge: false)`, без MOVE/DELETE/EXPUNGE — оригинал остаётся в INBOX, копия в `MZ\|{Lastname}`. Чтобы оригинал не торчал «непрочитанным» — ставим `\Seen` на оригинал явно после COPY (отступление от Foundation §1, документировано в CLAUDE.md абс. правило #8).
  - **createFolder требует существующих парентов**: `MZ\|Ivanov` нельзя создать без предварительного `MZ`. `MailFolderRouter::ensureFolder()` идёт по сегментам (recursive create).
- **`SyncMailboxFolderJob` body fetch ловушка (Phase 1.8d/1.9 находка)**:
  - **`whereAll()->limit(500)->get()` берёт первые 500 sequence-numbers** (старейшие письма), не последние UID. Когда папка перерастает 500 — новые UIDs (seq>500) **никогда** не попадают в выборку. last_uid_seen замораживается, sync видимо «работает» но ничего не догоняет. Закрыто через two-step fetch: `$connection->getUid()->validatedData()` для UIDs списком, потом per-UID `whereUid($uid)->get()->first()` для bodies.
  - **`->whereUid($uid)->first()` ломается**: webklex 6.x `WhereQuery` не имеет `first()` — magic `__call` транслирует имя в `WHEREFIRST` критерий → IMAP server BAD. Нужно `->whereUid($uid)->get()->first()` (first() на `MessageCollection`, не `WhereQuery`).
- **`AmneziaVPN` блокирует Beget Cloud DB** (Phase 1.8e находка): мой локальный трафик идёт через `AmneziaVPN` → exit-IP не whitelisted в Beget → TCP проходит, server сразу шлёт RST. Все диагностические запросы прогоняются на VPS через tinker, локально подключения нет.
- **HTML-only письма (LazyLift, маркетинг) → body_plain пуст или CSS** (Phase 1.8e): IMAP-парсер не извлекает текст из `<style>`, либо отдаёт сам CSS-блок. Решение — `EmailTextCleanerService::bodyPlainLooksBroken()` детектит и переключает на `htmlToText(body_html)` где `</td>→ \| ` и `</tr>→ \n` сохраняют табличную структуру.
- **Yandex 360 forward в quoted-блоке** (Phase 1.8e): Yandex обрамляет forward маркером `> -------- Перенаправленное сообщение --------` (с `>` префиксом цитирования). `extractForwardedContent` на raw тексте не матчится. Решение — `dequoteText()` ПЕРВЫМ, потом `extractForwardedContent`. Forwarded-блок физически выбрасывается (не отдаётся AI), потому что это либо наша же исходящая КП, либо чужая старая переписка.
- **`scp` на Windows PowerShell** не expand'ит `~/Desktop` (Unix tilde). Нужно `$env:USERPROFILE\Desktop\file.txt` или абсолютный путь.
- **Beget `php artisan config:cache route:cache view:cache` одной командой** не работает — artisan не принимает доп. аргументы. Только тремя отдельными вызовами или через `&&`.
- **PSR-4 autoload новых классов на проде** — после `git pull` если новые классы есть в `app/Services/Mail/`, `app/Prompts/Mail/` — обязательно `sudo -u www-data composer dump-autoload --optimize`, иначе Laravel autoload-cache (`bootstrap/cache/`) не подхватит и `class_exists` вернёт false.
- **HTML письма: `<style>` утекают в страницу** (Phase 1.13 находка): `{!! $body_html !!}` в Blade рендерит `<style>` блоки прямо в DOM приложения, переопределяя `.btn` / шрифты CRM. **Решение:** письма треда выводим в `<iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" srcdoc="...">` — стили физически изолированы, JS из письма не выполнится (нет `allow-scripts`). Auto-height через Alpine `x-init` + ResizeObserver, ссылки в письме переписываются на `target=_blank rel=noopener`. См. `resources/views/livewire/requests/detail.blade.php` таб «Переписка».
- **Кириллица в `Content-Disposition`** (Phase 1.13 находка): голый `Content-Disposition: attachment; filename="ролик.JPG"` нарушает RFC 7230 (header ASCII-only). Symfony Response/nginx mojibake'ит или режет header, скачивание «не работает». **Решение:** `Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition($disposition, $filename, $asciiFallback)` — даёт корректный `attachment; filename="..."; filename*=UTF-8''<percent-encoded>`. См. `AttachmentController::streamAttachment()`.
- **`Content-Length` из БД-метаданных** (Phase 1.13): `email_attachments.size_bytes` — то, что репортнул IMAP при ингесте, может не совпасть с реальным размером файла на storage-диске. Несовпадение объявленной длины и фактических байт → браузер обрезает stream и скачивание прерывается. **Решение:** не ставим `Content-Length` в `response()->stream()` совсем, Symfony отдаёт chunked transfer и stream завершается чисто.
- **`mailboxes.encrypted_credentials` NOT NULL** (Phase 1.13): колонка text NOT NULL по исходной миграции `2026_05_06_080000_create_mailboxes_table.php`. INSERT с `null` падает на constraint → 500. **Решение:** при создании ящика без credentials писать пустой зашифрованный JSON через `$mailbox->writeCredentials([])` до `save()` — тот же приём в существующем CLI `mail:add` (`MailAddMailboxCommand.php:97-99`). `Mailbox::accessToken()` на пустом credentials вернёт `null`, что корректно обрабатывает state-machine UI.
- **Livewire `Str::studly` несовместим с camelCase в имени класса** (Phase 1.13): tag `<livewire:admin.managers.mailbox-oauth />` через `Finder::generateClassFromName()` резолвится через `Str::studly('mailbox-oauth')` → `MailboxOauth` (lowercase «auth»). Если PHP-класс назван `MailboxOAuth` (camelCase «OAuth») — на Linux PSR-4 case-sensitive ломается. **Решение:** имя класса/файла должно быть строго результатом studly от kebab-tag. Файл: `app/Livewire/Admin/Managers/MailboxOauth.php`, класс: `class MailboxOauth`.
- **Livewire public Eloquent property с shadow-named class** (Phase 1.13): `public App\Models\Request $request;` в child Livewire-компоненте `ReassignDialog` ломает дегидратацию snapshot, валит render с inline «500 SERVER ERROR» в DOM. Конфликт через alias-цепочку с `Illuminate\Http\Request`. **Решение:** хранить только `public int $requestId;`, model резолвить через приватный `request()` helper с `findOrFail()`. Тот же паттерн в `MailboxOauth` и оригинальном `RuleEditor`.
- **Spatie role name vs UI label** (Phase 1.13): role enum value = `'manager'` (singular), UI chip label = «Менеджеры» (plural). Если key чипа фильтра = `'managers'` и оно же подставляется в `whereHas('roles', fn => where 'name' = $filter)` — match'а нет, список пустой при счётчике на чипе > 0. **Решение:** key чипа = role enum value (`manager`), label остаётся читабельным.
- **На карточке заявки 500 SERVER ERROR в action-panel** — отчётливый признак того, что отвалился child Livewire-компонент. Page-level `Detail` рендерится норм, ошибка локализована в одной плашке.
- **LazyLift KB synonym matcher через substring (Phase 2.0 находка):** `CategoryRefinementService::matchBySynonym` использует голый `mb_strpos`, и короткие 2-3-буквенные синонимы (`ОС`, `ЧП`, `ПЧ`, `БП`, `УП`, `ФБ`, `OSG`, `VFD`, `GSM`, `LCB`) дают огромное количество false positives — `«ОС»` (Ограничитель Скорости) ловится в `«п<ос>т»` русского слова «пост», и любой «Пост вызывной LOP2 OTIS» уезжал в `speed_governor`. **Решение:** word-boundary regex с явными Unicode-классами `[\p{L}\p{N}_]` lookahead/lookbehind. PHP `\b` с `/u` flag не помогает — он непредсказуемо классифицирует кириллицу (на нашей PHP-сборке `\b` ставится между `п` и `о`). Patch (`599d271`+`85fb60e`) — апстримить в LazyLift.
- **`QualityAssessmentService` preserve-логика (Phase 2.0):** при наличии `identification_category_id` сервис **не пересчитывает** категорию, а сохраняет старое значение (уважение к ручному выбору куратора). После любого fix-а резолвера старые false-positive не обновятся — нужен `kb:resolve --reset-categories`, который обнуляет `identification_category_id` + `manufacturer_brand_id` ДО `assessItem`. Альтернативно — вручную: `RequestItem::where(...)->update(['identification_category_id' => null])`. Логика на line ~188 of QualityAssessmentService.
- **Postgres CHECK-based enum (Phase 2.0):** Laravel `$table->enum()` на Postgres не создаёт настоящий enum-тип, а VARCHAR + CHECK constraint с автоименем `<table>_<column>_check`. `ALTER TYPE ADD VALUE` не работает — нужно `DROP CONSTRAINT` + `ADD CONSTRAINT` с расширенным IN-list. Шаблон в `2026_05_08_210001_add_internal_catalog_pending_to_quality_status_enum.php`.
- **MyLift internal SKU `M\d{4,}` (Phase 2.0):** Парсер позиций часто отдаёт article в виде «M02016» или составной строки «LOP2, HBB M02016». Это внутренние SKU MyLift корпоративной базы — их категория должна приходить из каталога, а не угадываться LLM. До появления каталога `QualityAssessmentService::detectInternalCatalogSku()` ловит pattern `(?<![\p{L}\p{N}_])M\d{4,}(?![\p{L}\p{N}_])` и помечает item статусом `internal_catalog_pending` с early-return — экономит OpenAI на гарантированно бесполезных вызовах. После появления каталога — batch-резолв по `payload.internal_catalog_sku`.
- **`SupplierResolverService` нужен в DI graph (Phase 2.0):** мы скопировали LazyLift KB без supplier-инфры (suppliers/Supplier model нет), но `RequestContextAnalysisService` принимает `SupplierResolverService` в конструкторе. Без stub-класса `kb:resolve` падает с `ReflectionException: Class App\Services\Kb\SupplierResolverService does not exist`. **Решение:** stub возвращающий `null` на `resolve()`. Заменить полноценным когда supplier model появится.
- **MyLift KB ≠ LazyLift KB (Phase 2.0+):** drop-in из LazyLift @ `7fee1f77` живёт в нашем репо как **независимая копия**. Все наши фиксы (synonym word-boundaries, insufficient-when-brand-known, internal SKU detection) НЕ распространяются обратно в LazyLift автоматически. Если кто-то синкнёт MyLift-KB обратно к LazyLift — нужен ручной merge. Для long-term можно вынести в `composer.json` shared package, но сейчас drop-in копия проще.
- **Расхождение «оценочного объёма» сидеров с реальным (Phase 2.0):** Explore-agent предположил «~140 rules / ~80 extractors». Реально в LazyLift JSON @ `7fee1f77`: 39 rules, 4 extractors. Не баг, а ограниченность исходного KB-corpus. Покрыты только тяговые канаты, контактор, дверной упор, индикация — узлы которыми занимались первыми. Кабели/индикаторы/освещение — белые пятна, items с такими названиями уходят в `not_covered`.
- **Phantom outbound link через L4 OutgoingMailLinker (2026-05-22 находка):** Старая логика `matchByOpenRequestForRecipients` искала открытые Request по `whereIn('client_email', recipients)` и при множественном match брала «самую свежую». При first-time синке личного ящика с большой Sent-историей это создавало каскад ложных привязок: outbound к коллеге `@myzip.ru` цеплялся к фантомной Request, у которой `client_email` = тот же коллега (артефакт внутренней переписки, ParseRequestItemsJob создал Request из inbound от коллеги). Дальше через L1/L2 каскадно — все письма с References на эту цепочку наследуют привязку. На mbox=6 (директор) такое привязалось 62 раза к 10 разным Request. **Решение (commit `8883717`):** три новых guard'а в L4: (1) фильтр `filterExternal` отбрасывает наши адреса (internal_domains + Mailbox.email + User.email) из recipients; (2) time-window `config('services.mail.outbound_link_window_days')` (default 90 дней) — Request старше окна не матчится; (3) при 2+ кандидатах — `return null + WARNING`, никакого «pick latest». Sanity для будущих ящиков менеджеров: cleanup через скрипт «outbound с related_request_id, без настоящего parent в БД (whereIn message_id) и без M-code в subject» → массовое отвязывание.
- **request_state_changes.event = varchar(32) (2026-05-22 находка):** Колонка коротко-индексируемая, длинные event-имена (`system.cleanup.internal_client_email_phantom` = 44 символа) падают на `SQLSTATE[22001] String data, right truncated`. Держим event'ы ≤ 32 символов. Использованные: `clarification_sent`, `reanimate`, `cleanup.internal_phantom` (24 chars).

## Журнал сессий

### Сессия 2026-05-28 — sender-blocklist + queue topology + dashboard chart + ops-грабли

**Контекст:** большая «комбо-сессия» — одна крупная фича (sender blocklist), несколько UX-фиксов, один production incident с разделением очередей, обновление manager-гайда, новый chart на дашборде. По ходу два раза заваливал прод (50 мин и ~1 час) — оба раза собственными «защитными» фиксами. Все известные грабли записал отдельно (см. секцию «Известные грабли» — добавлено 10 свежих позиций сверху списка) — следующий раз начинать с их прочтения.

#### Что задеплоено

| Модуль | Что | Ключевые коммиты |
|---|---|---|
| **Sender blocklist** | Целиком: enum BlocklistEntryType/Source, миграция `sender_blocklist` (unique по normalized_value), модель + сервис с suffix-match по доменам и plus-addressing нормализацией, врезка в `MailRouter::route` ДО LLM + defense-in-depth в `IncomingMailProcessor`, Livewire CRUD `/dashboard/sender-blocklist` (single + bulk), case `ClosedLostReason::Spam` + UX в `CloseLostDialog` с выбором email/domain scope, пункт «Стоп-лист» в топбаре. Гайды `rop/sender-blocklist.md` + `manager/spam-close.md`. 8 unit-тестов нормализации + 7 integration-тестов сервиса. Полная секция в MEMORY § «Sender Blocklist (стоп-лист отправителей, 2026-05-28)». | `b5aacd7` |
| **nginx fix /docs/ 403** | Убрал `$uri/` из `try_files` в `deploy/nginx/mzcorp.conf` + продовый `/etc/nginx/sites-available/mzcorp.conf`. Физическая папка `public/docs/screenshots/` блокировала Laravel-роут. | `24a09ae` |
| **Support 500 на attachment** | `SupportTicketService::storeAttachment` читал `getSize()/getMimeType()` ПОСЛЕ `storeAs()` — Livewire `TemporaryUploadedFile` к тому моменту терял связь с temp-файлом, `FilesystemAdapter->size()` падал на «file not found». Read metadata BEFORE storeAs(). | `18719db` |
| **CSS select обрезка** | `@tailwindcss/forms` накатывал `padding: .5rem .75rem + line-height: 1.5rem` на все `<select>`, с `h-[26px]` это давало 10px на контент при 18px глифах → вертикальная обрезка букв. Сначала зря погнал в сторону color/contrast (3 итерации с !important / -webkit-text-fill-color / color-scheme — всё мимо). В итоге `padding-top/bottom: 0 !important; line-height: 1 !important` в base CSS. Урок: если на скрине буквы «срезаны по горизонтали» — это геометрия, не контраст. | `672245f` + предыдущие |
| **Возврат «← К списку»** | Alpine `x-init` в `detail.blade.php` читает `document.referrer`, если pathname === `/dashboard/requests` — перезаписывает `$el.href`. URL-state у Livewire уже синхронизирован через `#[Url]`, проблема была только в return-path. Работает и для SPA-режима (referrer пишется в History API replaceState). | `a399d1f` |
| **M-2026-2102 не парсилась** | (1) Empty-state на табе «Позиции» обещал «добавьте позицию вручную ниже» — формы там не было, она пряталась за `@else $items->isEmpty()`. Подложил `livewire:requests.items.add-item-form` прямо в empty-state. (2) `Request::isParsingInFlight()` был gated на `status===Pending`, на quoted-заявке reparse не давал visual feedback → пользователь думал что кнопка не сработала. Добавил `parsing_meta.reparse_dispatched_at`, isParsingInFlight теперь возвращает true в течение 5 мин после ручного reparse независимо от статуса. Wire:loading на кнопку. (3) **Контентная проблема LLM:** «нужна паллета траволатора Doppler» — модель отбрасывала позицию по слову «паллет» в blacklist промпта. Whitelist «Паллета траволатора/эскалатора, Ступень, Гребёнка, Поручень, Канат тяговый» в `ParseItemsUnifiedPrompt`. (4) **Двойной слой фильтрации**: после фикса промпта LLM возвращал позицию (`items_count: 1` в логах), но `RequestItemParsingService::isServiceItem()` PHP-фильтр её отрезал по тому же regex `^паллет`. Симметричный whitelist `traveолатор|эскалатор|лифт|лебёдк|кабин|шахт|...` в negative-exclusions. **Грабля в граблях**: oba layer должны быть синхронны, правя один — править другой. | `bd20e58`, `ee03bdc`, `b872741`, `3c23bda` |
| **Catalog search description/comment** | Расширил поиск на `description`+`comment` каталога (GIN trgm индексы + word_similarity в codeTokenTopN/trigramTopN, weight ×0.7 чтобы body-hit не перебивал name-match). Vector embedding не трогал — потребовало бы перегенерации 35K эмбеддингов. Запрос пользователя: `B157AAUX01` — точного нет, но похожий `B157AAEX01` теперь находится в comment'ах M19188/M06971 «ЗАМЕНЕНО НА M24479». Миграция `2026_05_28_180000_add_description_comment_trgm_indexes_to_catalog_items.php` (CREATE INDEX IF NOT EXISTS — идемпотентно). | `da3a... → 0e59179` |
| **Модалки drag-select fix** | `wire:click.self="close"` → `wire:mousedown.self="close"` на 12 модалках. Drag-select текста из панели за её пределы перестал случайно закрывать окно. `click` срабатывает на общем предке mousedown+mouseup; `mousedown` — только в точке нажатия. | до `becd3f9` (часть mass-edit) |
| **Pool date + time** | В колонке кода заявки `created_at->format('d.m.y H:i')` вместо `d.m.y`. По запросу РОПа — важно понимать утренний/вечерний поток. | до `becd3f9` |
| **Queue topology post-mortem** | **Инцидент:** info@ inbox stuck 1h. `ResolvePendingChunkJob` зацикливался по PCNTL timeout race (handle() писал `done` И одновременно Laravel mark-failed по MaxAttemptsExceeded), мои частые `supervisorctl restart` оставляли reserved-limbo, INSERT в `failed_jobs` падал по UUID collision (`failed_jobs_uuid_unique`), 178 одинаковых записей. Архитектурный фикс — 3 очереди: `mail-sync` (SyncMailboxFolderJob), `default` (всё остальное), `catalog-resolve` (ResolvePendingChunkJob + dispatcher). Supervisor `--queue=mail-sync,default,catalog-resolve` (приоритет mail-sync). Закоммичен `deploy/supervisor/mzcorp-worker.conf`. Защиты: `ResolvePendingChunkJob` timeout 300→600, `ShouldBeUnique` по md5 sorted itemIds (5 мин), `failOnTimeout = true`. **САМОПОДРЫВ:** деплоя class-level `public string $queue = '...'` уронил прод в Fatal Error PHP 8 trait composition (Queueable trait объявляет `public $queue;` без default). Фикс — установка через `$this->onQueue('...')` в `__construct`. Полная секция в MEMORY § «Queue topology (2026-05-28 post-mortem)». | `3af4f45`, `cab33ce` |
| **Manager workflow.md обновлён** | Преамбула «Что такое mzCorp и зачем» (системный pitch без коммерческого пафоса). Расширены Шаг 2 (полное ⋮ меню позиции, Похожие из каталога с фильтрами и compare-checkboxes, Привязать вручную, Сменить фото), Шаг 3 (per-item панель со слотами KB + chip-кнопки + preview готового письма), Шаг 5 КП **полностью переписан** (КП теперь в самом mzCorp, не «вне MyLift»: пустой → создать → редактировать с per-line скидками и price_min defence → версии v1/v2 → Превью PDF → отправить), Шаги 6-7 (auto-reminders клиенту, ручная отметка оплаты). 11 свежих скринов в `public/docs/screenshots/` (требуют ssh + chown www-data на проде, но git pull это делает автоматически — проверено). | `becd3f9` |
| **Dashboard timeseries chart** | Новая ds-card «Поток заявок · по дням · {periodLabel}» перед heatmap. 3 series — personal (sky #0284c7), shared (emerald #059669, info@), total (neutral #111827 dashed). Чистый inline SVG без npm-зависимостей (Chart.js не подтягивал — Tailwind config уже сложный). Computed `requestInflowTimeseries()` через LEFT JOIN requests → email_messages → mailboxes.type. Заявки без email_message (ручные) попадают в total, не в split — tooltip показывает дельту «вручную: N». Доступно head_of_sales/director/secretary/admin (`isPrivileged`). Существующий heatmap переименован «по часам». | финальный коммит сессии |
| **Скриншоты + asset cleanup** | 11 свежих скринов из `C:\Work\mzCorp\sshots\MyLift-*.png` в `public/docs/screenshots/` с осмысленными именами: `requests-pool-detailed.png`, `request-overview.png`, `request-positions-full.png`, `item-actions-menu.png`, `catalog-link-similar.png`, `catalog-link-manual.png`, `item-clarification-panel.png`, `clarifications-draft.png`, `clarification-email-compose.png`, `quote-empty-create.png`, `quote-draft.png`. Старые 4 файла (`requests-pool.png`, `request-detail.png`, `request-positions.png`, `dashboard.png`) оставлены — могут использоваться где-то ещё. | в `becd3f9` |

#### Известные грабли — за сессию добавлено 10 (см. начало секции «Известные грабли»)

Топ-3 для следующей сессии (читать обязательно):

1. **PHP 8 trait composition: `public $queue` на job-классе = Fatal.** Ставь через `$this->onQueue('...')` в `__construct`. Стоило 50 мин даунтайма ДВАЖДЫ за сессию.
2. **`queue:work` процессы кешируют классы в памяти.** После `git pull` любого `app/Services/*`, `app/Jobs/*`, `app/Prompts/*`, `app/Models/*` ОБЯЗАТЕЛЕН `php artisan queue:restart` (soft) или `supervisorctl restart` (hard). Без этого воркер крутит старый код. Дважды попался: `ParseItemsUnifiedPrompt` + `isServiceItem`.
3. **Не лупить `supervisorctl restart mzcorp-worker:*` после каждого деплоя.** Hard kill обрывает in-flight job-ы, reserved-limbo создаёт UUID-collision на следующем re-pickup. Используй `php artisan queue:restart` — soft signal «закончи и выйди», supervisor поднимет свежего. Hard restart — только при рассинхроне/висящем процессе.

Остальные 7 граблей: shell quoting на ssh+tinker (heredoc через storage/script.php), Двойной слой фильтрации в parser-pipeline, PCNTL timeout race в long jobs, `wire:click.self` vs `wire:mousedown.self` на backdrop, `@tailwindcss/forms` + `h-[26px]` = обрезка глифов, Livewire `TemporaryUploadedFile` после `storeAs()` мёртв, `document.referrer` для возврата.

#### Открытые вопросы / TODO

- **Корневая причина зацикливания `ResolvePendingChunkJob`** до post-mortem. Запущен spawn-task: прочитать `app/Jobs/Catalog/ResolvePendingChunkJob.php`, понять почему 50 items × 3с не укладывались в 300с (медленный LLM? n+1 в matchOrResolve? memory leak на cursor?). После архитектурного фикса (timeout 600 + isolated queue) проблема перестала влиять на mail-sync, но сам цикл может вернуться. Нужно либо починить причину, либо явно `delete()` row с битыми входами.
- **Cron `queue:prune-failed --hours=72`** — раз в сутки, чтобы `failed_jobs` не рос. Сейчас 9 остатков (старые RouteMailToManagerJob), не критично.
- **Health-check команда** `queue:size-check` + telegram-alert если jobs > 200 или reserved > 30 мин.
- **`document.referrer`** в кнопке «← К списку» не сохраняет точное место скролла. Если нужна полная восстановляемость — sessionStorage с scroll position.
- **Старые скрины** в `public/docs/screenshots/` (`requests-pool.png`, `request-detail.png`, `request-positions.png`, `dashboard.png`) — оставлены на случай использования. Если ни одна страница не ссылается — удалить.
- **`mzcorp-worker.conf` в репо vs прод** — рассинхрон возможен. Сейчас прод-копия обновлена руками (cp + reread + update + restart). На следующем deploy любого worker-кода — пересинхронить.
- **Spawn-task `Fix poisoned ResolvePendingChunkJob cycle`** — висит в chip'е сессии (создан до моего deploy queue topology, root cause там объяснён более узко). Если будет открыт — нужно не дублировать.

#### Файлы / структура

Новые файлы:
- `app/Enums/BlocklistEntryType.php`, `BlocklistEntrySource.php`
- `app/Models/SenderBlocklistEntry.php`
- `app/Services/Mail/SenderBlocklistService.php`
- `app/Livewire/SenderBlocklist/BlocklistIndex.php`
- `database/migrations/2026_05_28_150000_create_sender_blocklist_table.php`
- `database/migrations/2026_05_28_180000_add_description_comment_trgm_indexes_to_catalog_items.php`
- `resources/views/livewire/sender-blocklist/blocklist-index.blade.php`
- `resources/views/admin/sender-blocklist/index.blade.php`
- `resources/docs/rop/sender-blocklist.md`
- `resources/docs/manager/spam-close.md`
- `tests/Unit/Services/Mail/SenderBlocklistNormalizationTest.php`
- `tests/Feature/Services/Mail/SenderBlocklistServiceTest.php`
- `deploy/supervisor/mzcorp-worker.conf`
- `public/docs/screenshots/{request-overview,request-positions-full,item-actions-menu,catalog-link-{similar,manual},item-clarification-panel,clarifications-draft,clarification-email-compose,quote-empty-create,quote-draft,requests-pool-detailed}.png` (11 шт.)

Изменённые job-ы (важно — `$queue` через `onQueue()` в `__construct`, НЕ public property):
- `app/Jobs/Mail/SyncMailboxFolderJob.php` (queue=mail-sync)
- `app/Jobs/Catalog/ResolvePendingChunkJob.php` (queue=catalog-resolve, timeout=600, ShouldBeUnique, failOnTimeout)
- `app/Jobs/Catalog/ResolvePendingFromCatalogJob.php` (queue=catalog-resolve)

Изменённый prod-конфиг (синхронизирован руками после правки в репо):
- `/etc/supervisor/conf.d/mzcorp-worker.conf` ← `deploy/supervisor/mzcorp-worker.conf`
- `/etc/nginx/sites-available/mzcorp.conf` (только `try_files` строка) ← `deploy/nginx/mzcorp.conf`

---

### Сессия 2026-05-25 — support tickets + docs + load_weight + mail self-healing + circuit-breaker

**Контекст:** широкая сессия после Foundation-feedback'а на тему пропавших/потерянных писем и фейковых заявок. 24 коммита, 14 фич в одном дне. Параллельно: построена тикет-система «связь с создателем», рукописная документация по 4 ролям, фикс распределения заявок (load_weight), круг защит от OpenAI fail + неправильно подключённых ящиков.

#### Что задеплоено

| Модуль | Что | Ключевые коммиты |
|---|---|---|
| **Support tickets** | `/support/my` + `/support` admin + `/support/{id}`. 3 миграции, enum SupportTicketStatus, SupportTicketService (createTicket/addReply/changeStatus с auto-переходами), 2 Mailable + markdown views, 4 Livewire (NewTicketModal / MyTickets / Inbox / TicketView). Auto-context: url/route/viewport/UA/roles_snapshot. Бэлл-нотификация автору при ответе админа (SupportTicketReplyNotification), badge на ▲ через `Livewire Support\Trigger` с `wire:poll.30s`, mark-read при открытии тикета. Mini-list «Мои обращения» в модалке нового тикета. | `4dbcdee`, `8c27d9e`, `bb9eab2` |
| **Иконка-око** | Заменил sparkles на triangle+eye (всевидящее око, accent-цвет) — по утверждённому макету `09-contact-creator.html`. SVG inline в шапке + большая в баннере модалки (`public/images/contact-creator*.svg`). Подпись в footer «Отвечает {name}» — `SUPPORT_DEVELOPER_NAME` env. | `8c27d9e` |
| **Документация `/docs`** | DocsService (markdown + YAML frontmatter + кэш по mtime), DocsController с role-gate, 3-колоночный layout (rail + 260px sidebar + контент), CSS `.doc-content` + `.doc-preview`. Иконка `circle-help` в шапке. 14 страниц: `common/` (roles, glossary), `manager/` (navigation, pool, request-lifecycle с HTML-превью), `rop/` (navigation, distribution, mail-rules, mail-review, managers, settings), `director/` (navigation, dashboard, oversight). `league/commonmark ^2.8` уже был в зависимостях. | `75f3fa9`, `0d21c4d`, `7debfbb`, `35057b4`, `c25fbb7`, `68ae8dd` |
| **Парсер UX** | `parsing_meta.parser_finished_at` в `ParseRequestItemsJob` finally, `Request::isParsingInFlight()` (status===Pending && finished_at===null && created<30min). Chip «парсится…» в hero/items-tab/overview с `wire:poll.10s` на корневом div'е. Кнопка «↻ Перезапустить парсер» (`Detail::reparseItems`) для owner/acting/privileged — сбрасывает finished_at + dispatch `force=true reset=true`. Условный текст: «парсер ещё работает» vs «парсер не нашёл позиций — перезапустите или добавьте вручную». | `ffdafec`, `39a2b3c`, `e3f1868` |
| **Invoice автосоздание** | `InvoiceService::autoIssueFromOutboundQuote()` — из OutboundQuote с `document_type=outbound_invoice` создаёт Invoice (Pending) + transitionTo Invoiced. Идемпотентно по `email_message_id` или `invoice_number`. `ParseOutboundQuoteJob` вызывает после успешного match'а. `Detail.php` фильтр `outboundQuotes` — таб «КП» больше не показывает invoice'ы. Backfill: M-2026-1496 OutboundQuote #124 → Invoice #2. | `116cf1e` |
| **Mail self-healing** | (1) `InboundReplyLinker` — defer-gate: parent с тем же Message-ID в БД, но без request_id → return null (не падать в from_email_open_request fallback). (2) Терминал-parent → `rememberInheritanceCandidate` + return null → старая ветка Phase 2.1 inheritance работает корректно. (3) Level-4 LLM clarifier теперь получает в `$candidates` ещё и closed_lost-кандидатов клиента (lookback `ARCHIVE_CANDIDATE_LOOKBACK_DAYS`, Guard A: `closed_lost_source_message_id NOT NULL`) — LLM может выбрать closed → ветка inheritance. (4) `CategorizeIncomingPrompt::resolveBody` через `EmailTextCleanerService::htmlToText` вместо `strip_tags` — починен parsing HTML-only писем. (5) Scheduler `mail:categorize --all --limit=50` каждые 5 минут. (6) Новая команда `mail:relink-deferred --apply --limit=50` + scheduler 5 мин — повторно вызывает linker для висящих категоризованных reply'ев с in_reply_to/references. | `e4ea402`, `3b4f19a`, `9c93014` |
| **OpenAI circuit-breaker** | `App\Services\AI\OpenAiCircuitBreaker` — state в cache (fail_count/opened_at/notified_at). При N=3 подряд transient-ошибок (429/insufficient_quota/503/502/504/timeout/rate_limit) circuit «открывается» на M=15 мин. Первый успешный вызов снимает паузу. `OpenAiCircuitOpenedNotification` (db + mail если `SUPPORT_DEVELOPER_EMAIL`) — bell с иконкой ⛔, заголовок «OpenAI недоступен», клик ведёт на `platform.openai.com/account/billing`. Интеграция в `MailCategoryClassifier` (isOpen-gate + recordSuccess/Failure). Параметры в `services.openai.circuit_breaker` (`OPENAI_CB_*` env). Carbon 3 особенность: `diffInMinutes` signed — нужен `abs()` в `remainingMinutes()`. | `582db03`, `80462d4` |
| **Weighted assignment (load_weight %)** | Миграция `users.load_weight smallint DEFAULT 100 + CHECK (10..500)`. `AssignmentService::pickWeightedLeastLoadedManager`: `effective_load = load / (weight/100)` + LRU-tiebreak. Поле «Плановая нагрузка %» в `Admin\Managers\Editor` (только для request-handler ролей). UI-подсказка live «обычная доля / 2× меньше / 2× больше». Эффект накопительный (не нормализуем historical load) — REKOMENDOVANO «A» (см. обсуждение). | `23dd257` |
| **AiDecision boost-fallback** | Новая команда `quotes:reboost-stuck-decisions --apply --limit=N` — находит `OutboundQuote.status=matched AND payload.match_stats.matched_request > 0` (jsonpath), бустит соответствующий AiDecision (status=suggested, conf<0.95) до 0.95 + auto-apply через `AiDecisionService::apply` если `detector.auto_mode.<type>` включён. Scheduler каждые 15 минут. Backfill: 13 заявок переведены в Quoted автоматически. | `b6476b9` |
| **Director-mailbox guard** | `SyncMailboxFolderJob::handle()` — после `is_active` check добавлен `Mailbox::query()->syncable()->whereKey($id)->exists()`. Прямой dispatch с `mail:sync --mailbox=N` теперь не обходит scope (фильтрует личные ящики director/secretary/admin). UI: `edit.blade.php` — условный MailboxOauth (info-плашка для не-handler ролей), `MailboxOauth::createMailbox()` — server-side guard, `managers/index` — chip «не для этой роли» / «не должен синкаться» (амбер). Cleanup: Mailbox #6 `alexander.rodenkov@myzip.ru` → `is_active=false`, 3 заявки от него (M-2026-1723 Мирзоева/Перемотка, 1717 Vecta, 1722 Zagro) → closed_lost `off_topic`. | `0b87395`, `87abba4` |
| **Прятать workspace-pill от менеджера** | `navigation.blade.php` — pill только для head_of_sales/director/secretary/admin. Менеджер видел чужой `man2@myzip.ru · +13 ящиков` — сбивало. Бонус: убраны 3 SQL-запроса на каждый рендер шапки для менеджера. | `cb2cfc0` |

#### Backfill-операции (выполнены за сессию)

- `mail:categorize --all --limit=100` × 2 → **230 писем** категоризованы (133 client_request / 19 thread_reply / 78 irrelevant), 0 fail. До этого backlog накопился из-за OpenAI 429.
- `IncomingMailProcessor::processIfRequest` руками по 200 категоризованным без related_request_id → **48 Request созданы** (33 + 15).
- `quotes:reboost-stuck-decisions --apply --limit=100` → **13 застрявших AiDecision переведены в Quoted** (M-1505, 1542, 1522, 1556, 1564, 1587, 1586, 1589, 1593, и ещё 4).
- Manual recovery M-2026-1690 («901» Краснова): руками `category=client_request`, processIfRequest → создан Request, привязан #3681 и #3684, удалена ошибочно добавленная позиция «Аварийный тормоз XAR 330C3» из M-2026-1654 (RequestItem #3267).
- Manual cleanup от mailbox=6 (alexander.rodenkov): 3 заявки → `closed_lost off_topic`, mailbox `is_active=false`.

#### Известные грабли, всплывшие за сессию

- **`unset($this->request)` на Livewire 4 валит 500** при следующей перерисовке blade (null pointer). Правильно — `$this->reloadRequest()` (есть в `Detail.php` как helper). Кейс: `Detail::reparseItems` — парсер диспатчился, ответ 500. Поправлено `e3f1868`.
- **Carbon 3 `diffInMinutes` возвращает signed diff** (отрицательный, если первый аргумент в будущем). `max(0, ceil(-15)) = 0` — нужно `abs()`. Поправлено `80462d4`.
- **Symfony YAML падает на `excerpt: текст с двоеточием: ещё текст`** без кавычек. `splitFrontmatter` ловит throwable → возвращает `[]` → title использует slug-fallback. Поправлено `68ae8dd` — обязательно "" вокруг значений с `:`.
- **`{{ $slot }}` через `@include` data-массивом HTML-эскейпит View-объект.** На `{{ $slot }}` Blade автоматически делает `e($slot)` → HTML-теги превращаются в текст. Правильно — `{!! $slot !!}`. Кейс: `_layout.blade.php` для docs выводил голый HTML вместо рендера. Поправлено `0d21c4d`.
- **OpenAI 429 insufficient_quota — основной фактор хаоса в течение дня.** Категоризатор молча возвращал empty (без error-лога), все письма копились в backlog. Решено циркуит-брейкером + bell-алертом. **TODO для оператора:** проверять `platform.openai.com/account/billing` при первом ⛔.
- **`scopeSyncable` правильно фильтрует на scheduler'е**, но `mail:sync --mailbox=N` обходит. Личный ящик директора стабильно подключался и синкал закупочную переписку → фейковые клиентские заявки. Двойная защита: guard в Job + UI запрет.
- **3-я по счёту повторная встреча с `git pull от root → owner=root → permission denied`** (см. MEMORY 2026-05-15 уже зафиксировано). За сессию словил 3 раза, лечил `chown -R www-data:www-data <субдиректория>` + `git reset --hard origin/main`. TODO: всё-таки сделать pre-hook на проде, в одном из последующих deploy.
- **`Request::isAccessibleBy` есть** (`app/Models/Request.php:443`) — проверка прошла, метод корректно работает.

#### Что вынесено в scheduled (`routes/console.php`)

- `mail:categorize --all --limit=50` — каждые 5 минут.
- `mail:relink-deferred --apply --limit=50` — каждые 5 минут.
- `quotes:reboost-stuck-decisions --apply --limit=50` — каждые 15 минут.

#### Открытые вопросы / TODO

- Скриншоты в гайдах остаются текстовыми описаниями + `.doc-preview` HTML-блоки. Реальные скриншоты от пользователя — на будущее.
- Гайд для секретаря не написан. Сейчас секретарь видит общие гайды + `rop/mail-review`. Возможно, нужен отдельный `secretary/inbox.md`.
- KB-гайд (для директора) — нечего писать, KB-модуль не задеплоен (Phase 2 LazyLift drop-in).
- Smoke-тест weighted-распределения — `Курзаев weight=200 load=39 effective=19.5` минимум, побеждает. Пользователь принял подход A (накопительный эффект, без принудительной нормализации).

#### Файлы / структура

Новые директории:
- `app/Services/Support/` (SupportTicketService)
- `app/Services/AI/` (OpenAiCircuitBreaker)
- `app/Services/Docs/` (DocsService)
- `app/Support/Docs/` (DocPage, DocSection)
- `app/Notifications/` (SupportTicketReplyNotification, OpenAiCircuitOpenedNotification)
- `app/Livewire/Support/` (NewTicketModal, MyTickets, Inbox, TicketView, Trigger)
- `resources/docs/{common,manager,rop,director}/` — 14 markdown
- `resources/views/livewire/support/` — 5 blade
- `resources/views/docs/` — 5 blade (index, show, _layout, _index-content, _show-content)
- `public/images/contact-creator{,-banner}.svg`

Новые миграции:
- `2026_05_30_120000_create_support_tickets_table.php` (+ messages, attachments)
- `2026_05_30_180000_add_load_weight_to_users_table.php`

Новые env:
- `SUPPORT_DEVELOPER_EMAIL`, `SUPPORT_DEVELOPER_NAME`
- `OPENAI_CB_FAIL_THRESHOLD=3`, `OPENAI_CB_COOLDOWN_MINUTES=15`, `OPENAI_CB_NOTIFY_COOLDOWN_MINUTES=60`

---

### Сессия 2026-05-22 — Mailbox::syncable, first-time watermark, phantom link bug cleanup

**Контекст:** Александр Роденков (директор) был замечен как «магнит» для фантомных заявок. UI заявки M-2026-0019 показывал «через alexander.rodenkov@myzip.ru», хотя по бизнес-роли его почту синкать не нужно. Расследование вскрыло три независимых проблемы и привело к большому cleanup'у.

#### 1. `Mailbox::syncable()` — фильтр ящиков по роли владельца

Personal-ящик идёт в IMAP-синк только если у владельца есть managerial-роль (`manager` / `head_of_sales`). Личные ящики директора, секретаря, админа — не читаем, даже если `is_active=true` и OAuth валиден. При смене роли владельца (Director→HeadOfSales) фильтр тут же подхватывает ящик без правки `is_active` или повторной авторизации. Реализовано через DB-scope (JOIN на `model_has_roles`/`roles`), без N+1.

Использовано в `MailSyncCommand` (`Mailbox::query()->syncable()`). Опция `--mailbox=ID` намеренно обходит фильтр для ручной отладки. `SyncMailboxFolderJob` принимает конкретный mailbox_id и фильтра не требует.

**Commits:** `58f1c2a`, `49540aa` (fix Shared vs General опечатки в enum).

#### 2. First-time watermark в `SyncMailboxFolderJob`

При первом подключении ящика (`sync_count=0 AND last_uid_seen=0 AND !uidValidityJustReset`) job ставит `last_uid_seen = MAX(UID)` папки и выходит без processing. Старая история остаётся на IMAP-сервере, в `email_messages` не льётся, `MailRouter::route` на ней не вызывается, LLM не дёргается. Семантика: «читаем только то, что приходит после подключения».

UIDVALIDITY-сброс (server-side смена валидности) идёт отдельной веткой — там last_uid_seen=0 в state с sync_count>0; флаг `$uidValidityJustReset` исключает такой кейс из first-time, полный resync известного ящика работает штатно.

Для ретроспективного забора конкретного письма — отдельный путь (`mail:reingest-uid` / ручной `--since-uid`).

**Commit:** `a61ad17`.

#### 3. Phantom link bug в `OutgoingMailLinker` L4 (главное расследование)

При синке Sent ящика директора 62 outbound-письма прилипли к 10 чужим Request через старый L4 fallback (см. соответствующую граблю выше). Анализ показал две корневых причины:

- **L4 был жадным:** искал любую открытую Request с `client_email IN recipients`, при множественном match — pick latest без проверки subject / времени / thread-relevance.
- **«Магниты» — 35 фантомных Request с `client_email=коллега@myzip.ru`** (`info@`/`noreply@`/`order@` и реальные коллеги). Эти Request родились ещё до фикса `InternalSenderDetector` (M-2026-0161) — `IncomingMailProcessor` создавал заявку из внутреннего письма с `from_email` коллеги, и `client_email` записывался как этот коллега. Каждый outbound, в чьих `to/cc` был тот же коллега, через L4 цеплялся к этому магниту.
- **Каскад через L1/L2:** один раз привязавшись через L4, письмо начинает «магнитить» через References — все будущие письма с этим Message-ID в References наследуют привязку.

**Фикс (commit `8883717`):** три guard'а в L4. Детали — в грабле выше.

**Cleanup БД:**
- 62 phantom outbound отвязаны от Request (письма из mbox=6 без настоящего parent в БД и без M-code в subject).
- 27 активных Request с internal `client_email` (@myzip.ru) → `closed_lost / off_topic`, audit-event `cleanup.internal_phantom`, comment с пояснением. Ещё 8 таких Request уже были `closed_lost` — итого 35 «магнитов» обезврежены.
- 58 писем mbox=6 остаются привязанными — это legit L1/L2/L3 матчи (настоящий parent в БД или M-code в subject).

**Открытые задачи (отдельная сессия):**
- **Supplier-blacklist в категоризаторе.** Кейс #1396/#1397 (Zagro AG / Angelo Zani): supplier-correspondence на английском (`«Hi Alexander, can you offer it?»`) категоризатор принял за `client_request` с confidence 0.9-0.95. Нужен либо blacklist доменов (`zagro.ch`, `oss-elevator-parts.com`, `escalatorparts.cn`), либо доработка промпта (учёт направления сделки).
- **3 Request с supplier client_email** (#19 `steven@oss-elevator-parts.com`, #244 `kid@escalatorparts.cn`, #1397 `angelo.zani@zagro.ch` — последняя уже закрыта). Их L4 guards не зацепят (фикс задеплоен), но они активные. Глазами посмотреть и решить.



### Сессия 2026-05-21 (ночь) — пред-релизный sprint: UID MOVE, admin-role, catalog FK, поиск, photo proxy

**Контекст:** подготовка к боевому запуску. Тестовый ящик `mail@myzip.ru` + тестовые менеджеры (man1/man2/man3) надо заменить реальной почтой `info@myzip.ru` + боевыми менеджерами. По ходу всплыли проблемы с производительностью поиска, кодировкой вложений, дубликатами писем в IMAP.

#### 1. Mail routing — атомарный UID MOVE (RFC 6851)

Раньше `MailFolderRouter::routeToManager` делал только COPY (без MOVE/EXPUNGE — webklex 6.x давал «BAD CLIENTBUG EXPUNGE Wrong session state» при попытке чистого MOVE/COPY+EXPUNGE на Yandex). Оригинал оставался в INBOX, помечался `\Seen`. В UI секретарь видел дубликат: одно письмо в INBOX (read) + одно в `MZ|Lastname` (unread).

**Проверено через `mail:try-move`** (новая команда): Yandex 360 поддерживает `MOVE` в CAPABILITY и атомарно выполняет UID MOVE — оригинал физически удаляется из INBOX, копия с новым UID появляется в target. Ответ: `OK [COPYUID <uidv> <src> <dst>] / N EXPUNGE / OK UID MOVE Completed.`.

**Защита от ложного OK:** Yandex возвращает «OK [CLIENTBUG] UID MOVE Completed (no messages).» с boolean=true даже когда UID не найден. Реальный признак успеха — наличие `COPYUID` в ответе. Без COPYUID считаем no-op, БД не трогаем.

После успешного MOVE обновляем `email_messages.folder = $targetPath` + `imap_uid = $newUid` (распарсенный из COPYUID).

**Также в SyncMailboxFolderJob:**
- POST-FIX (откат `\Seen` после body-fetch) теперь только для **личных ящиков** (`MailboxType::Personal`). На общих не трогаем флаги, чтобы не сломать routing для секретаря.
- 6-й параметр `Connection::store` — это флаг режима UID/MSGN (`IMAP::ST_UID=1`). Раньше передавали `null` → команда уходила обычным STORE по sequence number, могло попадать на чужие письма.

**Commits:** `460bdcc`, `bd27bce`, `bb87afe`, `8965879`, `431ba7d`, `0270fa3`, `da94338`.

#### 2. Email signature v2 — шаблонная подпись для всех outbound

`EmailSignatureService` собирает HTML+plain подпись из `config('services.company.signature')` (tagline, телефоны, ЭДО, websites) + User-поля (`name`, `name_en`, `phone`, `phone_extension`, `mobile_phone`). Logo встроен как `data:image/svg+xml;base64,...` (внешний URL `logo_url` блокируется почтовыми клиентами).

Profile-форма расширена: `/profile` теперь редактирует `name_en` + телефоны менеджера. Legacy `email_signature` поле осталось как override (если заполнено — отдаём как есть).

`OutgoingMailMimeBuilder::formatSignature()` теперь делегирует в `EmailSignatureService::render()`.

**Commits:** `4ce55b8`, `47481bc`, `6ebe02b`.

#### 3. Роль `admin` + UI «Ящики»

Новая роль в `Role` enum + миграция `add_admin_role`:
- Admin видит всё что директорат (route middleware `role:head_of_sales,director,admin`)
- Только admin может назначать/редактировать admin-учёток. РОП/директор не видят admin-юзеров вообще в `/dashboard/managers` (фильтр в `Index::users`).
- Защита в `Editor::save()` + `mount()` от обхода UI.

**Страница `/dashboard/mailboxes`** (admin-only) — управление shared-ящиками:
- Список с состоянием (active, last_sync, error, tokens/expiry)
- Создание: OAuth flow с verification-code ИЛИ app-пароль
- Per-row: тест соединения, активация/деактивация, переподключение, обновление credentials

Подключение и активация/деактивация основной почты доступны строго админу — РОП/директор не могут случайно остановить распределение заявок.

**Где admin был добавлен** (везде, где раньше director имел доступ):
- `routes/web.php` — два middleware-блока
- `Pool`, `Detail`, `ReassignDialog`, `Quotations\Editor`, `Dashboard\Index`, `Admin\Settings\Index`
- `Models\Request::isAccessibleBy`
- `Services\Request\{State,Pause,Merge}Service::ensure...`
- `Services\Catalog\RequestItemEditor::ensureCanEdit`
- `Controllers\QuotationPdfController`, `AttachmentController`
- `views/livewire/requests/detail.blade.php` (все hasAnyRole)

**Команда `system:cleanup-test-data`** (`--apply`, директораты+админы сохраняются автоматически). Удаляет операционные таблицы, юзеров без role=director|admin, их личные ящики; деактивирует shared-ящики; сохраняет каталог/KB/правила/настройки.

**Commits:** `db1fe44`, `7fa6c87`, `0d6f254`.

#### 4. Catalog FK `equipment_category_id` + backfill через rule + LLM

**Проблема:** фильтр «Тип запчасти» в каталог-поиске работал по `synonyms substring-matching` против `name + unit_name + part_type` каталога. Морфологически хрупко — «тяговые цепи» в каталоге не матчилось с «тяговая цепь» synonym. M33763 при выборе категории #14 «Тяговая цепь эскалатора» выбрасывался из выдачи.

**Решение:**
- Миграция `add_equipment_category_id_to_catalog_items` (FK, nullable, ON DELETE SET NULL, GIN index).
- `CatalogItem::equipmentCategory()` relation.
- `CatalogItemCategorizer` сервис — двухэтапный:
  1. Rule-based: substring synonym match с приоритизацией по числу хитов. Имя категории = +3, synonym = +1. Тие-брейк → LLM.
  2. LLM (gpt-4o-mini, JSON response_format) с полным списком активных KB-категорий. Confidence < 0.6 → NULL.
- `ClassifyCatalogItemPrompt` — system с правилами (приоритет part_type, разделение оборудование/деталь, комплекты → основная деталь, бренд игнорируется).
- Команда `kb:backfill-categories`:
  - `--apply` / `--dry-run` (default)
  - `--rule-only` (без LLM)
  - `--reclassify`, `--limit`, `--sku`
  - **`--by-part-type`** — группирует SKU по уникальным part_type, делает 1 LLM-вызов на тип → bulk UPDATE всех SKU. Для нашего каталога 30k SKU → 193 уникальных part_type → 50× меньше вызовов (~$0.04 вместо $2).
- `chunkById` вместо `chunk` (классический Laravel bug: при апдейте записей в WHERE NULL обычный `chunk()` теряет строки).
- Search filter переписан: точный FK-матч → если `equipment_category_id` стоит и != target → режем. Fallback на substring synonym match для legacy SKU с NULL FK.

**Результат:** ~99% покрытия каталога после rule + LLM. M33763 → #14 «Тяговая цепь эскалатора».

**Commits:** `31c1761`, `e83a5e9`, `56b2f87`.

#### 5. Catalog search — морфология, токенизация, фильтр шума

Несколько последовательных оптимизаций по реальным жалобам:

1. **Русские окончания** (`stemRussianPhrase`/`stemRussianWord`): «цепь» / «цепи» / «цепью» → «цеп» для слов ≥4 букв. Не трогает латиницу/цифры.
2. **Запятая в strip-наборе**: `regexp_replace(name, '[\s\-_./,]', '', 'g')` + миграция пересоздаёт GIN trgm индекс с новым паттерном. Кейс «L119,7».
3. **Per-word нормализация** (commit `8ea59f3`): убран `\s` из strip-set. Раньше `name_norm` склеивал всё в одно слово, word_similarity коротких токенов (`t135`, `l1197`) против такой простыни давал 0.4-0.5. Теперь пробелы между словами сохраняются, каждый токен ищется отдельно.
4. **Per-token trigram через UNNEST** — заменено на **literal-OR** (commit `75f5949`): EXISTS UNNEST не использовал GIN индекс (nested loop), давал 1.6с. После переписывания на `'tok1' <% expr OR 'tok2' <% expr OR ...` — 77мс (×20).
5. **AVG вместо MAX** для скоринга (commit `843c7d8`): позиция с большим числом совпавших токенов ранжируется выше. Раньше M21366 «Цепь батарея роликовая огибная» (один токен «цепь» 1.0) бил M33763 (4 токена по 0.75-1.0).
6. **Vector noise filter ≥0.50** (commit `c173759`): если позиция нашлась ТОЛЬКО через vector (без code/trgm), требуем cosine ≥ 0.50. Multi-source не фильтруется. Кейс: «барабан» → «башмак кабины» на 0.30 (отсекается).

Также добавлено: `Schedule::command('catalog:embed')` через 5 минут после каждого `catalog:sync-from-url` (раньше не было в scheduler → embedding для новых SKU не создавался, M33763 не находился через vector).

**Финальные цифры:** `trigramTopN 77мс` (после прогрева), `vectorTopN 293мс`. Hybrid pool возвращает M33763 на позиции #2 при запросе «Цепь T-135 L119,7» с sim=0.92.

**Commits:** `ff060fd`, `e48cd01`, `420bd12`, `843c7d8`, `8ea59f3`, `75f5949`, `c173759`.

#### 6. Catalog photo proxy с дисковым кэшем

`catalog_items.photo_url` указывает на внешний `https://mylift.ru/photo.php?id=GUID` — 302 redirect на CDN. ~500-800мс на фото × 20 thumb в диалоге = 10+ секунд waterfall.

Route `GET /img/catalog/{id}` → `CatalogPhotoProxyController`:
- При первом обращении скачивает с внешнего URL, сохраняет в `storage/app/public/catalog-photos/{id}.bin` + Content-Type рядом.
- На повторных запросах отдаёт прямо с диска + `Cache-Control: public, max-age=2592000, immutable` (30 дней) — браузер тоже кеширует.
- Безопасность: route принимает только `catalog_item.id` из БД, photo_url берётся оттуда (SSRF исключён).
- Placeholder 1×1 PNG если внешний сервис недоступен (TTL 1 час).

Blade-views (`_search-results-table.blade.php`, `item-catalog-link-dialog.blade.php`) переведены на `route('catalog.photo', $cat->id)` для thumbnail'ов. Original photo_url оставлен в `href` ссылок «полный размер».

**Commit:** `900d15f`.

#### 7. Mojibake в именах вложений

Кейс с liftway-ботом: filename присылается как сырые UTF-8 байты в Content-Disposition без RFC 2047 wrap → webklex интерпретирует как Latin-1 → «Đ¾Ñ‚Ñ‡ĐµÑ‚.pdf» вместо «отчет.pdf».

`MessagePersister::recoverMojibake` — после `decodeMimeHeader` проверяет признаки mojibake («Ð»/«Ñ»/«Đ» + диакритика рядом). Если есть — re-encode в Latin-1 (получаем сырые байты), читаем как UTF-8. Если результат содержит кириллицу — возвращаем.

Не трогает корректные UTF-8/ASCII имена. Применяется при персисте новых писем; существующие битые имена чинятся командой `mail:redecode-attachment-names-from-raw`.

**Commit:** `f7cabfb`.

#### Готово к запуску

| Что | Статус |
|---|---|
| Mail routing UID MOVE | ✅ задеплоен |
| Email signature v2 | ✅ задеплоен |
| Role admin + UI ящиков | ✅ задеплоен |
| Catalog FK + backfill | ✅ ~99% покрытие |
| Catalog search (morphology + tokens + filter) | ✅ M33763 на #2, 77мс |
| Photo proxy | ✅ |
| Mojibake recovery в attachment names | ✅ |
| **Cleanup test data** | ⏳ ждёт apply |
| Подключение info@myzip.ru | ⏳ ждёт админа через UI |
| Реальные менеджеры | ⏳ |

**Открытые вопросы:**
- Vector embedding для M33763 даёт всего cosine 0.45 (он не в top-100 vector). Не критично т.к. trigram его поднял. На будущее: посмотреть `buildEmbeddableText` — что эмбеддится для каталога, можно ли улучшить (добавить артикулы, расширить контекст).
- 77 part_type без категории после LLM прогона — возможно нужно расширять KB или понижать confidence threshold.

---

### Сессия 2026-05-21 (вечер) — brand-hallucination фикс, Path A/B/C + Photo Vision classifier + UI

**Контекст:** проверка на реальной заявке `M-2026-1147` (Лифт Schindler №7909814, кнопка вызова + масленка) обнаружила цепочку багов:

1. Парсер записывал `parsed_brand="ЩЛЗ (Щербинский Лифтовый Завод)"` для кнопки вызова на Schindler-лифте. В письме и на фото шильдика лифта ЩЛЗ нигде нет — чистая галлюцинация LLM.
2. `RequestContextAnalysisService` возвращал `equipment_units: []` для **всех** заявок, потому что использовал несуществующие поля `Request::source_body` / `source_subject`. LLM получала пустую строку → ничего не извлекала.
3. Reply «по масленке — это масленка на противовесе» не применялся к существующей позиции — `decideClarifications` требовал structured items от парсера, а тут был чистый free-text.
4. Photo-слоты («Фото шильдика», «Фото кнопки спереди», ...) никогда не заполнялись — n8n-флаг `data_source='photo'` исчез, бланкетная логика умерла.

**Сделано:**

1. **ParseItemsPrompt + Vision-промпт inline в `RequestItemParsingService` — секция `brand` переписана** (`8208abe`, `829813a`, `20bf6eb`):
   - Убрана двусмысленность «марка лифта ИЛИ производитель детали».
   - Новая логика: (1) явно в позиции / на шильдике детали → берём; (2) OEM-fallback от шапки группы в письме («для Лифт Schindler …» → brand=Schindler для всех позиций группы); (3) null. Третий шаг — НИКОГДА не угадывать.
   - Убран список «Otis, Kone, Schindler, …, ЩЛЗ, …» — он работал как pool кандидатов для модели.
   - Запреты сформулированы абстрактно (про сам тип поведения — угадывание по типу детали / региону), без конкретных имён.

2. **RequestContextAnalysisPrompt — добавлено правило «шапки групп позиций»** + few-shot пример `[Schindler] [№7909814] [4 эт]` с двумя позициями + второй пример с несколькими лифтами. `position_to_unit_assignments`: при одной шапке привязывать все позиции, при нескольких — по физическому расположению.

3. **`RequestContextAnalysisService::analyze` — критический фикс body** (`64ba2e2`): `Request` не имеет полей `source_body`/`source_subject` — добавлен метод `resolveEmailContent()` который тянет `body_plain` (с fallback на strip_tags `body_html`) из связанного `EmailMessage`. Без этого фикса контекст-анализ всегда работал «вслепую» для всех заявок.

4. **`RequestItemPersister` — auto-apply при reparse исходного письма** (`f24fc16`):
   - Если `source_email_message_id === request.email_message_id` (это reparse, не reply от клиента) → принудительно auto-apply clarifications независимо от confidence.
   - `applyClarificationToItems` получил флаг `$isReparseOfOriginal`: при reparse разрешена перезапись `parsed_brand` даже когда он не пуст (это улучшение, не конфликт).

5. **`DecideClarificationsPrompt` — bias на clarification + `refined_name`** (`e560aba`):
   - Раньше при сомнениях возвращал `new` → плодил дубликаты («масленка, направляющая 16 мм» + «Масленка для башмака противовеса» как две позиции вместо одной).
   - Переписана преамбула: clarification включает место установки, назначение, контекст. Правило предпочтения: одна и та же категория + ссылка клиента («по масленке…») → всегда clarification. Default при сомнениях развёрнут на clarification.
   - Добавлено поле `refined_name` — LLM может вернуть объединённое имя позиции. `RequestItemPersister::applyClarificationToItems` обновляет `parsed_name` по refined_name.

6. **Path C — `FreeTextReplyEnricher`** (`77d58a4`, `75cd87f`):
   - Новый сервис + промпт `EnrichExistingItemsFromReplyPrompt`. Срабатывает в `ParseRequestItemsJob` когда парсер вернул пустой `items[]` для reply, related_request_id заполнен, активного `ClarificationBatch` нет.
   - LLM смотрит на existing items + body reply, возвращает `[{item_id, field, value, source_quote, confidence, reasoning}]`. Пишет в существующий канал `enrichment_suggestions[]` в `quality_assessment_payload` — UI блок «Предложенные уточнения» автоматически подхватывает.
   - Фикс «применить ничего не меняет»: ENRICHABLE_FIELDS использовало `note`, а `RequestItemEditor::EDITABLE_FIELDS` знает только `supplier_note` → тихий return без изменений. Заменено на `supplier_note` + backwards-compat мапинг `note→supplier_note`.
   - Защита от дублирования: метод `contextAlreadyInName` — если ≥70% значимых слов value уже есть в `parsed_name`, suggestion пропускается (избегаем «note: на противовесе» когда имя уже «масленка на противовесе»).

7. **Photo Classifier (Vision-классификатор фоток)** — Phase 3 в `ResolveKbJob` (`ed89902`, `3ffb94d`, `776f320`):
   - Миграция `email_attachments.metadata` jsonb для `kb_slot_candidates[]`.
   - `PhotoClassifierPrompt` + `PhotoSlotClassifierService::classifyForRequest(Request)` — **один Vision-вызов на всю заявку** (photo-centric v2; v1 был item-centric с N вызовами и противоречиями).
   - На вход модели: список позиций (item_index, name, brand, category_name, photo_slots категории) + все image-аттачменты треда (до 12 за вызов). На выход: `assignments[]` с `{image_index, item_index, slug, confidence, status: matched|other|irrelevant, description}`.
   - matched + confidence≥0.6 → `extracted_parameters[$slug]=true` для позиции + запись в `EmailAttachment.metadata.kb_slot_candidates`. UI (см. ниже) рендерит превью.
   - На M-2026-1147 кнопка вызова получила 5 matched из 8 фоток (4× `photo_button_back` включая шильдик SCH-5550287, 1× `photo_button_front`); фото лифтового шильдика Schindler корректно помечено `irrelevant`.

8. **UI — превью фоток на photo-слотах** (`3ffb94d`):
   - `PositionSlotResolver` расширен полями `is_photo` и `photo_attachments[]` — для photo-параметров лениво подтягивает `EmailAttachment.metadata.kb_slot_candidates` и группирует по slug.
   - `_position-card.blade.php` — на photo-слотах вместо «да» рендерит до 4 миниатюр 36×36 с lightbox через `open-image` dispatch. Tooltip = filename + confidence + Vision-описание.

9. **UI — список заявок (`pool.blade.php`)** (`4841357`):
   - Описание заявки получает приоритет (1fr). Колонка СУММА убрана (всегда пусто до Phase 3). СТАТУС + СОБЫТИЕ объединены в одну ячейку. Сложность переехала в код-ячейку — компактная цветная точка с tooltip. Под кодом — дата создания. Колонка КЛИЕНТ перенесена после кода, перед описанием. Сетка: `24px 130px 170px minmax(280px,1fr) 200px 150px 80px 32px`.

10. **UI — позиции** (`fbcb17b`):
    - `canEditItems` через `Request::isAccessibleBy()` (включает делегирование).
    - Снят гейт `! $isCatalogBound` для slots grid + quick-chips: даже для catalog-bound позиций менеджер может хотеть запросить «фото шильдика» / «марку лифта».

11. **CLI-инструменты** (`0cc9df0`+):
    - `php artisan inspect:request {code}` — позиции, payload, тред, бренды в БД.
    - `php artisan parse:dry-run {code}` — прогон парсера + контекст-анализа без записи в БД.
    - `php artisan parse:debug-reply {message_id}` — подробный разбор reply: body, существующие позиции, items от ParseItemsPrompt, decisions от decideClarifications + reasoning + refined_name.
    - `php artisan path-c:test {message_id}` — прямой запуск FreeTextReplyEnricher.
    - `php artisan photo:classify {item_id}` — отдельный запуск photo-классификатора для одной позиции.
    - `php artisan request:reparse {code}` — перепрогон всей заявки (обновлено: **по всем inbound-сообщениям треда в хронологическом порядке**, не только исходному). Опции `--queue --reset --no-kb --only-original`.

**Известные ограничения:**

- **DetailedCategoryRefiner не покрывает «масленку»** — для позиции «масленка, направляющая 16 мм» `qa.reason='detailed_category_not_resolved'`, `identification_category_id` остался NULL → photo classifier и slot grid у этой позиции no-op. Нужно расширять seed-данные KB (новая категория «масленка/направляющая»). Не блокер.
- **Backfill старых заявок не делали** — старые заявки с галлюцинированными брендами останутся как есть до ручного `request:reparse` каждой. Пользователь принял решение не делать массовый backfill — главное чтобы новые заявки обрабатывались корректно.
- **Vision-OCR ошибки** — артикул `SCH_55502867` Vision прочитал как `SCH-5550287` (потерян 0/8, `_`→`-`). Это про точность модели, не про промпт. Промпт `detail: 'high'` уже стоит.

**Триггер на новые письма (без изменений в pipeline):**
```
inbound → MailRouter → ParseRequestItemsJob (Vision извлекает позиции из фоток)
                          → RequestItemPersister (sticky-routing, status, dispatch ResolveKbJob)
                             ├ RequestContextAnalysisService (Phase 1, контекст с шапкой)
                             ├ QualityAssessmentService (Phase 2, по каждой позиции)
                             └ PhotoSlotClassifierService (Phase 3, ОДИН Vision-вызов)
```

Для reply (msg.related_request_id != null, нет активного ClarificationBatch):
- `ParseRequestItemsJob` → `decideClarifications` (если items не пуст) → auto-apply / pending_clarifications с refined_name.
- Если items пуст → `FreeTextReplyEnricher` (Path C) → enrichment_suggestions для UI «Предложенные уточнения».

### Сессия 2026-05-21 — UI положения каталога + мерные позиции (фаза 1)

**Контекст:** после bulk-resolve C-step (231 матч из 1256) переключились на улучшение UI заявок и точности расчётов.

**Сделано:**

1. **UI: lead_time + is_price_actual на сматченных позициях** (коммит `75c3238`):
   - `_item-row.blade.php` и `_position-card.blade.php` — в ячейке «Цена» добавлен ⚠ амбер-значок, если `is_price_actual === false`; в title добавлено «цена не актуальна».
   - В ячейке «Наличие» — когда `stock <= 0`, ниже «нет» появляется маленький блок `{lead_time_days} дн` с tooltip «срок поставки под заказ». Параллель с UI catalog-search.
   - Поля проверены в модели: `CatalogItem` имеет casts `is_price_actual=>boolean`, `lead_time_days=>integer`, `last_imported_at=>datetime`. Eager-load `catalogItem` уже выполняется.

2. **Мерные позиции — Фаза 1** (коммит `c375c23`):
   Кейс M-2026-1287: клиент пишет «поручень — 43,56м — 2 шт», цена в каталоге 4 199 ₽/м. Раньше total = price × qty = 8 398 ₽, занижение в 44 раза (правильно 365 814.88 ₽).

   **Архитектура:**
   - Каталог НЕ хранит unit-of-measure (поле `unit_name` оказалось «Узел» из MDB — функциональная группа, не единица). Mерность детектится только из RequestItem (от LLM-парсера).
   - Источник истины о мерности — RequestItem, написал ли клиент вторую размерность.

   **Изменения:**
   - **Миграция** `add_length_fields_to_request_items`: `parsed_length` decimal(12,3), `parsed_length_unit` string(16), `billing_unit` string(16).
   - **`RequestItem` модель** — добавлены: `$fillable` + `casts['parsed_length'=>'decimal:3']`, методы `isMeasured()`, `effectiveUnit()`, `effectiveQty()`, `total()`. Единая формула: если `billing_unit === parsed_length_unit` → `qty × length`, иначе `qty`.
   - **`ParseItemsPrompt` v6** — раньше LLM писал «43.56 м каждый» текстом в `note`, теперь структурированно `length`+`length_unit`. `note` освобождён для свободных пометок. Добавлен Пример 4а (поручень 43.56м × 2 шт).
   - **`RequestItemParsingService`** (normalizer) + **`RequestItemPersister`** (create) — нормализуют и персистят новые поля. Защита: length без unit → null.
   - **`RequestItemEditor::EDITABLE_FIELDS`** расширен на `parsed_length`, `parsed_length_unit`, `billing_unit`. `normalizeFieldValue` обрабатывает decimal + string-16.
   - **UI `_item-row.blade.php` + `_position-card.blade.php`**:
     - qty cell: «2 шт. × 43.56 м» для мерных;
     - price cell: «4 199.00 ₽ / шт.» (dotted underline = clickable);
     - клик по «/ шт.» → x-data dropdown шт./компл./м/п.м./м.п./кг/л + parsed_unit + parsed_length_unit. Выбор → `wire:click="editItemField($id, 'billing_unit', $unit)"` (использует существующий generic-метод).
   - **Total** теперь рассчитывается через `$item->total()` → автоматически пересчитывается при смене billing_unit.

   **НЕ сделано (отложено):**
   - **Quotation pipeline** (`QuotationItem.unit_quantity` + `QuotationService::recalcTotals` учёт length) — Фаза 2. Сейчас КП клиенту считается по старой формуле `price × qty`, и при смене billing_unit в заявке расхождение НЕ пробрасывается в КП.
   - **Backfill старых заявок** (regex по `supplier_note` для извлечения length) — опционально, можно потом.
   - **`ItemEditDialog`** — modal-edit не получил inputs для `parsed_length`/`parsed_length_unit`. Менеджер может править только через inline-dropdown билинг-юнита. Manual ввод длины — позднее.

   **Проверка на M-2026-1287:**
   ```bash
   php artisan migrate
   php artisan view:clear
   php artisan tinker --execute="
   \$it = App\Models\RequestItem::where('catalog_item_id', App\Models\CatalogItem::where('sku','M14761')->value('id'))->first();
   \$it->parsed_length = 43.56; \$it->parsed_length_unit = 'м'; \$it->save();
   dump([\$it->effectiveUnit(), \$it->effectiveQty(), \$it->total()]);
   "
   ```
   В UI: qty «2 шт. × 43.56 м», цена «4 199.00 ₽ / шт.» (dotted underline). Клик → «м» → total = 365 814.88 ₽.

**Открытые вопросы для следующей сессии:**
- Quotation pipeline — пробросить effective_qty в QuotationItem (`QuotationService::autoFillItemsFromRequest` + `recalcTotals`). Унификация с `OutboundQuoteItem` (у него уже есть `unit_quantity`).
- ItemEditDialog — добавить inputs для parsed_length / parsed_length_unit (чтобы менеджер мог вручную задать мерность, если парсер не вытащил).
- Прогон свежих заявок с мерными товарами — убедиться, что v6 prompt правильно вытаскивает `length`+`length_unit` для канатов/ремней/цепей.

### Сессия 2026-05-05 (вечер)
Инфраструктура: skeleton Laravel 12, composer.json/lock, миграция pgvector tolerant, nginx vhost для `mzcorp.ru`. Прервалась перебоем электричества до активации vhost.

### Сессия 2026-05-06 (утро)
Восстановили контекст. Обнаружили: код частично залит на VPS, vhost не активирован, default nginx отдавал «Welcome to nginx!». Завершили деплой:
- Переключили origin на HTTPS на VPS (был SSH-alias, не резолвился под www-data).
- Подтянули последний коммит, активировали vhost.
- Поставили Let's Encrypt + HTTPS + redirect.
- Настроили supervisor для queue worker и cron для scheduler.
- Beget включил pgvector в whitelist по запросу — sanity-test пройден.
- Импортировали MyLift Design System в `design/`.

### Сессия 2026-05-06 (день) — Phase 1.1–1.4
- 1.1 — `CLAUDE.md`, `MEMORY.md`, `README.md` написаны.
- 1.2 — `laravel/breeze` (blade) + `spatie/laravel-permission`, 4 роли (`manager`/`head_of_sales`/`secretary`/`director`), 4 тестовых юзера, локализация `lang/ru.json`, register/email-verify убраны. На VPS поставлен Node.js 20 + npm, ассеты собраны. Login работает.
- 1.3 — `Mailbox`, `MailboxFolderState`, `EmailMessage`, `EmailAttachment` + миграции + enum'ы (`MailboxType`, `MailDirection`).
- 1.4 — `webklex/laravel-imap` + **OAuth 2.0 / XOAUTH2** для Yandex 360. Изначально планировались App passwords, но они отключены в корп. аккаунтах Yandex 360 — пришлось сразу делать OAuth (Phase 1.12 ушёл в 1.4):
  - `YandexOAuthService` (authorization URL, exchange, refresh, ensureFreshToken).
  - `OAuthYandexController` + routes для HTTP-callback flow.
  - `mail:oauth` CLI как fallback для verification_code flow (когда HTTP redirect не разрешён в OAuth-приложении Yandex).
  - В нашем приложении `MyZiP_AI` (client_id 94c0247…) Yandex прописал `https://oauth.yandex.ru/verification_code` как Redirect URI — пользуемся out-of-band flow через `mail:oauth code`.
  - `MailboxConnector` поддерживает оба пути аутентификации.
  - `SyncMailboxFolderJob` — per-folder UID-incremental sync с FT_PEEK (не ставим `\Seen`), UIDVALIDITY tracking, идемпотентность по `(mailbox, folder, message_id)`.
  - `MessagePersister` — маппинг webklex Message → EmailMessage + сохранение вложений в storage. MIME-decoder имён файлов.
  - `mail:add`, `mail:test`, `mail:sync` команды + scheduler `*/2 * * * *`.

#### Подключённые ящики на 2026-05-06
- `mail@myzip.ru` (mailbox id=1, type=shared, auth=oauth) — синкается, в БД 178+ писем, 197+ вложений.

#### Настройки OAuth-приложения Yandex (зафиксировать на будущее)
- Имя: `MyZiP_AI`
- ClientID: `94c0247012fc46e1be2f8aea77a95ef9`
- Redirect URI: `https://oauth.yandex.ru/verification_code` (Yandex не дал прописать кастомный)
- Scopes: `mail:imap_full`, `mail:smtp`
- Email админа OAuth: `rodenkov.al@yandex.ru`
- Из-за verification_code — каждый ящик подключаем через `mail:oauth url N` → ввод code в `mail:oauth code N`.

### Сессия 2026-05-06 (вечер) — Phase 1.5 → 1.11 + дизайн-система

Большая сессия, закрыли почти весь MVP Фазы 1.

**Phase 1.5 (Routing rules) — backend + UI + CLI:**
- Миграции: `mail_routing_rules`, `routed_mails`.
- Enums: `MailRuleMatchMode` (any_of/all_of/ai_classified), `MailRuleActionType` (forward/label_only/trigger_request_creation), `MailRuleField`, `MailRuleOperator`.
- `MailRoutingRuleEngine` — sequential priority-asc match, terminal early-stop.
- `MailLabelService` — webklex `addFlag()` для custom IMAP keywords (MyLift/...), мирорит в `email_messages.imap_flags`.
- `MailForwarder` — Symfony Mailer EsmtpTransport с `XOAuth2Authenticator`, переиспользует Mailbox access_token.
- `MailRouter` — orchestrator: rules → AI fallback → IncomingMailProcessor → audit. Phase 1.7 объединил с 1.5.
- CLI: `mail:rule list/preview/apply/sample/delete`.
- UI `/dashboard/mail-rules` (Livewire 4, RuleList + RuleEditor) с проверкой роли через middleware `role:head_of_sales,director`.
- Sample-правила: «Рекламации», «Бухгалтерия», «Не разобрано» (catch-all).
- Subject и `from_name` теперь декодируются от MIME (`mail:decode-backfill --apply` для исторических 176 писем).

**Phase 1.6 (AI-классификация):**
- `App\Services\AI\OpenAIChatService` — drop-in copy LazyLift OpenAIChatService (с переносом в namespace).
- Конфиг: `config/services.php → openai{api_key, base_url, proxy_key, mail_classifier_model}`.
- Подключено к LazyLift-прокси `https://ai.lazylift.ru` (общий ключ + X-Proxy-Key).
- `App\Enums\EmailClassification` (request/reclamation/accounting/general_question/spam/other).
- `App\Prompts\Mail\ClassifyIncomingPrompt` — RU system-prompt, требует JSON {classification, confidence, reason}.
- `MailClassifierService` — chat call с `temperature=0`, `response_format=json_object`. Идемпотентен по `classified_at`.
- `MailRouter` теперь делает rules → AI fallback → ещё раз rules (для match_mode=ai_classified).
- CLI `mail:classify {id}` / `--all` / `--force`.
- Распределение по 248 классифицированным письмам: request 165 (67%), accounting 33 (13%), other 24 (10%), spam 16 (7%), general_question 7 (3%), reclamation 1.
  - **NB: 33 в accounting раздуто loop-дублями** — реальных бухгалтерских меньше.

**Phase 1.8 (Request + IncomingMailProcessor):**
- Миграции: `requests`, `request_assignments`, `request_code_sequences`, FK на `email_messages.related_request_id`.
- `internal_code` формат `M-2026-NNNN`, год+counter, атомарно через UPSERT с RETURNING.
- `RequestStatus` enum: New | Assigned (полная state-machine — Phase 4).
- `AssignmentService` — round-robin по наименее загруженному менеджеру, tie-break по `last_assigned_at`.
- `IncomingMailProcessor` — создаёт Request из inbound писем с `ai_classification=request`, ставит метку `MyLift/Заявка/{ManagerLastName}` (Foundation §1.6).
- Интегрирован в MailRouter после AI-classify.
- CLI `mail:create-requests --apply` — backfill: создал 165 Request на старых 165 письмах-заявках.

**Phase 1.10 (UI «Заявки»):**
- `App\Livewire\Requests\Pool` — список с фильтром «мои/все», поиск по коду/теме/клиенту, paginate(25). Менеджер видит только свои; РОП/директор/секретарь — все.
- `App\Livewire\Requests\Detail` — карточка: исходное письмо (HTML с inline images через cid: → /attachments/cid/...), скачивание вложений, история назначений.
- `AttachmentController` с двумя методами: `download($attachment)` и `inline($emailMessage, $contentId)`. Доступ к чужим письмам — только привилегированным.
- Routes `/dashboard/requests`, `/dashboard/requests/{request}`, `/attachments/{attachment}`, `/attachments/cid/{emailMessage}/{contentId}`.

**Phase 1.11 (Дашборд РОПа v0):**
- Заменил placeholder Breeze на полноценный `/dashboard`.
- 6 KPI-плиток: всего / новых / в работе / не назначено / 24h / 7d.
- AI-распределение писем за 30 дней (горизонтальные bars).
- AI coverage % (классифицированных / всего).
- Топ-8 менеджеров по нагрузке (total + new).
- Mailbox health (traffic-light).
- Последние 8 пересылок с success/error индикатором.
- Последние 5 заявок с быстрым переходом.
- Менеджер видит свой scope (без mgmt-листов).

**Hot fix forward-loop:**
- В живом проде sample-правило «Бухгалтерия» создало бесконечный цикл: forward в `buh@myzip.ru` каким-то образом возвращался обратно в `mail@myzip.ru`, AI находил «оплата» в subject, снова форвардил.
- Защита: `MailForwarder` теперь добавляет заголовок `X-MyLift-Forwarded: 1`. `MailRouter::isLoopMessage()` проверяет этот заголовок + префикс subject `[MyLift forward]` — если совпало, пишет `routed_mails.action_taken='loop_skipped'` и пропускает все правила.
- В БД 8+ loop-копий остались (Решено: не чистим, просто отключили правило в UI).
- Правило «Бухгалтерия» сейчас off, можно безопасно включить — loop guard страхует.

**Подключённые ящики:**
- `mail@myzip.ru` (id=1, shared, oauth) — работает.
- `man2@myzip.ru` (id=2, personal, oauth) — добавлен, но **OAuth не выдан**, дашборд показывает «ошибка».
- `man3@myzip.ru` (id=3, personal, oauth) — то же.

**Тестовые пользователи (роль `manager`):**
- `manager@mylift.test` — Иванов (имеет 165 заявок).
- `man2@myzip.ru` — Менеджер 2 (0 заявок, ящик не подключён).
- `man3@myzip.ru` — Менеджер 3 (0 заявок, ящик не подключён).
- Plus rop/secretary/director.

**Phase 1.12 (Design polish) — начато, не завершено:**
- Прочитаны `design/colors_and_type.css` и `design/ui_kits/crm/02-dashboard.html`.
- Текущий UI собран на дефолтном Breeze layout с Figtree font и стандартными gray Tailwind classes — это явно отличается от макетов (Inter font, 13px base, oklch neutrals, 9-step palette, top-bar 48px + left rail 56px).
- Решение: полный redesign, поэтапно. На 2026-05-07 ушло на Phase 1.8b — отложено.

### Сессия 2026-05-07 (вечер 2 / марафон) — Phase 1.8d UI + sync bug + Pending status + Phase 1.9 inbound thread + Phase 1.8e parser v5

Большая сессия, **18 коммитов**: `3604f50` → `57915de`. Закрыли почти весь backlog Phase 1, остался только Phase 1.9 outbound (Sent-tracking).

**Phase 1.8d — UI redesign по `design/ui_kits/crm/`** (5 коммитов: `3604f50`, `41ad955`, `fadd901`, `7350cfb`, `0b4f652`):
- `resources/css/design-tokens.css` (port из `colors_and_type.css`) + tailwind.config.js extend на `var(--token)`.
- `layouts/app.blade.php` + `navigation.blade.php`: 48px sticky topbar, brand wordmark, workspace pill (live mailbox count + traffic-light dot), Inter + JetBrains Mono.
- `Livewire\Requests\Pool` + view: 36px-row table, status chips, filter chips, scope `mine/all`.
- `Livewire\Requests\Detail` + view (главное): 7 табов «Обзор / Переписка / Позиции / Поставщики / Активность / Файлы / Связанные» по `04-request-detail.html`. Hero card с code+copy, status-row, action-buttons (disabled с title=«Phase 2»). Подгрузка thread в Переписку (Phase 1.9 ниже).
- Dashboard view: 6 KPI tiles + AI breakdown bars + manager load + mailbox health + recent forwards/requests.
- Frontend bundle: 44 KB CSS / 8.6 KB gzip, 88 KB JS. `npm run build` чистый.

**Sync bug fix** (`dd102f4` + `4ccdcbc` + `ebecba8`):
- **Симптом**: на 2026-05-07 09:01 INBOX-sync `mail@myzip.ru` замер. last_uid_seen=400, sync_count=672, but no new emails в БД хотя в Yandex поток продолжается.
- **Корень 1**: `whereAll()->limit(500)->setFetchBody(true)->get()` берёт первые 500 sequence-numbers (старейшие письма) и пытается тянуть body для всех 500 за один FETCH. После того как INBOX перерос 500 — новые UIDs (seq>500) перестали попадать в выборку. Дополнительно — body fetch на 500 писем ронял worker по памяти/таймауту без записи в production log.
- **Фикс**: two-step fetch. ШАГ 1 — `$folder->getClient()->getConnection()->getUid()->validatedData()` (только UIDs списком, kB трафика). ШАГ 2 — фильтр `>= sinceUid`, обрезка до 500 свежайших, per-UID `whereUid($uid)->get()->first()` с body+flags.
- **Корень 2**: `->whereUid($uid)->first()` падал с «Method WhereQuery::whereFirst() is not supported». Webklex 6.x `WhereQuery` не имеет first() — magic `__call` транслирует имена в `WHERE<UPPERCASE>` критерий. Нужно `->whereUid($uid)->get()->first()` (first() на `MessageCollection`).
- Также **`config:cache route:cache view:cache` одной командой не работает** — artisan не принимает доп. аргументы. Зафиксировал в MEMORY и в deploy-сниппете.

**Phase 1.8d-pending — `RequestStatus::Pending` + auto ParseRequestItemsJob** (`d32c7c2`):
- Новое значение enum `Pending`. Скрыто от менеджеров в пуле (им не с чем работать без позиций).
- `App\Jobs\Mail\ParseRequestItemsJob` — обёртка над `RequestItemParsingService` + `RequestItemPersister`. ShouldBeUnique на 5 минут, timeout 180s, идемпотентен.
- `IncomingMailProcessor::processIfRequest` теперь создаёт Request **со статусом Pending**, БЕЗ assignment, БЕЗ folder routing. Dispatch'ит ParseRequestItemsJob.
- После успешного persist — `RequestItemPersister::persist` сам вызывает `AssignmentService::autoAssign()` который переводит status `Pending → Assigned` + назначает менеджера + копирует письмо в `MZ\|{Lastname}`.
- `Pool` отфильтровывает Pending для менеджеров; РОПу chip-фильтр «В обработке (N)».

**Phase 1.9 inbound thread linking** (`9353df7` + `f7aefe9` + `57915de`):
- `App\Services\Mail\InboundReplyLinker` — 5 уровней matching:
  1. **In-Reply-To** — точное совпадение со сохранённым `EmailMessage.message_id`.
  2. **References** — любой ID в цепочке (с конца — свежие первыми).
  3. **Subject `M-2026-NNNN`** — safety net для Fwd / поломанных headers.
  4. **From_email + open Requests** клиента. Гейт по `category=client_request` → не линкуем (новая заявка). При 1 кандидате → она же. При 2+ → передаётся в уровень 5.
  5. **AI multi-choice** — `ThreadClarificationAi` (gpt-4o-mini, `services.openai.clarification_model`). Source: n8n `AI Agent: Process Clarification`. На сбое fallback на самую свежую.
- `MailRouter::route` — порядок поправлен: `categorize()` ДО `tryLink()` (4-й уровень опирается на category).
- **Парсинг reply'ев**: после успешного link'а dispatch `ParseRequestItemsJob::dispatch($message->id, force: true)`. Извлекает доп. позиции которые клиент мог дописать в follow-up.
- `RequestItemPersister::persist` — category-гейт обходится если `related_request_id` уже стоит (это reply, можно добавлять items в existing Request).
- **Suspicious-link safety log**: если на reply (`force=true`, `had_items_before=true`) парсер находит >0 новых items и 0 совпадений с существующими — пишем `WARNING` с hint'ом для РОПа (потенциально ошибочный link от AI clarifier). Не отвязываем автоматически (риск ложной тревоги), но даём видимый сигнал.
- `Detail` Livewire — eager-load всего треда (`EmailMessage where related_request_id = X`), таб «Переписка» показывает цикл с разными аватарками (accent-красный для outbound) и chip-«исходящее» / category-chip. Таб «Файлы» — вложения **всего треда**.

**Phase 1.8e parser v5** (`c18279f` + `fd44cc9` + `eca7436` + `89b8959` + `81bf0a6` + `78ec391`):
- **Подключили n8n workflow** (`design/uploads/...email_classification...json` + операторские dump'ы корпуса в `design/uploads/parser-corpus.txt` и `parser-rebake-50.txt`, `regress-bodies.txt`).
- `App\Prompts\Mail\ParseItemsPrompt` — system message v5 (port n8n `AI Agent: Parse Items`). Несколько итераций:
  - первая: жёсткое «subject НИКОГДА не источник» → over-conservative, регрессия на 9 кейсах.
  - финальная (`78ec391`): permissive с **позитивными правилами** + 8 few-shot positive examples из реального корпуса. Subject правило теперь situational: «обычно метка, НО если subject содержит явный артикул+qty (как "DAA20220B17 1 штука") — извлекай». Раздел «КОГДА items: []» — только 4 чётко описанных случая.
- `App\Services\Mail\EmailTextCleanerService` — PHP-порт трёх JS-функций из n8n `Code: Prepare Parse Input`:
  - `extractForwardedContent` — изоляция «--- Пересланное сообщение ---» (5 вариантов написания включая «Перенаправленное» от Yandex).
  - `dequoteText` — снимает `>`-префиксы, режет служебные строки.
  - `removeSignature` — режет подпись после `--`/«С уважением»/«Best regards», умно (если после `--` идут товаро-подобные строки — это разделитель перед позициями, не подпись).
  - **Порядок**: `dequoteText` ПЕРВЫМ (Yandex оборачивает forward в цитату), потом `extractForwardedContent` (теперь маркер виден), forwarded физически выбрасывается.
  - `htmlToText` — **критично для LazyLift / маркетинговых писем**: HTML-only без plain-alternative, IMAP вытаскивает CSS из `<style>`. `</td>→ \| ` и `</tr>→ \n` сохраняют табличный layout позиций.
  - `bodyPlainLooksBroken` — эвристика: пустой / < 20 символов / ≥3 CSS-маркеров → fallback на `htmlToText(body_html)`.
- `RequestItemParsingService` — структурированный user-prompt секциями `## ОТПРАВИТЕЛЬ / ## ТЕМА / ## ТЕКСТ` (был склеенный текст, GPT путал subject с описанием товара).
- **Корпус-валидация на 50 письмах**:
  - 9/9 false-negative-регрессий восстановлены (#371, #370, #369, #365, #352, #350, #355, #356, #329).
  - 7/7 контролей-positive без регрессий.
  - 6/7 контролей-negative корректны (#357 потенциально проблемный — но обрабатывается thread-linker'ом в продакшене).
- `requests:cleanup-items` CLI — для clean rebake: удалить items в диапазоне id, перевести Request обратно в Pending. Защита: --from-id обязателен, без --apply dry-run.
- **Backfill корпуса не делаем** — оператор решил, старые заявки уже устарели, наблюдаем только новые.

**ThreadClarificationAi** (5-й уровень, `f7aefe9`):
- `App\Prompts\Mail\ThreadClarificationPrompt::systemMessage()` + `userMessage($from, $subject, $body, $candidates[])`.
- `App\Services\Mail\ThreadClarificationAi::chooseRequest($message, Collection $candidates)` — выбирает из 2-5 свежайших, на сбое fallback на самую свежую.
- gpt-4o-mini, `OPENAI_CLARIFICATION_MODEL`. Стоимость ~$0.001-0.005 на вызов.

**`AmneziaVPN` блок к Beget** (Phase 1.8e находка): мой локальный трафик идёт через AmneziaVPN, exit-IP не в whitelist Beget Cloud DB. Все запросы прогоняются на VPS через tinker, локальный pg_connect не работает. Зафиксировал в «Известных граблях».

**Подключённые ящики** (без изменений):
- `mail@myzip.ru` (id=1, shared, oauth) — работает, sync 2-min schedule.
- `man2@myzip.ru` (id=2) — OAuth access-token expired, no refresh — шумит ERROR в лог каждые 2 мин, но не критично.
- `man3@myzip.ru` (id=3) — то же.

### Сессия 2026-05-08 — Phase 1.13 admin/managers UI + email rendering fixes + attachments UX

Длинная сессия, **9 коммитов**: `b73d677` → `a06fccd`. Закрыта Phase 1.13 целиком + один пункт бэклога.

**Email rendering — изоляция стилей через iframe srcdoc** (`b73d677`):
- **Симптом**: на карточке заявки с письмом от Liftway (HTML с `<style>` блоками) ломалось оформление кнопок «Сформировать КП / Refresh цен / Ответить» в action-panel — слетал размер, фон, выравнивание.
- **Корень**: `bodyHtmlFor()` возвращает сырой HTML тела, в Blade выводится через `{!! $html !!}`. `<style>...</style>` из письма попадает в DOM и применяется глобально к `.btn` / `<button>`.
- **Фикс**: тело письма рендерится в `<iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ $html }}">`. Стили физически не могут утечь, скрипты письма не выполнятся (нет `allow-scripts`). Auto-height через Alpine `x-init` (set scrollHeight + ResizeObserver), все ссылки в письме → `target=_blank rel=noopener`, минимальный typography reset инжектится в iframe.
- См. `resources/views/livewire/requests/detail.blade.php:367` таб «Переписка». Это золотой стандарт Gmail/Yandex.

**Attachments UX — image preview + lightbox + RFC 6266 download** (`2200400`, `11eb624`):
- **Симптом**: скачивание вложений с кириллицей в имени (`ролик1.JPG`, `реквизиты АЛЬФА-БАНК.pdf`) не работало или сохранялось под мусорным именем.
- **Корень**: `Content-Disposition: attachment; filename="ролик1.JPG"` — голый UTF-8 в HTTP header нарушает RFC 7230 (ASCII-only). Symfony Response/nginx mojibake'ит или режет.
- **Фикс**: централизовали stream через `AttachmentController::streamAttachment()`, header строится через `Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition($disposition, $filename, $asciiFallback)`. Корректный output: `attachment; filename="rolik1.JPG"; filename*=UTF-8''%D1%80%D0%BE%D0%BB%D0%B8%D0%BA1.JPG`. ASCII-fallback через `preg_replace('/[^\x20-\x7e]/', '_', $name)`.
- **Дополнительно**: убрали `Content-Length` header — `email_attachments.size_bytes` фиксируется при IMAP-ингесте и может не совпасть с реальным размером файла на storage-диске (encoding/decoding). Без header'а Symfony отдаёт chunked, stream завершается чисто. См. `app/Http/Controllers/AttachmentController.php`.
- **Image preview**: добавлен новый роут `GET /attachments/{attachment}/preview` (action `preview()`, Content-Disposition: inline) для использования в `<img>` thumbnail и лайтбоксе.
- **Image thumbnails в треде**: для вложений с `mime_type=image/*` или расширением `jpg/jpeg/png/gif/webp/bmp/svg/tif/tiff` — миниатюра 140×100 (object-cover) вместо чипа. Клик → `$dispatch('open-image', {src, name, dl})`.
- **Lightbox** (Alpine): fixed full-screen контейнер слушает `window:open-image`, рисует backdrop `rgba(0,0,0,0.82)`, картинка центрируется через `position:absolute + transform:translate(-50%, -50%)` с `max-width: calc(100vw - 48px)` / `max-height: calc(100vh - 80px)`. Закрытие — `Esc`, клик по бэкдропу, кнопка «Закрыть». Все геометрия — inline-стили (а не Tailwind-классы), потому что новые JIT-классы (`fixed inset-0 bg-black/85 ...`) не были собраны на проде до `npm run build`. См. `resources/views/livewire/requests/detail.blade.php:670+` (lightbox-контейнер).

**Phase 1.13 — UI управления менеджерами + OAuth-привязка ящиков + manual reassign** (5 коммитов: `5796b10`, `47b42f2`, `2e24099`, `a06fccd`):

Триггер: РОП блокировался без админа на каждое добавление/удаление сотрудника. Теперь весь flow в браузере.

- **Архитектурные вилки** (закрыты до старта кода через `AskUserQuestion`):
  - Оффбординг: soft-archive (`users.archived_at`) + ручное переподчинение (включаем UI-кнопку из дизайна).
  - Доступ: `role:head_of_sales,director` (как mail-rules).
  - OAuth flow: verification_code прямо в UI (открыть Yandex auth в новой вкладке, вставить 7-знач код, exchange). Не трогаем настройки OAuth-приложения Yandex.
  - Пароль: РОП задаёт временный, менеджер потом меняет в `/profile`.

- **Backend foundation:**
  - Migration `2026_05_08_120000_add_archived_at_to_users_table.php` — `users.archived_at TIMESTAMP NULL INDEX`. Не Eloquent SoftDeletes (global scope слишком агрессивно — ломает `request_assignments` audit-trails).
  - `User::scopeActive()` / `scopeArchived()` / `isArchived()`.
  - `LoginRequest::authenticate()` — после `Auth::attempt()` проверяет `isArchived()`, делает `Auth::logout()` + `ValidationException::withMessages(['email' => 'Учётная запись деактивирована. Обратитесь к РОПу.'])`. Проверка ПОСЛЕ attempt чтобы не утекать факт существования учётки.
  - `AssignmentService::autoAssign()` — теперь `User::role(Manager)->active()->get()` (sticky + round-robin фильтрует архивных).
  - `App\Services\Request\ReassignService::reassign()` — транзакционный manual reassign. Пишет audit в `request_assignments` (reason `manual_reassign: …`), best-effort `MailFolderRouter::routeToManager()` (IMAP-сбой логируется WARNING, не валит DB tx).

- **UI — раздел `/dashboard/managers`** (3 Livewire-компонента + 5 views + routes + topbar nav):
  - `App\Livewire\Admin\Managers\Index` — таблица с фильтр-чипами (Менеджеры/РОП/Секретари/Директорат/Все активные/Архив), поиск по name+email (ilike), 25 на страницу, действия `archive($id)` / `restore($id)` через `wire:confirm`. Чипы статуса: «активен» / «в архиве» / mailbox-status (active/error/detached/missing).
  - `App\Livewire\Admin\Managers\Editor` — create/edit form. На create `password|required|min:8|confirmed`, на edit опционально. После create редирект на `managers.edit/{id}` чтобы РОП в одном flow подключил ящик.
  - `App\Livewire\Admin\Managers\MailboxOauth` (sub-component на edit-странице) — three-state machine:
    - **NO_MAILBOX**: форма «email ящика» + «name» → `createMailbox()` создаёт `Mailbox` с `type=Personal`, `auth_type=OAuth`, `is_active=true` + пустой encrypted JSON через `writeCredentials([])` (NOT NULL constraint).
    - **NO_TOKENS**: блок инструкции + кнопка `<a href="{{ $authorizeUrl }}" target="_blank">` (URL из `YandexOAuthService::authorizationUrl(state: "ui:mb-{$id}", loginHint: $email)`) + поле «Verification code» + `saveCode($oauth)` → `$oauth->exchangeCode($code)` + `$mailbox->setOAuthTokens(...)`.
    - **HAS_TOKENS**: статус (expires_at, refresh_token наличие, последний sync, последняя ошибка) + кнопки «Переподключить» (`reconnect()` пишет пустой credentials → возврат к NO_TOKENS) и «Отвязать» (`detach()` ставит `is_active=false`, токены не трогаем).

- **Manual reassign в карточке заявки** (`detail.blade.php`):
  - Disabled-кнопка «⊘ Переподчинить» из дизайна заменена на `<livewire:requests.reassign-dialog :request="$req">` для `head_of_sales|director|secretary`. Менеджеру оставлен disabled-stub.
  - `App\Livewire\Requests\ReassignDialog` — модалка fixed-position с select active managers + textarea «Причина» + submit → `ReassignService::reassign()` → redirect через Referer (сохраняет таб state).

- **Найденные грабли (зафиксированы выше в «Известные грабли»):**
  - `mailboxes.encrypted_credentials NOT NULL` → `writeCredentials([])` до save, как в `mail:add` CLI.
  - Livewire `Str::studly` для tag `mailbox-oauth` → `MailboxOauth` (не `MailboxOAuth`). PSR-4 case-sensitive на Linux.
  - `public App\Models\Request $request` в child Livewire-компоненте → 500 на дегидратации (shadow от `Illuminate\Http\Request`). **Решение:** хранить только id.
  - Spatie role name `manager` (singular) vs UI label «Менеджеры» (plural) — chip key должен совпадать с role name, иначе `whereHas('roles', fn => where 'name' = $filter)` пуст.

**man2/man3 OAuth re-issue — закрыто** (бэклог-пункт):
Через новый `/dashboard/managers/{id}/edit` оператор переподключил оба ящика по verification_code flow. `production.ERROR` про expired access_token прекратились.

**Подключённые ящики** на 2026-05-08:
- `mail@myzip.ru` (id=1, shared, oauth) — работает.
- `man2@myzip.ru` (id=2, personal, oauth) — **переподключён через UI**, токены живые.
- `man3@myzip.ru` (id=3, personal, oauth) — **переподключён через UI**, токены живые.
- + новый менеджер «Морозов Алексей Игорьевич» — создан через UI, ящик подключён.

### Сессия 2026-05-07 (вечер 1) — Phase 1.8c (LazyLift email classifier port) + UI mismatch raised

После Phase 1.8b backfill оператор увидел в пуле 6+ ложно-позитивных Request разных категорий (внутренние КП от коллег, supplier offers, out-of-office, newsletter spam). Триггер «items.count > 0» оказался слишком слабым.

**Что сделано:**
- **`0dccf8a` Port LazyLift Email Classifier (Phase 1.8c).**
  - Source: `LazyLift n8n workflow Flow 1: Email Classification v9.2` (предоставлен оператором).
  - LazyLift'ские 4 типа → 3 типа MyZip: `client_request | thread_reply | irrelevant` (без `invoice`/LW-артикулов).
  - `App\Enums\EmailCategory` — новый enum.
  - Migration `add_category_to_email_messages`: `category`, `category_confidence`, `category_intent`, `category_reasoning`, `categorized_at` (независимо от старых `ai_classification`-полей).
  - `App\Prompts\Mail\CategorizeIncomingPrompt` — полный системный промпт LazyLift, адаптирован под MyZip:
    - Заменены упоминания Liftway / `support@liftway.ru` на MyZip + список наших mailbox-адресов (динамически из БД).
    - Сохранены 10 правил: направление переписки (Правило 1), услуги vs ТМЦ (2), пустое тело + вложение (3), множественные получатели (4), жёсткий гейт thread_reply Re:/Fwd: (6), confirm_order intent (8), корпоративный RFQ (9), маркетинг/newsletter (10).
  - `App\Services\Mail\MailCategoryClassifier` — обёртка над OpenAIChatService (gpt-4o, не -mini). Идемпотентный по `categorized_at`.
  - `OPENAI_CATEGORY_MODEL=gpt-4o` в `.env.example` и `config/services.php → openai.category_model`.
  - CLI `mail:categorize {id}` / `--all` / `--force`.
  - `RequestItemPersister` гейтит создание Request на `category === client_request`.
- **`85d1c70` Wire MailCategoryClassifier в живой MailRouter.**
  - Каждое новое inbound-письмо после loop guard и до rules проходит через категоризатор.
  - Заполняет `email_messages.category` — оператор «следит за новым распределением».
  - На текущее поведение Request-creation (старый `ai_classification=request` гейт в IncomingMailProcessor) **не влияет** — false-positives продолжатся, пока в следующей сессии не переключим триггер.
  - Ошибка категоризатора non-fatal (Log::warning, pipeline продолжает).

**Стоимость:** ~$0.005-0.01 на письмо (gpt-4o с длинным промптом). При 50 inbound/day ≈ $0.25-0.50/день.

**`mail:export-for-analysis` CLI** — дамп всех писем в CSV (с признаками: domain match, headers, attachment types, current ai_classification, related Request items). Для ручной разметки и валидации классификатора.

**Старые позиции (227 backfill Request) НЕ перекатываются** — оператор решил отслеживать только новое распределение.

**Старые сервисы оставлены:**
- `MailClassifierService` (6 категорий, gpt-4o-mini) — продолжает работать для `MailRoutingRule`.
- `EmailClassification` enum остаётся.
- `IncomingMailProcessor.processIfRequest` гейт остаётся `ai_classification === request` (не переключен на `category === client_request`).

**Поднятый оператором вопрос UI** (на следующую сессию — Phase 1.8d):
- Текущий `/dashboard/requests/{request}` показывает «исходное письмо» как главный контент — это **«читалка писем», а не работа с заявками**.
- Foundation §«работа с заявками»: Заявка = **список позиций + переписка с заказчиком**, не одно письмо.
- Шаблон `design/ui_kits/crm/04-request-detail.html` определяет правильную структуру: Tabs `Обзор / Переписка (N) / Позиции (N) / Поставщики (N) / Активность (N) / Файлы (N) / Связанные`. Hero с `internal_code`, статусом, SLA, менеджером, sticky, суммой, «сматчено N/M». Action-кнопки: «Сформировать КП», «Refresh цен», «Ответить», «Пауза», «Переподчинить».

**Связанные шаблоны:**
- `01-pool.html` — пул менеджера с фильтрами (статус/SLA/возраст/manager).
- `03-requests.html` — список заявок РОПа с расширенными колонками (позиции/сумма/поставщики).
- `02-dashboard.html` — KPI-плитки + графики.

### Сессия 2026-05-07 — Phase 1.8b (content-driven парсинг + folder routing)

Большая сессия, ~14 коммитов. Закрыли Phase 1.8b целиком в CLI-режиме (без интеграции в boevoй MailRouter — отложена).

**Что сделано:**
- **Pkg 1** (`cc1df60`) — composer deps: `smalot/pdfparser ^2.12`, `phpoffice/phpword ^1.2`, `phpoffice/phpspreadsheet ^5.5`. На VPS apt: `poppler-utils + abiword`.
- **Pkg 2** (`5093d11`) — миграция `request_items` + модель `RequestItem` (минимум: parsed_*, supplier_note, data_source, status, is_active) + `Request->items()` HasMany.
- **Pkg 3** (`45920a3`) — `RequestItemParsingService` drop-in из LazyLift @ `25b59645`. Адаптации: `OpenAIChatService` namespace, hard-coded models → `config('services.openai.parsing_model'\|'vision_model')`, `parseItemsFromInboundMessage` под `EmailMessage`/`EmailAttachment` (был `RequestMessage`/`RequestAttachment`).
- **Pkg 4** (`b912df1`) — `RequestItemPersister` (idempotent items → Request) + CLI `requests:parse-items` (single + bulk modes, --apply, --force, --limit, --from-id).
- **Pkg 5** (`674a916` → `e9b84c7` → `29ce799`) — `MailFolderRouter`: COPY письма в подпапку `MZ\|{Lastname}` (root namespace, не под INBOX), транслитерация имён, FT_PEEK при чтении, явное `\Seen` после COPY.

**Результаты backfill (`requests:parse-items --apply --force --limit=500`):**
- 360 inbound писем обработано
- 227 с непустыми items (63% — реальные заявки)
- 424 позиций распарсено всего (~1.87 на заявку)
- 38 новых Request созданы (+пропущенных старым AI-classifier)
- 189 Request обновлены (items добавлены к существующим)
- **0 failed** — pipeline стабилен на массовом прогоне
- Распределение по папкам: `MZ\|Ivanov` 137, `MZ\|2` 46, `MZ\|3` 44 — round-robin отработал на трёх менеджерах
- Стоимость: ~$10-15 (gpt-4.1 + gpt-4o Vision)

**Открытый вопрос #6 закрыт.** Зафиксированы Yandex IMAP folder ops quirks в «Известные грабли».

**Новый открытый вопрос:** **\Seen побочно ставится** — все 369 писем в INBOX `\Seen`, новые приходящие тоже становятся \Seen «через какое-то время». Все наши IMAP-fetch'и используют `FT_PEEK`, но похоже webklex 6.x в комбинации `FT_PEEK + setFetchBody(true) + setFetchFlags(true)` не уважает PEEK для тела (не ставит `BODY.PEEK[]`). Не блокирует, но идёт против Foundation §1. Расследование на следующую сессию.

**Legacy для уборки (вручную в Yandex UI):**
- Папки `MZ\|2`, `MZ\|3` называются по `shortName('Менеджер 2')='2'` — некрасиво. Если name тестовых юзеров поменяют на нормальные — папки переименуются автоматически (новый MOVE создаст `MZ\|Petrov` и т.п., старые `MZ\|2`/`MZ\|3` останутся как rubbish). Удалить вручную в UI.
- `MZ\|&-BBgEMgQwBD0EPgQy-` и `MZ\|&BBgEMgQwBD0EPgQy-` — наши early-attempt фейлы с double-encode и manual UI create. Удалить вручную.
- Зелёный label «MZ Иванов» на email#16 (legacy IMAP keyword до перехода на folder approach) — удалить вручную.

### Сессия 2026-05-12 — Phase 2.x: web-fetch + clarifications + catalog + vector match + UI «Настройки»

Большая сессия. Закрыли несколько Phase 2 фич + добили обработку каталога.

**1. Bugfix 500 на табе «Позиции»** (`ffa2f9a`)
- `RequestItem::category()` relation коллидировал со строковой колонкой `category` (coarse-категория от парсера). Eloquent в `getAttribute()` отдавал строку → `$item->category->slug` валился ErrorException.
- Переименован relation → `kbCategory()`, обновлены `Detail::mount()` eager-load и блейд.

**2. Web-fetch URL'ов из тела письма** (`1d5c703`)
- Новая таблица `inbound_url_fetches` (cache по `url_hash`, status, extracted_text).
- `App\Services\Web\WebSecurity` — SSRF guard (CIDR blocklist, schemes whitelist, DNS resolve before connect, per-redirect re-check).
- `UrlExtractor` (regex + DOMDocument для `<a href>`), `InboundUrlFetcherService` (Guzzle с timeout/size limits + readability extraction через DOMDocument).
- Интегрировано в `RequestItemParsingService::parseItemsFromInboundMessage` синхронно — заявка не появляется в пуле пока URL не сфетчены.
- Cap 10 URL/email, бюджет 60с, кэш 7 дней. Конфиг `services.web_fetch.*` через env.
- Unit-тесты: `tests/Unit/Services/Web/WebSecurityTest.php` (14 кейсов), `UrlExtractorTest.php` (7).

**3. `--reset` флаг для force re-parse** (`e63feba`)
- `ParseRequestItemsJob` теперь принимает `reset=true` — стирает `RequestItem`-ы привязанные к Request перед persist. Нужен после смены парсера/промпта, иначе старые «склеенные» позиции дедупа не пройдут.
- CLI: `php artisan requests:parse-items <id> --apply --reset`.

**4. Привязка фото-вложений к позициям (Vision image_index)** (`cca3109`)
- Миграция `add_image_attachment_id_to_request_items`.
- Vision-промпт расширен: возвращает `image_index` (0..N-1).
- `parseItemsFromPhotoMarkings` мапит index → email_attachment_id.
- В UI у позиций thumbnail 32×32 с lightbox.

**5. Thread-aware clarifications queue** (`1e5640c`)
- Phase 2 use-case D: когда reply к существующей Request содержит «уточнение артикула» (Liftway-auto: LW-* в первом письме, M-* в reply), система не плодит дубль.
- Второй LLM-проход (`DecideClarificationsPrompt` на mini): по контексту existing+new решает «new» vs «clarification».
- Clarifications кладутся в `requests.pending_clarifications` (jsonb), в UI карточки отдельный amber-блок с Apply/Reject кнопками.

**6. Каталог Liftway.ru — drop-in 30 649 строк** (`a3a2d8f`, `a76b241`, `f655152`, `20d244d`, `086c55b`, `6729fc6`, `9b70548`, `a12e20d`, `e4a2769`, `baebb47`, `7ef3dc6`, `44f68b1`, `d99e851`, `94c5ef8`)
- **Импорт**: `POST /api/catalog/import` (token-auth, hash-aware upsert, soft-delete, min_full_rows guard), CLI `catalog:import file.csv --apply` (cp1251 + autodetect разделитель).
- **Use-case A** (M-SKU resolve): items со status=internal_catalog_pending после импорта → `sufficient` + payload.catalog.
- **Use-case B** (brand_article match): новая колонка `brand_article_normalized` (uppercase + удаление `[\s\-_./]`) + HNSW-free lookup. 220+ позиций сматчено exact.
- **Use-case C** (vector + LLM validation):
  - Таблица `catalog_item_embeddings` + pgvector(1536) + HNSW по cosine.
  - `OpenAIEmbeddingService` симметричен `OpenAIChatService`.
  - `CatalogEmbeddingService` — embed catalog (30k × ~80 токенов ≈ $0.09 разово), buildQueryText из RequestItem, vector top-1 retrieval.
  - **Three-stage retrieval**:
    1. vector cosine ≥ threshold (default 0.75);
    2. article safety-check (если у обоих brand_article и они normalize-разные → reject);
    3. LLM validation (gpt-4o-mini, `ValidateCatalogMatchPrompt`) для пар [threshold, hc_threshold=0.90). Внешний retry 2×sleep(5) поверх Http::retry — справляется с прокси-503 серий.
  - `llm_fail_action=reject` (default): при сбое LLM матч отклоняем (precision приоритет).
  - Маркер `payload->catalog_match->method = A_internal_sku | B_brand_article | C_name_vector` для точечного rollback'а.
  - На свежей выборке: B 220, C 11 approved, precision на видимых ~100%.
- **UI карточки**: chip «в каталоге · M01767» (violet), chip `mylift.ru ↗` (sky external-link, для M-SKU позиций), цена/наличие/сумма в колонках, hero «Сумма» и «Сматчено N/M (P%)», подытог + НДС {VAT_PERCENT}% + итого внизу таба «Позиции».
- **Защита**: `CATALOG_IMPORT_MIN_FULL_ROWS` отвергает 422 если < N строк (защита от обнуления каталога битой выгрузкой).

**7. UI «Настройки» (`/dashboard/settings`)** (`44f68b1`, `d99e851`, `94c5ef8`)
- Таблица `app_settings` (key/value/type/description/updated_by) — override поверх `config()`.
- `SettingsService` (get/set/unset/forget с 5-мин кэшем); helper `app_setting('key', config('default'))`.
- Livewire-страница admin Settings для head_of_sales/director: 7 настроек в 3 группах (catalog matching, catalog import, taxes).
- При save: если значение = config-default → unset (override удаляется); иначе upsert.
- НДС теперь 22% (с 2026 в РФ).

**8. Vision-промпт: numbered text list authoritative** (`a12e20d`)
- M-2026-0512: клиент пишет «1. Отводка Левая, 2. Отводка Правая, 3. Мотор» + 3 фото, Vision сливал Левую с Правой как «один товар с разных ракурсов».
- Промпт расширен: если в reference text нумерованный список → COUNT и SOSTAV из текста, фото — enrichment. Перечислены примеры asymmetric-модификаций (Левая/Правая, Верх/Низ, M/F, цвета).
- `image_index: null` теперь легитимный случай (позиция из текста без подходящего фото).

#### Известные грабли (новые в этой сессии)

- **Livewire wire:model и точки в ключах**: точки трактуются как nested array access (`values.X.Y.Z` → `$values[X][Y][Z]`). Workaround: в `Index::formKey()` конвертим dot-key → underscored form-key (`catalog.name_match.threshold` → `catalog_name_match_threshold`), в save переводим обратно через schema.
- **HTML5 `<input type=number>` валидация**: `value = min + n × step`. `min=1 step=100` → разрешены `1, 101, 201, ..., 24001` — `24000` мимо. Браузер показывает «Введите допустимое значение». Для счётчиков-integer держим `step=1`.
- **Прокси openai-proxy серией отдаёт 503** во время bulk LLM-вызовов. Laravel `Http::retry(3, 2000)` укладывается в ~6 сек — мало. Внешний retry в `validateMatchWithLlm` со sleep(5) добивает. + `llm_fail_action=reject` чтобы при остаточных fail'ах не пропускать матч.
- **Vector retrieval ловит «семантически близкие, но разные товары»**: «звено цепи» vs «звезда главного вала», «поручень» vs «ролик/колесо», «1 м/с» vs «1.6 м/с», разные модели в одной серии. Distribution similarity для правильных vs ложных пересекается (0.77–0.86), threshold-сдвиг не разделит. LLM-валидация это режет.
- **`git pull` на проде от root** оставляет файлы owner=root, последующий `sudo -u www-data git pull` ломается на permission denied и оставляет workdir в полу-применённом состоянии. Решение: `sudo chown -R www-data:www-data /var/www/mzcorp` + `sudo -u www-data git reset --hard origin/main` + `git clean -fd`. И запомнить: git на проде всегда от `www-data`.
- **composer.json:autoload.files требует `composer dump-autoload`** на проде после `git pull` — иначе helper `app_setting()` не подцепится.

#### Текущее состояние на проде (2026-05-12 вечер)

- `catalog_items`: 30 649 строк, all active.
- `catalog_item_embeddings`: 30 649 эмбеддингов (model `text-embedding-3-small`).
- `request_items.catalog_item_id`: ~250+ позиций (40 A + 220 B + 11 C approved + 5 свежих).
- `app_settings`: пусто (все на config-defaults).
- `inbound_url_fetches`: ноль (новых писем с URL пока не было после деплоя).
- Failed jobs очищены от старых OAuth-ошибок man2/man3.

### Сессия 2026-05-14..05-15 — Phase 1.9 UI-переписка + Priority 1 ручные действия + Phase 1.10 state-machine + багфиксы

Большая сессия, ~24 коммитов от `6864812` до `1e9e558`. Закрыты три крупные фичи + операторские багфиксы.

**Phase 1.9 — UI-переписка из карточки заявки** (`6864812`..`5afd521`, 14 коммитов):
- `OutgoingMailboxResolver` (assigned manager → shared `mail@myzip.ru` fallback) + `OutgoingMailMimeBuilder` + `MailQuoteBuilder` (Apple-Mail RU цитата, Yandex сворачивает) + `OutgoingMailSender` + `AppendToSentFolderJob`.
- `EmailDraftService` — drafts = `email_messages.is_draft=true`. Подпись/цитата клеятся ТОЛЬКО при send (в textarea menager видит чистый текст).
- Livewire `ComposeForm` с `WithFileUploads`: reply / reply-all / compose, listeners `open-reply` / `open-reply-all` / `open-compose` / `open-draft`, auto-save через `wire:model.live.debounce.1500ms`.
- **Drag&drop вложений** (Alpine + DataTransfer API) + auto-attach при выборе (`updatedNewFiles`) + cleanup `input.value` через event `attachments-uploaded` (фикс дублей).
- Migration `add_drafts_to_email_messages` (`is_draft`, `draft_author_user_id`, `last_edited_at`, `imap_uid` nullable, partial unique). **Грабли:** на Postgres `$table->unique()` = CONSTRAINT, `DROP INDEX` не сработал → `ALTER TABLE DROP CONSTRAINT` (см. `f6c2fcf`).
- `MessagePersister` дедуп по `X-MyLift-Reply: 1` — при Sent-sync найденный наш draft не дублируется, обновляется `imap_uid`.
- `users.email_signature` + textarea в `/profile`.
- `config/livewire.php` — `temporary_file_upload.max_upload_size = 26624`. На VPS поднять `php.ini`: `upload_max_filesize=30M`, `post_max_size=60M`, `max_file_uploads=40`.
- Subject-header в `ItemCatalogLinkDialog` (тут не уместно — это из Priority 1, но был commit `985968b` в эту сессию).

**Priority 1 — ручное управление позициями + каталогом** (`259d0ed`..`9117c2d`, 9 коммитов):
- Migration `add_internal_catalog_not_found_to_quality_status_enum` — 7-е значение в CHECK.
- Enum `QualityAssessmentStatus` (backed string, label/chipClass/isCatalogTerminal). Был `internal_catalog_pending`, добавили `internal_catalog_not_found`.
- `RequestItemEditor` — единая точка ручных действий: `editFields` (whitelist 6 полей), `softDelete` / `restore` (через `is_active`), `unbindCatalog` (с переносом в `previous_catalog_match`), `linkToCatalog` (method=`manual_link`), `refreshFromCatalog` (re-run matchByName + auto-снять not_found), `markCatalogNotFound` (guard pending only), `mergeIntoExisting` (operator-driven clarification fallback), `findSimilar` (top-N vector), `rematchAll` (bulk re-match всех позиций).
- `CatalogSearchService` — SQL ILIKE по sku/brand_article/normalized/name с priority sort.
- `CatalogEmbeddingService::topNByRequestItem(item, n=10)` — без threshold/safety/LLM для preview.
- `CatalogResolutionService` guards: `matchByArticle` / `matchByName` пропускают `internal_catalog_not_found`.
- UI items-tab переделан с dropdown «⋮» (Редактировать / Обновить из каталога / Отвязать / 🔍 Похожие из каталога / Привязать вручную / ❌ Нет в каталоге / 🔗 Это уточнение позиции… с sub-menu / Удалить) + лупа 🔍 в строке + toggle «Показать удалённые» + «🔄 Refresh всех» + chip `chip-danger` «нет в каталоге».
- Livewire диалоги: `ItemEditDialog` (6 полей с валидацией), `ItemCatalogLinkDialog` (tabs «По тексту» / «Похожие из каталога», subject-header «Ищем для позиции» с фото+chips+«Сейчас привязана»).
- Audit в `payload.manual_edits[]` (FIFO до 50 записей).
- Под `parsed_name` показывается `↳ catalog->name` если отличается.

**Phase 1.10 — минимальная state-machine** (`00a806b`, `49e78a7`, `9522c17`):
- RequestStatus расширен 11 значениями (Foundation §5.2): `in_progress`, `awaiting_client_clarification`, `quoted`, `under_review`, `postponed_until`, `awaiting_invoice`, `invoiced`, `paid`, `paused`, `closed_won`, `closed_lost`. Методы `label`, `chipClass`, `isTerminal`, `isOpenForAssignment`, `isVisibleToManager`, `allowedTransitions`, `canBePaused`.
- Enum `ClosedLostReason` — 10 reasons + `requiresComment()` для `*_other`.
- Migrations: `add_state_fields_to_requests_table` (`paused_until`/`paused_from_status`/`paused_reason`/`closed_at`/`closed_lost_reason`/`closed_lost_comment` + `status varchar(40)` + index `(status, closed_at)`) и `create_request_state_changes_table`.
- `RequestStateService::transitionTo` с validation+authorization+audit + `recordSystemInitial` для initial-event при autoAssign.
- `RequestPauseService::pauseUntil / resume / applyDuePauses` с cap `config('services.requests.max_pause_days', 21)`.
- CLI `requests:resume-paused [--dry-run]` + `Schedule::command()->dailyAt('06:00')`.
- `RequestItemPersister` после первого autoAssign пишет initial state-change.
- `AssignmentService` (load + sticky) пересмотрен через `isOpenForAssignment()` — корректная нагрузка по всем active-статусам.
- UI Detail: hero-chip через `chipClass()`, action-panel переписан под allowedTransitions, terminal/paused info-плашки, Pause/CloseLost диалоги, Activity-tab merge `stateChanges + assignments + email` с цветными точками и иконками 🔄/⏰/👤/✉.
- UI Pool: bucket-фильтры **active** (default) / **paused** / **closed** / **all** с counts + chip-фильтры внутри bucket'а + `setBucket()` + `mount()` auto-сброс stale URL `?status=` после смены статуса.

**Багфиксы и оператор-просьбы** (`a86323f`, `7ccb044`, `1e9e558`):
- **Sticky-navigate ломал Detail-страницу** — между двумя Detail-страницами Livewire SPA не пересоздавал state, Alpine dropdown'ы получали stale-инстанс, tabs не реагировали. Фикс: убрать `wire:navigate` со sticky-ссылок → full reload.
- **Кириллический «М» (U+041C) в M-SKU detector'е** — клиент пишет «М14224» (cyrillic), regex `/M\d{4,}/` (latin) не ловил. Helper `CatalogImportService::cyrillicLookalikeFold(string)` — strtr 11 пар lookalike (А/A В/B Е/E К/K М/M Н/H О/O Р/P С/C Т/T Х/X). Применён в `detectInternalCatalogSku` / `extractSku` / `normalizeArticle`.
- **Sticky-positions toggle** — кнопка «📎 Sticky-позиции» в табе Позиции показывает позиции связанных заявок (forward `sticky['links']` + reverse-search в `request_assignments.reason LIKE '%auto_sticky:%"linked":[..., this_id, ...]%'`). Общий partial `_item-row.blade.php` для main + sticky lists — одинаковый layout, sticky в `readonly`-режиме, action-cell заменён на ↗ (open карточку заявки).
- **Operator-driven merge «🔗 Это уточнение позиции…»** — fallback когда LLM `DecideClarificationsPrompt` ошибся и создал лишнюю позицию для голого артикула в reply. Sub-menu в dropdown позиции — выбрать target из существующих, апсинхронно: дописывание article + brand если у target пусто + catalog_item_id + soft-delete source + audit `manual_merge_in` / `manual_merge_out` в обеих позициях.

#### Новые грабли (зафиксированы в «Известные грабли»)

- **Postgres `$table->unique()` = CONSTRAINT, не INDEX** — `DROP INDEX` падает `SQLSTATE[2BP01]`. Нужно `ALTER TABLE ... DROP CONSTRAINT` через raw SQL. Шаблон в миграции `2026_05_14_120000_add_drafts_to_email_messages_table` после `f6c2fcf`.
- **Yandex сворачивает только Apple-Mail RU формат цитаты** — Gmail Western `On DATE, NAME wrote:` не распознаётся как collapsible. Нужно RU-локализованный «D мес. YYYY г., в HH:MM, NAME <email> написал(а):» + `<blockquote type="cite" style="margin:0 0 0 .8ex; border-left:1px #ccc solid; padding-left:1ex">`. См. `MailQuoteBuilder`.
- **Livewire `WithFileUploads` + DataTransfer DOM** — после backend `$newFiles = []` нативный `<input>.files` НЕ очищается → следующий drag&drop собирает старые DOM-файлы + новые → дубли EmailAttachment. Фикс: backend `dispatch('attachments-uploaded')` → Alpine `input.value = ''`.
- **PHP-FPM default request_terminate_timeout ≈ 30s** убивает SMTP send с большими вложениями. `set_time_limit(180)` + `ini_set('max_execution_time', '180')` в send action. На VPS `php.ini`: `upload_max_filesize=30M`, `post_max_size=60M`, `max_file_uploads=40`.
- **`wire:navigate` между двумя одинаковыми Livewire-page-компонентами** ломает state — Alpine dropdown'ы и tabs не реинициализируются. Между Detail→Detail (sticky-links) использовать обычные `<a>`. SPA-навигация работает между разными типами страниц.
- **Кириллический «М» (U+041C) визуально неотличим от latin M** — клиенты/1С автозаменяют. Helper `CatalogImportService::cyrillicLookalikeFold()` для 11 пар lookalike. Применять ДО regex/normalize в article-related коде.

### Сессия 2026-05-16 — Phase 1.11 Attention-механизм

Реализован Foundation §5.3 + §5.5 целиком (включая Settings UI + РОП-counters + PostponeDialog):

- Миграция `2026_05_16_120000_add_attention_fields_to_requests_table` — три поля + два индекса (composite `(assigned_user_id, attention_level DESC, attention_required_at NULLS LAST)` для Pool-сорта; partial `WHERE attention_level=1` для дашбордного COUNT просроченных).
- Enum `App\Enums\AttentionReason` — 7 значений из Foundation, с `label()` и `icon()` для UI-badge.
- `AttentionService` — single source of truth:
  - `recompute(Request $r)` — расчёт дедлайна по статусу + save(forceFill). Сбрасывает `attention_level=0` (overdue решает sweep).
  - `clear(Request $r)` — обнуление для silent-статусов.
  - `sweepOverdue()` — bulk UPDATE для cron'а; пере-классифицирует и в обе стороны (0↔1).
  - `compute(Request $r)` — pure-function расчёт `[Carbon|null, AttentionReason|null]` по статусу. Silent: Paused/Closed*/Pending/Paid.
  - Business-hours helper Пн-Пт 9-18 Europe/Moscow: `addBusinessHours(CarbonImmutable, int)` и `addBusinessDays(CarbonImmutable, int)`.
  - Anchor для расчёта — последний `request_state_changes.created_at` с `to_status = current` (без него fallback на `updated_at`).
  - `PostponedUntil` deadline читает `payload.postponed_until` из последнего state_change; fallback +7 раб. дней.
- Интеграции:
  - `RequestStateService::transitionTo()` — `$this->attention->recompute($request)` ВНУТРИ транзакции, после insert'а в `request_state_changes` (иначе `compute()` не увидит свежий anchor).
  - `RequestStateService::recordSystemInitial()` — тоже recompute (свеженазначенная заявка получает первый дедлайн).
  - `RequestPauseService::pauseUntil()` — `attention->clear()` (silent).
  - `RequestPauseService::resume()` — явно `attention_required_at = now()`, `reason = postponed_resume`, `level = 1` (Foundation §5.4: «возобновлена — тут же подсвечивается»).
- Console `requests:check-attention` everyFifteenMinutes — sweep `attention_level`. Артефакт в `app/Console/Commands/RequestsCheckAttentionCommand.php`, schedule в `routes/console.php`.
- Pool:
  - orderBy для bucket=active/overdue: `attention_level DESC, attention_required_at ASC NULLS LAST, id DESC`.
  - Новый bucket `'overdue'` в `setBucket` allow-list + `statusesForBucket()`. WHERE: `attention_level=1` + те же open-statuses что у active.
  - В `bucketCounts` — `'overdue' => count(active-scope + level=1)`.
  - View: chip «Просрочено» в bucket-row, красный (`--red-50 / --red-300 / --red-700`) когда count > 0.
  - При bucket=overdue — flat-list (один синтетический group со `status = null`), view проверяет `@if($group['status'] !== null)` для group header.
  - В title-cell row — attention-badge `🟡 через 4ч` (amber) или `🔴 просрочено 2д` (red) с tooltip `reason · timestamp`.
  - Overdue row: tint `bg-[var(--red-50)]` + left-border `--red-500`.
- Settings UI — 8 новых полей в группе «Attention · дедлайны» (`attention.new_hours / assigned_hours / in_progress_hours / awaiting_clarification_days / quoted_first_followup_days / under_review_days / awaiting_invoice_hours / invoiced_followup_days`). Defaults из Foundation §5.5. `configKeyFor()` пустая строка для них → fix через новый helper `configDefault()` (раньше `config('', $default)` возвращал repository вместо $default).
- Dashboard РОПа — 2 KPI-плашки над основным KPI-strip:
  - «Просрочено» — clickable `<a href="?bucket=overdue">`, красная подсветка когда count > 0.
  - «Дедлайн сегодня» — `attention_required_at BETWEEN now() AND endOfDay() AND level=0`, амбер.
  - Считаются на той же базе что и существующие `requestCounts` (менеджер видит свои).
- `PostponeDialog` (`app/Livewire/Requests/PostponeDialog.php` + view) — отдельный диалог для transition в `PostponedUntil`. Пишет `payload.postponed_until` в state_change (Carbon ISO8601, время `09:00` рабочего дня). Cap: 90 дней (`services.requests.max_postpone_days`). Заменил прямой `wire:click="transitionStatus('postponed_until')"` в Detail.
- Request model: `attention_required_at` / `attention_reason` (cast в enum) / `attention_level` (int) в `$fillable` + `$casts`.

#### Грабли Phase 1.11

- **`Carbon::diffInMinutes($cursor, false)` reversed-направление → бесконечный цикл** (hotfix `f465cc9`): первая версия `addBusinessHours` делала `$endOfDay->diffInMinutes($cursor, false)`. Когда $cursor < $endOfDay (например 14:00 < 18:00), Carbon 2 возвращает -240, `max(0, -240) = 0` → availableMinutes=0 → `$remaining` не убывает, while крутится вечно → PHP execution timeout → 500 на ЛЮБОЙ `RequestStateService::transitionTo`. Симптом в UI: «multiple instances of Alpine», табы перестают кликаться, child Livewire-компонент крашится (Phase 1.13 паттерн «отвалился child = вся страница плывёт»). **Решение:** timestamp-арифметика `(int)(($endOfDay->getTimestamp() - $cursor->getTimestamp()) / 60)` — version-agnostic, не зависит от Carbon 2 vs 3 signage. Плюс safety-iter cap (2600 итераций ≈ 10 лет рабочих дней) на случай будущей регрессии.
- **`config('', $default)` возвращает Repository, не default** — Settings UI хитро ломается для настроек без параллели в config/. Новый helper `Settings\Index::configDefault()` обрабатывает пустой config-key.
- **`statusEnteredAt()` зависит от `request_state_changes`** — для старых заявок (до Phase 1.10) истории нет, fallback на `updated_at`. Это нормально для backfill.
- **Pool flat-list для overdue теряет status-groups** — операторы привыкли к группировке; для других bucket'ов сохранено. Если жалоба — можно вернуть группы через флаг.
- **resume() жёстко проставляет `attention_level=1`** (а не пересчитывает через recompute). Это соответствует Foundation §5.4 («тут же подсвечивается»), но конфликтует с обычной recompute-логикой — стоит помнить при тестах.
- **Recompute вызывается ВНУТРИ транзакции** state-сервиса, после insert'а state_change. Это критично — иначе `statusEnteredAt()` не найдёт текущий anchor. Если когда-нибудь будем выносить recompute в job — нужно дождаться commit'а транзакции.

#### Деплой Phase 1.11

```bash
cd /var/www/mzcorp
sudo -u www-data git pull --ff-only origin main
sudo -u www-data composer dump-autoload --optimize
sudo -u www-data php artisan migrate --force          # одна новая миграция (attention-поля + 2 индекса)
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:clear && sudo -u www-data php artisan view:cache
sudo -u www-data npm run build
sudo supervisorctl restart mzcorp-worker:*

# Backfill attention для существующих active-заявок (одноразово):
sudo -u www-data php artisan tinker --execute='
$attn = app(App\Services\Request\AttentionService::class);
$silent = [App\Enums\RequestStatus::Paused->value, App\Enums\RequestStatus::ClosedWon->value,
           App\Enums\RequestStatus::ClosedLost->value, App\Enums\RequestStatus::Pending->value,
           App\Enums\RequestStatus::Paid->value];
$n = 0;
App\Models\Request::query()
    ->whereNotIn("status", $silent)
    ->orderBy("id")
    ->chunkById(200, function ($chunk) use ($attn, &$n) {
        foreach ($chunk as $r) { $attn->recompute($r); $n++; }
    });
echo "Backfilled: ".$n.PHP_EOL;
app(App\Services\Request\AttentionService::class)->sweepOverdue();
'

# Проверка cron:
sudo -u www-data php artisan schedule:list | grep check-attention
```

### Сессия 2026-05-16 (продолжение) — Phase 2.4a Vision photo-binding fixes

Боевой кейс M-2026-0673: «Резистор LAD4RCU» и «Пускатель A013250» получили одно и то же фото (att=1370 — общий план обоих устройств), «Доп.контакт A013256» получил closeup LAD4RCU (att=1374). Vision-промпт image_index не различал «главный объект» и «виден в кадре», не запрещал dup-mapping. Три патча:

**Шаг 1 — UI ручная перепривязка** (`c331c7d`):
- `RequestItemEditor::rebindPhoto(RequestItem, ?int $attachmentId, User)` — валидация: attachment принадлежит тому же email_message_id, mime_type начинается с `image/`. Идемпотентен. Audit `payload.manual_edits[]` с `action=rebind_photo`.
- Livewire `ItemPhotoRebindDialog` (`app/Livewire/Requests/Items/`) — слушает `open-photo-rebind {itemId}`, computed `photoAttachments` отдаёт все image-вложения письма в порядке id ASC (тот же что видел Vision). Grid 3-5 колонок с превью + плитка «⊘ без фото». Тот же modal-паттерн что у `ItemCatalogLinkDialog`.
- Пункт «📷 Сменить фото…» в dropdown `⋮` строки позиции (`_item-row.blade.php`).
- Registered в Detail рядом с item-edit / catalog-link диалогами.

**Шаг 2 — Vision-промпт CoT** (`a39e314`):
- Двухшаговый Chain-of-Thought в `RequestItemParsingService::parseItemsFromPhotoMarkings()`:
  - **ШАГ 1: `photo_descriptions[]`** — для каждого фото `{index, main_subject, secondary_items[], has_readable_marking}`. Модель сначала разбирает все 10 фото, потом распределяет.
  - **ШАГ 2: image_index с правилами** — (а) главный объект, не «виден»; (б) closeup приоритет; (в) запрет дубликатов кроме group-shot; (г) «лучше null чем угадывать».
- `max_tokens` 4096 → 6144 (CoT-preamble ~80-150 токенов на фото).
- В Log: `photo_descriptions`, `image_index_distribution` (array_count_values) — видно как Vision сам обосновал каждый index, легко регресс-анализировать.

**Шаг 3 — filename fallback** (`9709eaa`):
- `MessagePersister::persistAttachment` баг: `?? ` срабатывал только когда `getName()` возвращал `null`. iPhone-фотки приходят с `Content-Type: image/jpeg` БЕЗ name=/filename= параметров — webklex отдаёт `''`, fallback не срабатывал, в БД попадал пустой string. UI показывал UUID-storage-key вместо имени.
- Fix: `trim((string)$att->getName())` → если пусто → синтезируем `<disposition>-<random>.<ext>` (e.g. `inline-a3b2c1d4.jpg`). `guessExtension()` — таблица 20 MIME-типов (jpeg/png/heic/pdf/xlsx/zip/…).
- CLI `mail:backfill-attachment-names` для исторических записей (~200 заявок). `--dry-run`, `--chunk=N`. Идёт по `filename IS NULL OR filename = ''`.

#### Грабли Phase 2.4a

- **Vision dup-mapping** — модель честно подбирает фото где товар *виден*, а не где он *главный объект*. Один общий план легко прилипает к двум-трём позициям. CoT-preamble + явное правило «main_subject vs secondary_items» в промпте — лечится. UI rebind — страховка.
- **`?? ` не ловит пустую строку** — классическая PHP-грабля. `getName() ?? 'fallback'` — fallback не сработает если getName() вернул `''`. Нужно `trim((string)$x); if ($x === '') ...`.
- **webklex `Attachment::getName()`** ведёт себя по-разному для разных клиентов: iPhone/iOS Mail часто шлёт inline-фото без `name=`/`filename=` → null или ''. Outlook/Yandex web — MIME-encoded в name. Содержимое RFC обычно есть, но `Content-Disposition: inline; filename=` не обязательно.
- **gpt-4o с `response_format: json_object`** прекрасно принимает структурированный CoT — `photo_descriptions[]` + `items[]` в одном ответе. Не надо выносить в отдельный preceding-call.

#### Деплой Phase 2.4a

```bash
cd /var/www/mzcorp
sudo -u www-data git pull --ff-only origin main
sudo -u www-data composer dump-autoload --optimize        # ItemPhotoRebindDialog + MailBackfillAttachmentNamesCommand
sudo -u www-data php artisan view:clear && sudo -u www-data php artisan view:cache
sudo -u www-data php artisan route:clear && sudo -u www-data php artisan route:cache
sudo supervisorctl restart mzcorp-worker:*

# Backfill пустых filename (~200 заявок, ~1500 attachment'ов):
sudo -u www-data php artisan mail:backfill-attachment-names --dry-run
sudo -u www-data php artisan mail:backfill-attachment-names
```

Тест нового Vision-промпта на M-2026-0673 (если оператор согласен потерять текущие image-привязки):
```bash
sudo -u www-data php artisan tinker --execute='
$r = App\Models\Request::where("internal_code", "M-2026-0673")->first();
\App\Jobs\Mail\ParseRequestItemsJob::dispatchSync($r->email_message_id, force: true, reset: true);
'
sudo grep -A 30 'parseItemsFromPhotoMarkings: GPT responded' /var/www/mzcorp/storage/logs/laravel.log | tail -60
```

### Сессия 2026-05-17 — Phase 4.0 DocumentDetector

Четыре коммита `b876244..5ebe671`. Полная реализация Foundation §7 включая validation framework (Foundation §7.3) — auto-mode выключен по умолчанию для всех 8 типов, РОП включает per type через Settings после валидации.

**Commit 1 — schema** (`b876244`):
- Migration `2026_05_17_120000_create_ai_decisions_table` — журнал AI-решений (detector_type + status lifecycle + confidence + payload jsonb + applied_by audit). Composite индексы `(request_id, status)` и `(detector_type, status, created_at)`.
- Migration `2026_05_17_120001_add_closed_lost_quote_to_requests_table` — Foundation §7.4 (`closed_lost_quote` text + `closed_lost_source_message_id` FK).
- Enums `DetectorType` (10 значений) с `targetStatus()` map; `AiDecisionStatus` (6 значений).
- `AiDecision` model + relations Request::aiDecisions / closedLostSourceMessage.
- `RequestStateService::transitionTo` принимает в context `closed_lost_quote` + `closed_lost_source_message_id` (для inbound-classifier).

**Commit 2 — outbound rule-based detector** (`9fb94f5`):
- `OutboundDocumentDetector::analyze()` — без OCR/PDF-разбора:
  - filename regex (RU/EN: `КП_*.pdf`, `Quote-*`, `Счёт_*.pdf`, `Invoice-*`, `Bill_*`, `INV-*`)
  - body/subject keywords (~25 фраз, normalize ё→е для recall)
  - confidence 0.95 / 0.90 / 0.65 / 0.60 от комбинации сигналов
  - priority: invoice > quotation > clarification
  - clarification только если НЕТ relevant-attachments
- `AiDecisionService::recordSuggestion / apply / override / dismiss` — single source of truth жизненного цикла. Идемпотентен по (email_message_id, detector_type).
- MailRouter outbound branch: после OutgoingMailLinker если linked Request → анализ → recordSuggestion.
- UI plashka в Detail action-panel ВЫШЕ кнопок: иконка + label + confidence% + target-status + apply/dismiss. Apply disabled если переход не разрешён из текущего статуса.

**Commit 3 — inbound LLM classifier** (`cefd439`):
- `ClassifyClientResponsePrompt` (gpt-4o-mini) — 6 intent'ов: under_review_acknowledgment / postponement_request / invoice_request / decline_with_reason / clarification_response / unclear. Извлекает `suggested_resume_date`, `suggested_closed_lost_reason`, `cited_phrase` (точная цитата из письма).
- `InboundIntentClassifier::isApplicable()` — фильтр статуса Request (quoted / under_review / postponed / awaiting_clarification). `classify()` — confidence floor 0.6 (ниже → unclear). clarification_response downgrade в unclear если статус Request не AwaitingClientClarification.
- MailRouter inbound branch: после InboundReplyLinker если applicable → classify → recordSuggestion.
- `AiDecisionService::apply` пробрасывает inbound postponed `suggested_resume_date` в state_change.payload (AttentionService::postponedUntilFor читает её). Для decline pre-filled `closed_lost_reason` + `cited_phrase` → `closed_lost_quote` + `closed_lost_source_message_id`.
- UI plashka: cited_phrase отображается italic blockquote (если decline), иначе reasoning.

**Commit 4 — validation framework** (`5ebe671`):
- Settings (9 новых полей в группе «DocumentDetector · auto-mode»):
  - `detector.confidence_threshold` (float, default 0.85)
  - 8× `detector.auto_mode.<type>` (bool, default false)
- `AiDecisionService::recordSuggestion` — если `shouldAutoApply(type, confidence)` (auto-mode включён И confidence ≥ threshold) → apply сразу с `auto=true` (status=auto_applied, без UI-подтверждения).
- Dashboard РОПа — новая таблица «AI quality (детектор · 30 дн.)»: per DetectorType counts (auto + confirmed / overridden / dismissed / pending) + correctness% с цветовой индикацией (≥90 emerald, ≥70 amber, <70 red). Скрыты строки где total=0.

#### Грабли Phase 4.0

- **Idempotency suggestion'ов** — повторный запуск пайплайна (sync re-pull одного и того же письма) не должен плодить дублей. Проверка по `(email_message_id, detector_type, status=suggested)` в `recordSuggestion`. Финальные status (auto_applied и т.д.) не блокируют новый suggestion на следующее письмо.
- **clarification_response trap** — LLM любит ставить этот intent любому ответу клиента. Дополнительный server-side gate: downgrade в unclear если Request.status ≠ AwaitingClientClarification.
- **`apply` рекурсия** — recordSuggestion при auto-mode дёргает apply(auto=true), который вернёт fresh()→Decision. Если apply падает (transitionTo throws) — переводим в Failed, не в Suggested (чтобы не висело в pending).
- **Confidence floor 0.6** — задан и в промпте, и в коде. Дублирование сознательное — LLM иногда возвращает 0.55 и `intent` ≠ unclear, не доверяя промпту в одиночку.
- **Auto-mode по умолчанию выключен** — это критично. Foundation §7.3 описывает 1000+ выборку перед включением. Не включать ни один toggle на старте, даже если соблазн «outbound_invoice с confidence 0.95 — точно правильно».

#### Деплой Phase 4.0

```bash
cd /var/www/mzcorp
sudo -u www-data git pull --ff-only origin main
sudo -u www-data composer dump-autoload --optimize
sudo -u www-data php artisan migrate --force          # 2 новые миграции
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:clear && sudo -u www-data php artisan view:cache
sudo supervisorctl restart mzcorp-worker:*

# Проверка что MailRouter подхватил новые сервисы:
sudo -u www-data php artisan tinker --execute='
$r = app(App\Services\Mail\MailRouter::class);
echo "MailRouter OK\n";
$o = app(App\Services\DocumentDetector\OutboundDocumentDetector::class);
echo "OutboundDetector OK\n";
$i = app(App\Services\DocumentDetector\InboundIntentClassifier::class);
echo "InboundClassifier OK\n";
'
```

После 2-4 недель боевого режима оценить точность через Dashboard «AI quality» — если correctness% по конкретному detector_type ≥ 99% на ≥1000 решений, можно осторожно включить auto-mode в Settings.

### Сессия 2026-05-18 — Phase 5.2 Reanimate closed

Один коммит `6e51dc4`. Foundation §5.2 — закрытые заявки реанимируются автоматически при ответе клиента, без создания дубликата.

**Migration** `2026_05_18_120000_add_reanimated_fields_to_requests_table`:
- `reanimated_at` (timestamp nullable) — момент последней реанимации
- `reanimated_count` (smallint default 0) — счётчик циклов

**RequestStateService::reanimate(Request, ?User, EmailMessage)**:
- Guard: только `ClosedLost`. `ClosedWon` → `DomainException` («сделка состоялась, новое письмо = новый запрос»).
- Snapshot `closed_at` / `closed_lost_reason` / `closed_lost_comment` / `closed_lost_quote` / `closed_lost_source_message_id` → `request_state_changes.payload.restored_from`.
- Очищает все closed_lost_* поля.
- status → `InProgress` (Foundation §5.2 говорит `qualifying`, но в нашей state-machine эквивалент = InProgress: заявка уже была assigned + распарсена, менеджер сразу видит в Pool).
- `reanimated_at = now()`, `reanimated_count++`.
- Audit `event='reanimate'`, `comment='Клиент написал после закрытия — реанимация'`, by_user_id=null (если author не передан — линкер ставит null).
- AttentionService::recompute (новый SLA-дедлайн от now).

**InboundReplyLinker integration**:
- В `tryLink` после успешного match'а проверяем `$request->status`:
  - `ClosedLost` → вызываем `stateService->reanimate($request, null, $message)`; `matchedBy` дополняется `:reanimated`.
  - `ClosedWon` → возвращаем null (IncomingMailProcessor создаст новую Request).
  - Иначе — обычный flow.
- `matchByOpenRequestForFromEmail` (level 4) расширен **level 4-bis**: если open-кандидатов нет, ищем `ClosedLost` с тем же `client_email` + `closed_lost_reason IN (silent_reasons)` + `closed_at >= now() - 180 дн`. Constant `REANIMATE_FROM_EMAIL_SILENT_REASONS` = `['no_client_response_to_clarification', 'no_client_response_to_quote', 'manual_other', 'off_topic']`. Декларативные decline'ы (price/timing/competitor) сюда не входят — клиент явно отказался, по этому уровню НЕ реанимируем. Header-threading (levels 1-3) реанимирует ВСЁ ClosedLost независимо от reason — там есть явный thread-link, контекст важнее.

**UI Hero status row**: violet chip «↻ реанимирована ×N · M дн.» рядом со статус-чипом если `reanimated_count > 0`. Tooltip = «Реанимирована DD.MM.YYYY HH:MM · циклов: N».

**Activity-tab**: новый kind `state-reanimate` для event=`reanimate`. Violet dot + иконка ↻ (matches hero chip цвет). by-line = «InboundReplyLinker · автоматически» если by_user_id отсутствует.

#### Грабли Phase 5.2

- **Реанимация через header-threading vs from_email** — разные guard'ы. Levels 1-3 (In-Reply-To/References/subject-code) реанимируют любой ClosedLost, потому что есть явный thread-link и контекст важнее false-positive risk. Level 4-bis (from_email) — только silent reasons + lookback 180 дн, потому что match по email без header'ов слишком слабый сигнал.
- **ClosedWon никогда не реанимируется** — даже header-threading на closed_won возвращает null из tryLink → IncomingMailProcessor создаёт новую Request. Это сознательное решение: сделка состоялась, новое письмо клиента — другой контекст.
- **`reanimate` НЕ через `transitionTo`** — отдельный метод сервиса. Causes: (1) `closed_lost → in_progress` запрещён `allowedTransitions()` map (terminal!), и нет смысла открывать; (2) нужны специфичные поля (`reanimated_at`, snapshot в payload) которые transitionTo не знает. Если когда-нибудь будем выносить state-machine в общую абстракцию — этот частный путь стоит сохранить.
- **`reanimated_count` после двух реанимаций может быть 2+** — это нормально и видно в UI как «×2». Если оператор закрыл reanimated-заявку и она снова получила ответ — снова reanimate. История каждого цикла в `request_state_changes` (filter `event=reanimate`).

#### Деплой Phase 5.2

```bash
cd /var/www/mzcorp
sudo -u www-data git pull --ff-only origin main
sudo -u www-data php artisan migrate --force          # 1 миграция
sudo -u www-data php artisan view:clear && sudo -u www-data php artisan view:cache
sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache
sudo supervisorctl restart mzcorp-worker:*

# Бэкфилл reanimated_count для исторических данных НЕ нужен —
# поле default 0, реанимации до Phase 5.2 не были возможны (closed_lost
# был окончательным терминалом).
```

После деплоя — мониторить лог на предмет реанимаций (ожидается несколько за первую неделю на closed_lost из августа-апреля):
```bash
sudo grep 'RequestStateService: reanimated' /var/www/mzcorp/storage/logs/laravel.log | tail -20
```

### Сессия 2026-05-19 — Foundation Фаза 2 (хвост)

Три коммита (`c4326ee`, `ccb01e7`, `1b2b917`) после сверки плана с реализацией. Закрыты три остававшихся пункта Foundation Фазы 2: менеджер «недоступен», auto-rejection irrelevant + reopen UI, базовые in-app notifications.

**Commit 1 — `c4326ee` менеджер «недоступен»**:
- Migration `2026_05_19_120000_add_unavailability_to_users_table` — `users.unavailable_until` (timestamp nullable) + `unavailable_reason` (varchar 500).
- `User::scopeAvailable()` — `archived_at IS NULL AND (unavailable_until IS NULL OR <= now())`. Возврат в пул автоматический — cron не нужен.
- `User::isUnavailable()` — для UI-маркеров.
- `AssignmentService::autoAssign` использует `available()` вместо `active()` — менеджеры в отпуске не получают новые заявки.
- `ManagerUnavailabilityService` — single source of truth: `markUnavailable / markAvailable / reassignActiveRequests`. Batch reassign отвязывает заявку (assigned_user_id=null) и дёргает autoAssign — sticky-resolver САМ не вернёт недоступного, потому что он выбит из `available()`. Если других менеджеров нет — возвращает заявку как было (skipped counter).
- UI Admin/Managers: chip-warn «недоступен до DD.MM.YYYY» в status-колонке, tooltip с reason. Кнопка «⏸ Недоступен…» открывает `UnavailabilityDialog` (date + reason min 3 chars + checkbox «передать активные заявки», default=true). Cap 120 дней на дату — простой sanity.

**Commit 2 — `ccb01e7` auto-rejection + reopen UI**:
- Route `/dashboard/mail-review` (role:head_of_sales,director).
- Livewire `Admin\MailReview\Index`: filterable listing inbound писем где `ai_classification ≠ request` AND `related_request_id IS NULL`. Период (today/7d/30d/90d/all) + chip-row категорий с counters.
- Action `reopenAsRequest(int $emailId)`: создаёт Request (Pending) + email→related_request_id + audit `manual_reopen_as_request` в `email_messages.detected_artifacts` (тот же столбец что DocumentDetector использует — единая audit-точка для AI-overrides) + dispatch ParseRequestItemsJob. Дальше pipeline идёт как обычно (RequestItemPersister → autoAssign → MailFolderRouter).
- Action `confirmRejection(int $emailId)`: audit `manual_confirm_rejection` без изменений — для статистики «сколько раз РОП согласился с AI».
- Nav-link «Авто-отклонённые» в `resources/views/layouts/navigation.blade.php`.

**Commit 3 — `1b2b917` in-app notifications**:
- Migration `2026_05_14_093649_create_notifications_table` — стандартная Laravel notifications table (UUID id + morphs notifiable + data jsonb + read_at). Дата файла из `php artisan notifications:table` (Herd-системная дата мог быть прошлым) — порядок применения миграций не зависит, не баг.
- `App\Notifications\RequestAssignedNotification(Request $r, string $reason)` — статическая фабрика `::from(Request)`. via=['database']. Диспатчится из `AssignmentService::autoAssign` после save (try/catch — non-fatal на ошибке).
- `App\Notifications\RequestAttentionOverdueNotification(Request $r)` — to-database. Диспатчится из `AttentionService::sweepOverdue` ТОЛЬКО на переход level 0→1. Логика sweep'а переделана: сначала `pluck('id')` тех кто переходит, потом UPDATE по ids; иначе bulk UPDATE не вернул бы перечень.
- Livewire `Notifications\Bell` — `wire:poll.30s`. Computed `unreadCount` + `recent` (8 последних, unread сверху через `read_at IS NULL DESC`). Actions: `toggle / close / markRead(string $id) / markAllRead`. Bell в topbar заменил placeholder-кнопку с disabled-классом.

#### Грабли Foundation Фазы 2 (хвост)

- **`scopeAvailable` не нужен cron** — он реакционный: при чтении смотрит `unavailable_until <= now()`. Если менеджер «недоступен до 25.05», 26.05 он автоматически снова в available(). НО UI продолжает показывать chip «недоступен до 25.05» пока РОП не нажмёт «Доступен» (чтобы обнулить поля). Это сознательно: chip остаётся как след «был в отпуске», но AssignmentService уже не фильтрует.
- **Bulk reassign + sticky** — если sticky-резолвер находит другую заявку у недоступного менеджера, по идее sticky сматчит обратно. Но scopeAvailable исключает недоступного из `$managers` collection, который передаётся в `pickStickyManager` — поэтому matching idle. Корректно.
- **detected_artifacts как audit-stack для AI-overrides** — `email_messages.detected_artifacts` jsonb уже использовался DocumentDetector. Сейчас туда же пишутся `manual_reopen_as_request` и `manual_confirm_rejection`. Поле логически «всё что AI решил и оператор подтвердил/изменил по этому письму». Может разрастаться, но FIFO-truncate ещё не сделан — стоит держать в уме (для items есть 50-cap в RequestItemEditor).
- **AttentionService sweep — bulk UPDATE→pluck rework** — раньше bulk update делал всё одним SQL. Теперь сначала pluck(), потом whereIn UPDATE, потом for-loop notify. Слегка тяжелее на больших объёмах overdue (>1000 строк), но на нашем масштабе незаметно. Не оптимизировать преждевременно.
- **Notification queue** — `via=['database']` пишет напрямую через Eloquent в той же транзакции вызова. Если AssignmentService уже в DB::transaction, notify может затормозить commit. Не баг, просто наблюдение — на большом объёме стоит вынести в queued notification (`implements ShouldQueue`).

#### Деплой Foundation Фазы 2 хвост

```bash
cd /var/www/mzcorp
sudo -u www-data git pull --ff-only origin main
sudo -u www-data composer dump-autoload --optimize        # новые PSR-4 классы (Notifications + UnavailabilityDialog + MailReview)
sudo -u www-data php artisan migrate --force              # 2 миграции: unavailability + notifications
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:clear && sudo -u www-data php artisan view:cache
sudo -u www-data npm run build                            # новый Bell-component в topbar — Tailwind-классы могут не быть в текущем build
sudo supervisorctl restart mzcorp-worker:*

# Проверка route'а и services:
sudo -u www-data php artisan route:list --name=mail-review
sudo -u www-data php artisan tinker --execute='
echo app(App\Services\Request\ManagerUnavailabilityService::class)::class.PHP_EOL;
echo app(App\Livewire\Notifications\Bell::class)::class.PHP_EOL;
'
```

После деплоя — РОП должен увидеть в topbar bell-icon (badge=0 на старте); в Admin/Managers — кнопки «⏸ Недоступен…»; в nav — «Авто-отклонённые». Первые notifications появятся при следующем sweep AttentionService (через ≤15 мин) или при следующей входящей заявке (autoAssign).

#### Текущее состояние на проде (2026-05-15 вечер)

- `request_state_changes` — пустая (первые записи появятся при ручных transitions через UI после деплоя).
- `requests.paused_*` / `closed_at` / `closed_lost_*` — пустые (миграция применена, поля nullable).
- `request_items.quality_assessment_status` — расширен enum (`internal_catalog_not_found` есть в CHECK).
- `email_messages.is_draft` — boolean индекс, partial unique на (mailbox_id, folder, message_id) WHERE is_draft=false.

#### Деплой-чек-лист после `1e9e558`

```bash
cd /var/www/mzcorp
sudo -u www-data git pull --ff-only origin main
sudo -u www-data composer dump-autoload --optimize    # много новых PSR-4 классов
sudo -u www-data php artisan migrate --force          # три миграции из этой сессии
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:clear && sudo -u www-data php artisan view:cache
sudo -u www-data npm run build
sudo supervisorctl restart mzcorp-worker:*

# Поднять PHP-FPM лимиты (если ещё не сделано — для compose attachments):
sudo tee /etc/php/8.3/fpm/conf.d/99-mzcorp-uploads.ini > /dev/null <<'EOF'
upload_max_filesize = 30M
post_max_size = 60M
max_file_uploads = 40
memory_limit = 256M
EOF
sudo systemctl restart php8.3-fpm

# Cron для resume-paused — проверить:
sudo -u www-data php artisan schedule:list | grep resume-paused
```

### Сессия 2026-05-19 (часть 2) — ItemCatalogLinkDialog UX: ширина + фильтры + hover

UX-доработки диалога «Привязать позицию к каталогу» по живому фидбэку оператора.

**1. Ширина колонки «Название»** — `max-w-[760px]` → `max-w-[1040px]` модала (compare-mode остался 1200). Colgroup сжат: compare 32→28, photo 56→44, sku 90→80, brand/article 160→120, price 90→84, stock 80→72, similarity 80→72. Освобождает ~270px под name (с ~150px до ~420px). `table-layout: auto` → `fixed` чтобы width-инструкции работали жёстко.

**2. Hover-preview фото каталога** (`_catalog-results-table.blade.php`):
- Delay 1000ms → **700ms** (стандарт tooltip).
- На входе `openPreview` сразу `this.show = false` — предыдущее превью не висит с прошлым url до срабатывания нового таймера.
- Позиционирование привязано к **`el.closest('.ds-card')` — самому модалу**, а не миниатюре. Превью открывается слева от модала (если ≥400+12px), иначе справа от модала. Никогда не накрывает чекбокс comparison, колонку SKU и прочий контент таблицы. Fallback на старое поведение (relative к миниатюре) — только если окно ýже модала+400px.
- Pointer-events: none на превью — оставлено (мышь сквозь превью видит чекбокс/строки).

**3. Diagnostic chip-теги под названием** (показывают, *почему* позиция в выдаче):
- `unit_name` (sky chip), `part_type`, `form_factor` (neutral chips).
- Размеры `size_a..f` собраны в mono-chip типа `«62×40×10 мм»` (amber).
- Multi-OEM: если `articles[]` содержит больше одного артикула (помимо `brand_article`) — `+N OEM` chip.
- `CatalogSearchService::search` SELECT расширен полями `form_factor`, `articles`, `size_a..f` (раньше тащил только базовые — chips на text-вкладке были бы пустыми). Для similar-вкладки embedder уже возвращал full Eloquent models (`whereIn('id', $ids)->get()` без select-маски) — не трогали.

**4. Три chip-фильтра над таблицей** (между tabs и search-input):
- 🏷️ **Бренд: <subject.brand>** — sky-tone, exact match `lower(trim(catalog.brand)) === lower(trim(subject.brand))`.
- 🎯 **Категория: <subject.kbCategory.name>** — emerald-tone. Хрупкая эвристика (catalog не хранит structured category): первое слово KB-name ≥4 симв (избегаем «без»/«для»/«над») → substring в `lower(catalog.name + unit_name + part_type)`. Точный structured-фильтр потребует мапинг-таблицы или новой колонки `catalog_items.category_id` — отложено.
- 📏 **Размер: <a · b · c>** — amber-tone, исходит из `subjectDimensions` (regex по `parsed_name + parsed_article`). Покрывает форматы `62×40×10`, `1700 мм`, `L=1141.5`. Match: хотя бы один из subject-dims попадает в любой `size_a..f` каталога с допуском **±5 мм**.

Реализация: все фильтры **post-fetch** в `applyChipFilters(array $rows): array` — применяются к уже полученному топ-N. Включение бренда может схлопнуть 10 кандидатов в 2-3; UI показывает «Все кандидаты отфильтрованы chip'ами выше — снимите хотя бы один». Backend-side фильтрация (передача WHERE в pgvector / CatalogSearchService) — следующая итерация, потребует трогать `topNByQueryText` и trigram-search SQL.

Default OFF для всех трёх — оператор включает руками. Не сюрприз «куда делись результаты».

**5. Унификация формата результатов**. `textResults` теперь возвращает `array<{catalog, similarity:null}>` вместо `Collection<CatalogItem>` — чтобы `applyChipFilters` работал единообразно с `similarResults`. Партиал `_catalog-results-table` принимает `collect($results)` — поведение без изменений.

**Грабли сессии**:
- **Computed property → метод**: `subjectDimensions` сделан `#[Computed]` чтобы (1) показывать в chip без перерасчёта (2) использовать в `applyChipFilters` через `$this->subjectDimensions` (property access). Перерасчёт regex'ов кешируется на render. `subjectCategoryKeyword()` оставлен private method — internal helper.
- **`->isEmpty()` vs `empty()`**: после смены `textResults` на array — blade-проверка изменена с `$results->isEmpty()` на `empty($results)`.

**После деплоя нужно**: `php artisan view:clear` (blade-кеш) — иначе старый hover-delay (1000ms) может временно остаться видимым.

**Что НЕ сделано из наброска оператора** (отложено): tabs «По артикулу» / «По фото», бюджет / SLA в шапке, +N% к бюджету в цене, multi-select привязки, фильтр по серии, фильтр по длине L=X±5% (нет канонической оси), цветовая легенда похожести.

### Сессия 2026-05-19 — semi-auto cleanup: удалены manual inbound intent кнопки

Закрыт open question «InboundIntentClassifier auto-apply кнопок в action-panel».

**Что удалено** (resources/views/livewire/requests/detail.blade.php):
- 📑 «Клиент на согласовании» (`transitionStatus('under_review')`)
- ⏰ «Клиент отложил» (`$dispatch('open-postpone-dialog')`)
- 💵 «Запросил счёт» (`transitionStatus('awaiting_invoice')`)

Все три перехода теперь идут только через `InboundIntentClassifier` (gpt-4o-mini, 6 intent'ов) → `AiDecisionService::recordSuggestion` → AI-плашка в action-panel с кнопками «✓ Применить» / «✕ Dismiss». `apply()` для `PostponedUntil` вытаскивает `suggested_resume_date` из payload → пробрасывает в `context['payload']['postponed_until']` без UI-диалога (`AiDecisionService.php:155-161`).

**Что оставлено как safety net**:
- ⊘ «Закрыть как потеря» (CloseLostDialog с reason-taxonomy) — AI вытаскивает `suggested_closed_lost_reason` для apply, но manual вход с reason'ом важен для ClosedLostReason precision.
- ↩ «Вернуться к работе» / ✓ «Клиент ответил» (override) — implicit-state не покрывает все сценарии.
- 💴 «Счёт отправлен» / 💰 «Оплачено» / ✓ «Закрыть как успех» — outbound события менеджера/бухгалтерии, не inbound intent.

**Dead-UI**: `<livewire:requests.postpone-dialog>` тег и сам компонент `PostponeDialog` оставлены в репо. После удаления manual-кнопки никто не диспатчит `open-postpone-dialog`. По абсолютному правилу #1 («не удалять без явного запроса») файл сохранён — может пригодиться для будущей фичи «Manual override для semi-auto статусов» (dropdown «⋮ Изменить статус → …») в open questions.

**Слепые зоны после удаления**:
- Если confidence < 0.6 → downgrade в `InboundUnclear` → apply-disabled, manual fallback отсутствует. Менеджер ждёт следующего письма / эскалирует РОПу.
- Если клиент пишет «оплатим в среду» без явного «отложим до…» — AI может не сматчить точно postpone-intent → менеджер не сможет руками открыть диалог отсрочки.

Если real-world precision окажется ниже ожиданий — открываем TODO «Manual override для semi-auto статусов».

**Также** в архитектурном блоке шапки MEMORY обновлена строка «Кнопки в action-panel убраны» — добавлены три удалённые кнопки в список.

### Сессия 2026-05-18 (часть 3) — multi-invoice parsing + hybrid search refinements

Дебаг M-2026-1032 (клиент Мария Зайцева, METEOR Лифт Москва): письмо
с подзаголовками «1 счет :» и «2 счет:», 9 строк по двум счетам, в UI
показывалось 7-8 вместо 9. Долгая итерация.

**Коммиты:** `ac09380` … `1c1d604` (≈14 коммитов).

**1. Hybrid search — фильтр шумовых code-токенов** (`ac09380`).
`extractCodeTokens` в `CatalogEmbeddingService` сейчас требует ≥5 raw
chars, ≥5 norm chars, ≥2 цифр. Раньше ≥3 симв. ловил «5ММ», «3МС» из
запросов типа «канат 6-6,5мм, 1,3м/с» → 95% 🎯 code-match по
случайным позициям. После — реальные артикулы (M04557, ПКЛ32,
ЕИЛА68725500804, 12067R1) проходят, шум режется.

**2. Multi-source бонус в ранжировании** (`4c243cd`). Tiebreaker за
дополнительные источники поднят с +0.001 до +0.05 (`multi/2 = +0.05,
multi/3 = +0.10`). Multi-source подтверждение теперь реально бьёт
single-source code 0.95.

**3. Парсер позиций — multi-invoice case** (`bebc230`, `38e5708`,
`5aaaf87`, `1199a83`, `1c1d604`). Корневой баг: парсер дедуплицировал
дубли артикулов между разными счетами, теряя позиции второго счёта.
Цепочка фиксов:
- В `ParseItemsPrompt` добавлен top-level раздел «КРИТИЧЕСКОЕ ПРАВИЛО:
  НЕСКОЛЬКО СЧЕТОВ В ОДНОМ ПИСЬМЕ» + Пример 4c. CoT через обязательное
  поле `invoice_analysis: {client_requested_invoices, blocks_found}`
  в JSON-выходе → LLM сначала считает счета, потом извлекает items.
- В items добавлено обязательное поле `invoice_index` (1-based, к
  какому счёту относится позиция).
- В Vision-промпт (`parseItemsFromPhotoMarkings`, inline в
  `RequestItemParsingService`) добавлены ТЕ ЖЕ правила про
  multi-invoice + invoice_index. Это закрыло main bug — у MZaytseva в
  письме был CID-attached screenshot таблицы (`image003.png`, 14KB),
  Vision видел всю таблицу, выдавал 9 items с invoice_index=1 у всех.
- Vision-промпт также теперь знает про OEM + M-SKU дубль-артикул
  (правило раньше было только в text-промпте, Пример 4b): «если рядом
  с OEM виден `M\d{4,6}` — оба в article через запятую».
- `dedupeWithinList` ключ перешёл с `article` на
  `article + qty + invoice_index`. Multi-invoice позиции с разным
  invoice_index сохраняются, реальные дубли (photo+text) режутся.
- `normalizeParsedItem` читает `invoice_index` из LLM-ответа,
  default=1. Поле transient (не сохраняется в БД), используется
  только в pipeline дедупа.

**4. Новые команды**:
- `requests:reparse-items {internal_code+} --apply` — точечный re-parse
  заявки по internal_code. Внутри: items()->delete() + persist в
  транзакции. Без `--apply` — dry-run.
- `mail:debug-parser-input {email_id}` — показывает что cleaned body
  выглядит до LLM: body_plain vs htmlToText → cleanInboundReferenceText
  → эвристики (счёт-вхождения, M-SKU). Не дёргает LLM (бесплатно).

**5. Диагностические Log::warning** (`5770e35`, `3a41b30`, `6b59b00`):
- `parseItemsFromInbound: LLM response` — raw items_brief (article/qty/
  invoice_index) после text-парсера.
- `parseItemsFromInboundContent: pipeline summary` — какой путь набрал
  какие items (images_count / structured_count / tried_text /
  items_before_dedup / items_articles).
- Поставлены на warning (а не info), потому что на проде LOG_LEVEL=info,
  но Log::info в текущей пилотной фазе теряется. Переключить на info
  когда устаканится.

**6. Регрессия sticky 3-level** (`b6cd653`). Миграция
`request_assignments.reason` varchar(64) → varchar(512). Sticky-фикс
коммита `de32270` стал писать в reason JSON `auto_sticky:{"kind":...,
"linked":[989,900,...]}` — для клиента с историей превышало 64 симв.,
INSERT падал, валил ParseRequestItemsJob, ResolveKbJob не дёргался.
~52 заявок за 3 дня стояли с qa_status=not_assessed без catalog match.

**7. Каталог = master data** (`5a58b0b`). По указанию заказчика
переписана семантика import'а: позиции из каталога НИКОГДА не
soft-deletаются, даже если их нет в новой выгрузке MDB. Если позиция
не пришла — `stock_available=0` + `is_price_actual=false`. 768 ранее
soft-deletаных каталог-позиций восстановлены через миграцию
`2026_05_18_180000_reframe_soft_delete_to_unavailability`.

**Восстановленные кейсы**:
- M-2026-1064 (M04557): добавлен name-as-article fallback в
  `CatalogResolutionService::matchByArticle` (`a00acb4`).
- M-2026-1063 (M00468): был не сматчен из-за sticky-варчар-регрессии.
  После миграции и re-parse — норм.
- M-2026-1032: long journey, итог — 9 позиций по двум счетам, все с
  правильными OEM + M-SKU артикулами.

**Грабли сессии**:
- **`dedupeWithinList` как «невидимый» фильтр**: я долго бил по
  промпту, не подозревая что post-process на стороне PHP схлопывает
  правильные 9 items в 7. Урок: при парсер-багах первым делом
  логировать raw LLM response до пост-обработки.
- **`Log::info` на проде теряется**: даже при `LOG_LEVEL=info`. Для
  диагностики стартового запуска лучше `Log::warning`, потом понизить.
- **Промпт Vision и text — РАЗНЫЕ**: `ParseItemsPrompt` (text-only) и
  inline-промпт в `parseItemsFromPhotoMarkings` — два разных файла.
  Любое правило про items надо дублировать в обоих, иначе разные ветки
  парсера дают разные результаты.
- **CID-attached screenshot Outlook'а**: image003.png 14KB — не всегда
  лого. Outlook генерирует CID-image копии таблиц для рендеринга,
  Vision их видит как полноценный screenshot. `image_attachments`
  filter ловит ВСЁ, включая такие.
- **LLM нестабилен при temperature=0**: на одном и том же промпте мог
  выдать то 7, то 8 позиций. Промпт-only фиксы по такому случаю
  непредсказуемы.

### Сессия 2026-05-18 — Catalog UI, hybrid search, lightbox gallery, compare

Шесть UX/perf-улучшений диалога «Привязать позицию к каталогу» и связанных UI. Все на проде.

**Коммиты:** `d1f58ea` … `a0000c6` (≈20 коммитов).

**1. Custom-query в «Похожие из каталога» (#5)** — менеджер пишет свой запрос («Плата ПКЛ-32») вместо опоры на `parsed_name`, нажимает 🔍 → vector-поиск идёт по этому тексту. Кнопка «↺ Сбросить» возвращает дефолт.
- `CatalogEmbeddingService::topNByQueryText(query, n)` — отрефакторено из `topNByRequestItem`, параметризовано на text.
- `RequestItemEditor::findSimilarByQuery` — auth-checked wrapper.
- `ItemCatalogLinkDialog`: `$similarQuery`, `$similarQueryActive`, `applySimilarQuery()` / `resetSimilarQuery()`.

**2. Hybrid search (`code+trgm+vector`)** — pure vector рассеивался на длинных фразах. Сейчас три источника, merge по `catalog_id`, score=MAX, метод в UI помечен иконкой (🎯 code / 🔤 trgm / ✨ vector / 🔀 multi):
- **code-token ILIKE**: `extractCodeTokens(query)` тащит буквы+цифры ≥3 симв., ищет substring через `regexp_replace(lower(name), '[\s\-_./]', '', 'g') ILIKE ANY ?::text[]` + `brand_article_normalized ILIKE ANY`. Использует GIN trgm индекс.
- **trigram pg_trgm**: `word_similarity` на dehyphenated name + (опц.) `articles_search`. $useArticles требует ≥6 цифр в нормализованной форме.
- **vector**: OpenAI embed → pgvector. Deferred — дёргается только если code+trgm < limit.
- `*1.10` boost trigram над vector. Tiebreaker +0.001 за каждый дополнительный источник для multi-source.

**3. pg_trgm + GIN индексы (миграции)**:
- `2026_05_18_140000_enable_pgtrgm_and_index_catalog_items.php` — `CREATE EXTENSION IF NOT EXISTS pg_trgm` (fail-soft) + GIN на `lower(name)` и `brand_article_normalized`.
- `2026_05_18_150000_add_dehyphenated_name_trgm_index.php` — функциональный GIN на `regexp_replace(lower(name), '[\s\-_./]', '', 'g')`.
- `2026_05_18_160000_add_articles_search_column_to_catalog_items.php` — text-столбец `articles_search` = upper-concat нормализованных `articles[]` через `|`, PG trigger `BEFORE INSERT OR UPDATE OF articles` пересчитывает, backfill 35K строк, GIN trgm индекс. Закрыло EMMA-кейс: 1300мс → 12мс (290× быстрее) для verbose article-query.
- Beget Cloud DB — pg_trgm whitelisted, индексы созданы.

**4. Перф итог (тинкер на 35K каталоге)**:
- «Плата ПКЛ-32» — 48 мс (code-token)
- «Башмак кабины OTIS» — 11 мс (trgm)
- «EMMA.687255.008-04» — 495 мс (articles_search + trgm)
- «Плата управления ПКЛ-32 с ПЗУ ЕИЛА.687255.008-04» — 12 мс (multi-source)

Также `CatalogSearchService::search` (текстовый таб): убран `LOWER(sku/brand_article/name) ILIKE + ORDER BY CASE` full-scan, заменён на `lower(name) LIKE` через GIN trgm + sku ILIKE + brand_article_normalized.

**5. Lightbox с навигацией (gallery-mode)** — `resources/views/livewire/requests/detail.blade.php` лайтбокс расширен: принимает `{items: [{src,name,dl},...], index: N}`, рисует ‹ › кнопки (фон rgba(0,0,0,0.55)), счётчик «N/M», keydown.left/right.window. Legacy формат `{src,name,dl}` поддерживается — wrapped в 1-item gallery без стрелок.

Применено в:
- Диалог «Привязать позицию к каталогу» — мини-галерея 2×3 в шапке (subject); компактная карусель ‹›/counter в compare-режиме.
- Detail.blade tab «Переписка» — gallery per-message.
- Detail.blade tab «Файлы» — gallery всех image-вложений треда.
- Detail.blade tab «Позиции» — galleryItems = все image-вложения письма заявки (`$email->attachments`), index по `image_attachment_id → idx`. Передаётся в `_position-card.blade.php` явно через @include params (не через x-data inheritance — Alpine reactivity ломалась через Livewire morph).

**6. Compare-режим (#6)** — в результатах диалога чекбокс-колонка «⚖️», менеджер выбирает 1-3 каталога, «⚖️ Сравнить (N)» в тулбаре. Модал расширяется до `max-w-[1200px]`, рисуется grid: subject (sky-фон, мини-карусель фото) + 1-3 catalog-колонки. Полные поля: фото 1:1, SKU, brand, brand_article, все `articles[]` (multi-OEM), unit/part_type/form_factor, цена, наличие, вес, размеры A..F, статус. Кнопка «Выбрать»/«✓ Выбрано» в каждой → `selectCatalogId`. «← К списку» возврат.
- State: `$compareIds: array<int>` (max 3), `$comparing: bool`, `compareItems` Computed.

**Грабли сессии**:
- **Alpine `get` getters в x-data + Livewire morph**: `:src="lbCur.src"` через getter не обновлялся при `prev/next` — Alpine reactivity не трекала зависимости через computed getter. Решение: плоские поля `lbSrc/lbName/lbDl` + метод `sync()` который их обновляет после mutate `lbIdx`.
- **x-data scope через @include + wire:morph**: дочерний blade не всегда видел `items` из родительского x-data контейнера. Решение: передавать массив `'galleryItems' => $arr` параметром @include и инлайнить через `@js($galleryItems)` в каждой кнопке.
- **GIN индекс не используется**: expression в WHERE должно ТОЧНО совпадать с миграционным. `upper(regexp_replace(coalesce(name, ''), ...))` ≠ `regexp_replace(lower(name), ...)`. После выравнивания — индекс используется, perf падает в 10×.
- **word_similarity ассиметрична**: для случая «короткий артикул каталога vs длинный verbose query» порядок аргументов критичен. `word_similarity(catalog_article, user_query)` находит подстроку catalog_article в user_query, даёт ~1.0. Обратный порядок — теряется в длине union.
- **articles[] EXISTS = seq scan**: без денормализации `jsonb_array_elements_text(articles)` сканит 35K строк + парсит jsonb. Решение — `articles_search` text-столбец + PG trigger + GIN trgm.

**Ещё в этой сессии (мелочи)**:
- Sticky 3-level: `catalog_item_id → client_email → parsed_article/name` + UI fix `str_starts_with` в Pool.
- Configurable newbie boost X через AppSetting (`assignment.newbie_boost`, 1.0..10.0, formula `coef = 1 + (X−1) × (max−load)/(max−min)`).
- Outbound quotes — `base_unit_price`/`discount_percent`, всегда overwrite warnings на null при autofix (fix stale).



Phase 1.9 (UI-переписка), Priority 1 (ручное управление позициями), Phase 1.10 (state-machine), Phase 1.11 (Attention-механизм) — закрыты. На очереди — auto-переходы и реанимация.

### ~~Приоритет 1 — Attention-механизм~~ ✅ закрыт 2026-05-16

Реализовано в Phase 1.11. См. таблицу декомпозиции выше.

### ~~Приоритет 2 — DocumentDetector~~ ✅ закрыт 2026-05-17

Реализован в Phase 4.0 (четыре коммита `b876244..5ebe671`). См. таблицу декомпозиции выше.

### ~~Приоритет 3 — Reanimate closed~~ ✅ закрыт 2026-05-18

Реализован в Phase 5.2 (`6e51dc4`). См. таблицу декомпозиции выше.

### ~~Приоритет 4 — Регулярный sync MDB → прод~~ ✅ закрыт 2026-05-19

Реализован вместо Python-скрипта на office Task Scheduler — HTTP pull по public URL `mylift.ru/getxfile.php?id=...` напрямую с VPS. `CatalogSyncFromUrlCommand` + scheduler `0 3,7,11,15,19,23 * * *` MSK + mdbtools для конвертации .mdb → CSV → `catalog:import --apply`. State в `storage/app/private/catalog/.last_sync.json` (sha256, last_modified, last_pull_at, last_import_at, last_error). Ротация snapshots — keep последние 7 серий.

**Известный артефакт:** в исходной MDB колонка `Узлы` использует запятую как разделитель (`"Главный привод, лебедка лифта, гидравлическая станция и гидро бак"`), а `CatalogImportService::units_raw` парсер ждёт `;`. На выходе один длинный «unit» вместо трёх. Если станет проблемой — расширить split на `/[,;]/` в `CatalogImportService`.

### ~~Приоритет 5 — Дашборд РОПа v1~~ ✅ закрыт 2026-05-19

Реализован в `7c5e590..ea1a15a`: period switcher 7/30/90 (`#[Url(as: 'period')]`), funnel received→quoted→won/lost + quote_rate + conversion (`request_state_changes` distinct request_id per to_status в окне), inflow heatmap 7×24 (ISODOW × HOUR Europe/Moscow с 5-уровневой sky-палитрой), sparklines per менеджер (14-дн поток назначений из `request_assignments.assigned_at` + текущий load через inline SVG polyline, общий max-scale).

### Бэклог низкого приоритета

- **M-2026-0512 reparse** — проверить, что новый Vision-промпт даёт 3 позиции вместо 2 (Левая+Правая+Мотор).
- **Top-K vector + LLM best-of-N** (если C-recall окажется слишком низким на боевых письмах).
- **Liftway clarifications retroactive apply** — UI кнопка «применить все clarifications от Liftway за период».
- **Pending clarifications digest для РОПа** — еженедельная сводка clarifications, требующих action.
- **OAuth man2/man3** — failed_jobs за 2026-05-08 (1000 экземпляров). Сейчас токены живые, accumulate'ы старые. Можно почистить через `\DB::table('failed_jobs')->where('exception','LIKE','%OAuth access-token%')->delete()`.
- **Phase 1.9 outbound observer** — см. приоритет 3.
- **Dashboard polish**, **Manual edit позиций в UI**, **Backfill старого корпуса** (из прошлого плана).

<!-- legacy Phase 1.9 outbound план остался актуален и переведён в Приоритет 3. -->

### Мониторинг новых писем

После всех изменений Phase 1.8e + 1.9-inbound + Phase 2 catalog нужно следить за качеством на боевом потоке:

```bash
# Suspicious thread links (потенциально неверный AI clarifier choice)
sudo grep 'suspicious thread link' /var/www/mzcorp/storage/logs/laravel.log | tail -20

# Pending'и за сутки (письма попали как Pending без позиций — РОПу разобрать)
sudo -u www-data php artisan tinker --execute='
$pending = App\Models\Request::where("status", "pending")->where("created_at", ">", now()->subDay())->count();
echo "Pending за сутки: ".$pending.PHP_EOL;
'

# C-step LLM решения (precision vs recall)
sudo grep 'CatalogEmbeddingService' /var/www/mzcorp/storage/logs/laravel.log | grep -c 'LLM rejected match'
sudo grep 'CatalogEmbeddingService' /var/www/mzcorp/storage/logs/laravel.log | grep -c 'LLM failed — match rejected'

# Web-fetch URL'ов (счётчики статусов)
sudo -u www-data php artisan tinker --execute='
print_r(\App\Models\InboundUrlFetch::query()->selectRaw("status, count(*) as c")->groupBy("status")->pluck("c","status")->all());
'
```

### Бэклог низкого приоритета

- **Pending clarifications digest для РОПа** — еженедельная сводка `requests.pending_clarifications`, требующих action.
- **Round-robin догон**: Иванов 165, M2/M3 ~54 (естественно догонится). После Phase 1.9 outbound можно подумать про sticky-роутинг (Foundation Phase 2).
- **Dashboard polish**: heatmap inflow-by-hour, sparklines, funnel — после стабильного боевого потока 2-4 недели.
- **Phase 2.0 KB-сервисы**: `BrandResolutionService`, `EquipmentUnitMatchingService`, `ParameterExtractionService` — частично работают, можно дотюнить когда увидим что фактически нужно.
- **Manual edit позиций в UI**: в Detail табе «Позиции» добавить inline-edit (qty, unit, name) и кнопку «удалить». Сейчас всё disabled.
- **Backfill старого корпуса**: ~$20-40 OpenAI, ~30-60 мин. Оператор отказался — старые заявки уже отработаны или закрыты.
- **OAuth man2/man3 failed_jobs cleanup** — 1000 экземпляров за 2026-05-08. Токены живые, accumulate'ы старые. `\DB::table('failed_jobs')->where('exception','LIKE','%OAuth access-token%')->delete()`.
- **Backfill brand_article_normalized для старых import'ов** — не нужен, миграция уже делает backfill в `up()`.

<!-- legacy планы 2026-05-06/07 (Phase 1.8d/1.8b) — выполнены, удалены 2026-05-07 (вечер 2). -->

### Сессия 2026-05-19 (часть 3) — Recovery нераспределённых заявок

**Контекст**: User обратил внимание, что меню «Нераспределённые» открывает пустой список. Разбирались — оказалось 49 Pending без assigned_user_id, из них 16 С items (включая M-2026-1061 от 18 мая 17:02).

**Диагностика**:
- Все 16 заявок с items созданы в окне **18 мая 14:56–17:02** — ровно в окно деплоя коммитов `de32270` (three-level sticky) → `7076195` (weighted round-robin) → `5ef9c3e` (newbie boost). Дилер-флаг (`42bfacc`) приехал на следующий день, не виноват.
- 33 заявки без items — старше 2ч, в основном мусор (internal myzip.ru-адреса: `noreply@`, `dmitry.rumiantsev@`, `ilya.kurzaev@` ×2, `Ilya.Yurkov@`, `info@`; служебные `info@partleader.ru` ×2, `support@liftway.ru`; адреса из периода до InternalSenderDetector).
- Root cause гипотеза: в окне деплоя AssignmentService persist() сохранил items, но autoAssign() упал тихо (несовместимость кода в worker vs FPM). ParseRequestItemsJob поймал в catch, Request остался Pending forever. Логи 18 мая ротированы — окончательно не доказать.

**Реализовано** (commits `c245dee`, `fe872b7`):
- `ClosedLostReason::ParserNoContent` — новая причина для авто-закрытия пустых
- `RequestStateService::systemCloseLost()` — cron-safe close без author-гейта (writes `request_state_changes.event='system_close_lost'`)
- `RequestsRecoverUnassignedCommand` — hourly cron:
  - items есть → `autoAssign()` + audit `request_state_changes.event='auto_recovery'`
  - items нет AND `created_at < now() - threshold` (default 2ч) → `systemCloseLost(ParserNoContent)`
  - `--dry-run`, `--threshold=N` опции
- `routes/console.php` → `Schedule::command('requests:recover-unassigned')->hourly()`

**Результат прогона 19 мая (16:xx MSK)**: assign ok=15 / close ok=33 / fail=0. Распределение: Морозов 5, Иванов 4, РОП Сидоров 3, Менеджер 2 — 2, Менеджер 3 — 1. `order@liftway.store` (5 заявок) раскидался между 3 разными менеджерами — dealer-flag работает.

**Окно «риска» теперь ≤1 час вместо «навсегда».**

### Сессия 2026-05-19 (часть 4) — Multi-brand / OEM-кросс в каталоге

**Контекст**: M-2026-0983, позиция «Вкладыш кабины Otis F0380CP3/T0380Y3». Менеджер искал M01231 (`brand_article=L-8`, `articles=[L-8,F0380CP3,FO380CP3,FAA380CP3,DAA380E5]`, `brand=Руспромаппаратура`, `brands=[Руспромаппаратура,OTIS,OTIS,OTIS,OTIS]`) и не находил.

**Две системные проблемы**:
1. **Вторичный артикул** — `CatalogSearchService` смотрел `name/brand_article_normalized/sku`, но не `articles_search` (где лежат все OEM). Фикс: `+OR articles_search ILIKE` (commit `7884ce6`).
2. **Вторичный бренд** — нигде в коде не читался jsonb `brands[]`, только скалярный primary `brand` (выбранный `pickPrimaryOem`). Аналоги хранят производителя в `brand=Руспромаппаратура`, а OEM-кроссы — в `brands[]`. chip Brand=Otis отсекал, similarity gate reject'ил, compare-таблица рисовала «другой бренд».

**Реализовано** (commit `6712e61`):
- Migration `2026_05_19_180000_add_brands_search_column_to_catalog_items`: text `brands_search` (UPPER(B1|B2|...)) + PG-триггер refresh при INSERT/UPDATE OF brands + GIN trgm индекс + backfill
- `CatalogSearchService`: +OR `brands_search ILIKE UPPER(query)`
- `ItemCatalogLinkDialog::applyBaseChipFilters`: chip Brand проверяет primary `brand` + jsonb `brands[]`
- `CatalogEmbeddingService` brand-gate: match если subject brand содержится в `brand` ИЛИ любом из `brands[]`
- `CatalogComparisonService::brandCell`: match-status по всем брендам; если не на primary — sub-надпись «OEM-кросс: <бренд>»

**Нормализация ТОЛЬКО UPPER** (без strip-separators как у articles): бренды — слова с пробелами/дефисами («ThyssenKrupp Elevator»), strip сольёт в кашу.

**Масштаб**: 19334 / ~35K позиций (≈55%) — multi-brand. До фикса все они теряли вторичные бренды во всех чтениях.

**Проверка boundary**: `Otis` → 6068 матчей (нормально, нужно сужать чипами / артикулом). `F0380CP3` → точечно находит M01231.

### Сессия 2026-05-19 (часть 5) — Code-token catalog match: вторичные OEM в SIMILAR

**Контекст**: после части 4 фикс «Точно» (TEXT-режим) работает, но менеджер пользуется в основном «Похожее» (SIMILAR). Кейс: `T0380Y3` находит M01231 (артикул есть в `name` каталога), `F0380CP3` — нет (нет в `name`, лежит только в `articles[]`). Подтверждение что в SIMILAR мы искали только по primary артикулу.

**Root cause**: `CatalogEmbeddingService::codeTokenTopN` (быстрый ILIKE-путь в hybrid pipeline до vector) намеренно НЕ дёргал `articles[]` — старый комментарий ссылался на медленный `jsonb_array_elements_text` seq scan (~500мс). Multi-OEM кейсы намеренно сбрасывались на `trigramTopN`, но там digit-гейт ≥6 цифр (F0380CP3 = 5 цифр) отрезал короткие OEM-артикулы Otis-стиля.

**Реализовано** (commit `fd25a42`):
- `codeTokenTopN`: +OR `articles_search ILIKE ANY (upper-tokens)` — теперь все OEM-артикулы, не только primary. GIN trgm индекс (миграция 2026_05_18_160000) делает это быстро.
- `codeTokenTopN`: +OR `brands_search ILIKE ANY (upper-tokens)` — симметрия с TEXT по бренду в SIMILAR.
- `trigramTopN`: digit threshold ≥6 → ≥5 цифр, чтобы Otis-OEM (F0380CP3 5 цифр, T0380Y3 5 цифр) проходили fuzzy fallback.

**Итог по сессии 19 мая (части 3+4+5)**:
- Recovery нераспределённых заявок (hourly cron)
- TEXT-режим caталог-поиска: вторичные OEM и бренды
- SIMILAR-режим: вторичные OEM и бренды через code-token + relaxed trigram threshold
- Multi-brand semantics везде в каталоге (chip, similarity gate, compare)

**Открытые вопросы** для следующих сессий:
- Меню Pool — пройтись по пунктам, отключить ненужные («Жду клиента», «На паузе», «КП отправлено», «Счёт выставлен», «Просрочено по SLA», «Refresh цен ждут», «Сохранённые виды»)
- КП Phase 4 — отправка через ComposeForm с PDF-attachment
- КП Phase 5 — polish (hero chip с КП-кодом, профиль phone/extension)

### Сессия 2026-05-20 — Phase 2 use-case C: полная переработка + bulk resolve

**Контекст**: после части 5 (code-token catalog match) сделали диагностику unmatched 1167 items через новый CLI `catalog:diagnose-c-rejected`. Match rate был 0% — все валились в article/brand/llm safety. Сессия посвящена полному переосмыслению C-step pipeline.

**Реализовано** (commits `91bf9d0` → `63b5f24`):

1. **`LocalSupplierCodePattern`** (`app/Services/Catalog/`) — helper для LW-* (внутренние коды поставщика OTIS-запчастей, не настоящие OEM). Интегрирован в `buildQueryText` (не подмешивать в embed), `isArticleSafe` (не блокировать матч), `matchByArticle` (skip), оба LLM prompt'а (санитизация client article).

2. **Hybrid retrieval в `matchByRequestItem`** — переход с pure top-1 vector на `topNByQueryText(top-10)` (code+trgm+vector с multi-source бонусом). Та же логика, что в UI «Похожие из каталога».

3. **Multi-candidate LLM rerank** (`RerankCatalogMatchPrompt` + `rerankCandidatesWithLlm`):
   - Top-N кандидатов → pre-filter (threshold + brand_safe + article_safe) → если ≥2 safe → LLM выбирает `best_index` или null
   - Single safe → старый binary `validateMatchWithLlm` (skip-if vector ≥ hc_threshold)
   - LLM-validation полностью subsumed под rerank — теперь основной decision-point
   - Новый AppSetting `catalog.name_match.rerank_model` (default mini, можно переключить на gpt-4o)
   - Новый AppSetting `catalog.name_match.rerank_top_n` (default 10)
   - В `quality_assessment_payload.catalog_match`: `name_match_method` (code/trgm/vector/multi) + `name_match_sub_scores` + `llm_validation='approved_rerank'`

4. **`normalizeBrand` → `normalizeBrandTokens` (multi-token)**:
   - Strip org-префиксов (ООО/ОАО/ЗАО/АО/ИП/ПАО/OOO/LLC/LTD/INC/CO/...)
   - Parse «BRAND (ex OLDBRAND)» — оба бренда (AVIRE ↔ MEMCO)
   - Multi-word возвращаем все слова ≥4 чарs кроме generic-суффиксов (ELECTRIC, MOTORS, GROUP, COMPANY, ЭЛЕКТРИК, ЗАВОД, ...). Кейс XIZI OTIS ↔ OTIS, Schneider Electric ↔ Schneider.
   - ASCII Levenshtein fallback (≤1 для 5-7 симв., ≤2 для ≥8) для опечаток вроде Shneider/Schneider. Cyrillic пропускаем — PHP levenshtein byte-level не работает на UTF-8.

5. **`isArticleSafe` 3-уровневый relax**:
   - Prefix-relax (min ≥4, diff ≤5): «LC1D258» ↔ «LC1D258F7C», «БУАД-4-25» ↔ «БУАД-4-25.8»
   - Name-substring fallback с **`cyrillicLookalikeFold` для catalogName** (КРИТИЧНО — без fold «БУAД» с латинской A не находился в name каталога с кириллической «БУАД»)
   - Slash-token: вдобавок к split по `,/`, пробуем полную строку — кейс «E10/18» где / это sub-identifier, не разделитель

6. **Retrieval ranking (4 уровня tie-break)**:
   - usort: `score DESC → vector DESC → trgm DESC → catalog_id ASC` (детерминизм)
   - **Vector backfill**: для items в code/trgm пуле без vec_score догружаем bulk SQL `WHERE id IN (...)`. Кейс #2385 CENTA: M28598 имел vec=0.879 но не попадал в vector top-20 (220VAC — генерик-токен лифт-домена)
   - **`codePoolLimit` 20 → 100**: на генерик-токенах сотни match, M28598 (id=7643) не попадал в первые 20 строк DB index scan
   - **Vector/trigram всегда дёргаются** (раньше скипались если code-pool полный) — vector — главный semantic tiebreaker
   - Helper `embedQueryToVectorLiteral` с статическим кешем — один embed-вызов на queryText

7. **Prompt-улучшения** (`RerankCatalogMatchPrompt` + `ValidateCatalogMatchPrompt` симметрично):
   - «Деталь X для системы Y» ≠ «Система Y целиком, имеющая X внутри» — критично для FP-fix (#2337 Соленоид на ОС vs Ограничитель скорости)
   - Артикул пустой → опираться на name+размеры (LiftMaterial = дистрибьютор, OEM в каталоге — норма)
   - Несколько артикулов через запятую — match по любому совпадающему
   - Минорные суффиксы (-02, R1, M1, F7C) = та же модель
   - Синонимы лифтового домена: Преобразователь ≈ Устройство ≈ Привод ≈ Блок; Контактор ≈ Магнитный пускатель; Кнопка ≈ Клавиша ≈ Нажимной элемент
   - Brand-aliases: AVIRE (ex MEMCO), Schneider/Telemecanique, опечатки Shneider/Schneider, организационные ООО

8. **LW-санитизация для LLM prompt** — если client article — все LW-токены, передаём как null. Раньше LW попадал в prompt и LLM требовал его совпадения с каталожным (LW нет в каталоге by design).

9. **Diagnose CLI** `catalog:diagnose-c-rejected` (`app/Console/Commands/Catalog/CatalogDiagnoseCRejectedCommand.php`) — read-only диагностика unmatched items: hybrid top-N → safety filter → rerank/binary LLM → bucket counts + per-item reasons. Опции: `--limit`, `--top-n`, `--min-sim`, `--source=text|photo`, `--item=ID`, `--no-llm`.

**Прогрессия match rate на выборках 40-item:**

| Версия pipeline | rerank_picked + would_match | match% |
|---|---|---|
| Pure vector + binary (старт) | 0 / 20 | 0% |
| Hybrid + binary | 0 / 20 | 0% |
| Hybrid + rerank | 2 / 20 | 10% |
| + brand multi-token + article prefix | 5 / 40 | 12% |
| + slash-split + name-substring + Cyrillic fold | 11 / 40 | 27% |
| + LW-sanitize + ООО-skip + ex-brand parse | 12 / 40 | 30% |
| + детaль-vs-целое prompt + top-N=10 | 14 / 40 | 35% |
| + vector backfill + tie-break + code-pool=100 | **14 / 40** | **35%** |

**Финальный resolve на проде** (commit `63b5f24`, 27 мин, ~$3-5):
- Проверено: 1256 items
- B (brand_article): 1
- C (name_vector): **230**
- **Итого 231 новых матчей (18%)**

**Известные ограничения** (acceptable trade-off):
- БУАД-кейс: LLM-mini считает «Преобразователь частотный БУАД-4-25» ≠ «Устройство БУАД 4-25.8» (детaль-vs-целое нюанс, prompt не убеждает). Решается переключением `catalog.name_match.rerank_model` на gpt-4o (+5x cost).
- Bernstein разные модели (601 vs 608), KONE кнопки с разными KM-кодами — корректно отклоняются LLM, физически разные товары.
- LW-в-name (без артикула) — клиент пишет «Ролик LW-0007369», LW в name сбивает LLM. Edge case.

**Pipeline state на 2026-05-20:**
```
Retrieval:    code-100 + trgm-20 + vec-20 (всегда все три)
              vector backfill для code/trgm-only кандидатов
              4-уровневый tie-break (score → vec → trgm → catalog_id)
Pre-filter:   threshold + isBrandSafe (multi-token + Levenshtein)
              + isArticleSafe (prefix-relax + name-substring + LW-skip + slash)
Decision:     1 safe → binary LLM (skip if vec ≥ hc 0.90)
              ≥2 safe → multi-candidate LLM rerank (gpt-4o-mini)
              fail-action=reject (consistent с pre-LW политикой)
```

**Файлы добавленные/изменённые:**
- new: `app/Services/Catalog/LocalSupplierCodePattern.php`
- new: `app/Prompts/Catalog/RerankCatalogMatchPrompt.php`
- new: `app/Console/Commands/Catalog/CatalogDiagnoseCRejectedCommand.php`
- modified: `app/Services/Catalog/CatalogEmbeddingService.php` (hybrid retrieval + backfill + brand/article safety + rerank)
- modified: `app/Services/Catalog/CatalogResolutionService.php` (matchByArticle LW-skip + matchByName extended payload)
- modified: `app/Prompts/Catalog/ValidateCatalogMatchPrompt.php` (synonyms, suffixes, articles)

**Открытые вопросы для следующих сессий:**
- A/B тест `rerank_model = gpt-4o` vs mini на subset проблемных кейсов (БУАД, спорные суффиксы)
- Возможно: парсер должен помечать parsed_article=null при detection LW-pattern (сейчас pipeline хорошо обрабатывает, но «грязный» article остаётся в payload)
- Возможно: cross-references LW→GAA/DAA в `articles[]` каталога — точечно для часто встречающихся LW-кодов (нужна работа со стороны каталог-импорта)
- requests:recompute-complexity после bulk-resolve (231 items перешли в NameMatch=3 вместо Manual=8) — снизит complexity_score на ~50 заявок

## Известные грабли (накопленные за Phase 1.4)

- **App passwords в Yandex 360 для бизнеса часто отключены** — путь сразу через OAuth.
- **OAuth-приложение `MyZiP_AI` использует verification_code** (Yandex не разрешил кастомный redirect_uri). Подключение нового ящика — только через `mail:oauth url N` + ручной ввод кода через `mail:oauth code N`.
- **dotenv не любит пробел без кавычек** — `YANDEX_OAUTH_SCOPE` всегда в `"..."`.
- **webklex 6.x особенности:**
  - `whereUid('1:*')` оборачивает значение в кавычки → `UID "1:*"` → IMAP BAD. Серверный UID-фильтр не используем; UID-фильтрация на стороне приложения.
  - Без `whereAll()` пустой SEARCH тоже даёт BAD — обязательный critirion.
  - `setFetchAttachment` не существует в 6.x (только `setFetchBody`, `setFetchFlags`).
  - `getTo()`, `getCc()`, `getFrom()`, `getSubject()`, etc. возвращают `Webklex\PHPIMAP\Attribute`, а **не** Laravel Collection. У него нет `->values()` — используем `->all()`.
  - `getDate()` возвращает Attribute с Carbon внутри (через `->all()[0]`).
- **IMAP в Yandex** должен быть включён в настройках почты + на уровне организации Yandex 360 admin.
- **Yandex MIME-encoded имена файлов** часто разбиваются на 10+ кусков `=?utf-8?Q?...?=` и легко превышают varchar(255). Декодируем `iconv_mime_decode` → `mb_decode_mimeheader` → fallback оригинал, потом `mb_substr(0, 255)`.
- **PostgreSQL не примет 0x00 байты в text-полях** — `cleanString()` чистит.
- **dotenv не любит перенос строки внутри значения** — длинный `OPENAI_API_KEY` через `tee -a <<EOF` ломается, нужно склеить через `sed -i 's|...|...|'`.
- **www-data не должен пересобирать чужие ассеты** — при первом сбое прав на `public/build/assets/` нужно `rm -rf public/build && chown -R www-data:www-data public storage bootstrap/cache`. Иначе `npm run build` падает на EACCES при unlink старого CSS.
- **Forward через корпоративный SMTP может закольцевать** — наш forward в `buh@myzip.ru` каким-то образом возвращался обратно в `mail@myzip.ru`. Защита: заголовок `X-MyLift-Forwarded` + детект префикса `[MyLift forward]` в subject. См. `MailRouter::isLoopMessage()`.
- **Subject и from_name приходят MIME-encoded** (`=?utf-8?B?...?=`) — декодируем через `iconv_mime_decode → mb_decode_mimeheader`. Иначе AI-classifier и rules видят кодированный мусор.
- **AI vs Rule счётчики не равны** — это нормально: AI смотрит subject+body+from, rule только subject. Если хотим симметрию — переключаем правило на `match_mode=ai_classified`.
- **Loop-копии раздувают AI-метрики** — каждая дублирующая копия классифицируется как accounting, накручивая счётчик. После hot-fix дубли больше не создаются, но 8+ существующих в БД остаются (решено не чистить).
- **Webklex 6.x авторизация SMTP** — Symfony Mailer поддерживает XOAUTH2 через `addAuthenticator(new XOAuth2Authenticator())` и `setPassword($accessToken)`. Это уже завязано в `MailForwarder`.
- **После деплоя `app/Services/Request/Assignment*` ОБЯЗАТЕЛЬНО `sudo supervisorctl restart all`.** PHP-FPM подхватывает новый код мгновенно, queue worker крутится со старым байт-кодом. В окне расхождения `RequestItemPersister::persist()` сохраняет items, autoAssign падает на несовместимости — Request остаётся Pending+unassigned forever (см. M-2026-1031..1061 от 18 мая). Recovery-cron теперь подбирает hourly (`requests:recover-unassigned`), но лучше избегать первоначально.
- **Carbon ≥3 `diffInHours()` возвращает signed value** — для прошедшего времени отрицательное. Если нужен «возраст» — `abs(now()->diffInHours($created_at))` или `$created_at->diffInHours()` без аргумента.
