# MEMORY.md — Текущий контекст MyLift

> Этот файл — рабочая память между сессиями. Перед началом работы — прочитай его целиком. После значимых изменений — обнови.

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

KB drop-in, sticky-snapshot, catalog (A+B+C), settings UI — **закрыты в Фазе 2 (2026-05-08, 2026-05-12).**
Phase 1.9 UI-переписка, Priority 1 ручное управление позициями, Phase 1.10 state-machine — **закрыты 2026-05-14, 2026-05-15.**
Phase 1.11 Attention-механизм — **закрыт 2026-05-16.**
Phase 4.0 DocumentDetector — **закрыт 2026-05-17.**
Phase 5.2 Reanimate closed — **закрыт 2026-05-18.**
Foundation Фаза 2 хвост (unavailable / mail-review / notifications) + delegation rework + планируемая недоступность — **закрыт 2026-05-19.**
Экспорт в 1С, KB curator UI, PriceRefreshService — **за пределами текущей фазы.**

## Что готово (инфраструктура — Фаза 0)

- Laravel 12 skeleton развёрнут в `/var/www/mzcorp` на VPS Beget (`84.54.31.54`, домен `mzcorp.ru`).
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

## Известные грабли

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

## Журнал сессий

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

## План на следующую сессию

Phase 1.9 (UI-переписка), Priority 1 (ручное управление позициями), Phase 1.10 (state-machine), Phase 1.11 (Attention-механизм) — закрыты. На очереди — auto-переходы и реанимация.

### ~~Приоритет 1 — Attention-механизм~~ ✅ закрыт 2026-05-16

Реализовано в Phase 1.11. См. таблицу декомпозиции выше.

### ~~Приоритет 2 — DocumentDetector~~ ✅ закрыт 2026-05-17

Реализован в Phase 4.0 (четыре коммита `b876244..5ebe671`). См. таблицу декомпозиции выше.

### ~~Приоритет 3 — Reanimate closed~~ ✅ закрыт 2026-05-18

Реализован в Phase 5.2 (`6e51dc4`). См. таблицу декомпозиции выше.

### Приоритет 4 — Регулярный sync MDB → прод

Старый план (Python-скрипт готов, на офисной машине поставить Task Scheduler).

### Приоритет 5 — Дашборд РОПа v1

- Sticky group-headers в Pool уже есть. Добавить heatmap inflow-by-hour, sparklines per менеджер, funnel (received → quoted → closed_won).
- В дашборде — counter open/quoted/closed_won/closed_lost за период, conversion rate.

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
