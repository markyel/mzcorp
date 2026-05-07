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
| 1.9 | `OutgoingMailObserver` (Sent-tracking, привязка к `Request` через `In-Reply-To`/`References`) | новое | pending |
| 1.10 | UI `/dashboard/requests` — пул менеджера + карточка заявки + inline attachments | новое (Livewire 4) | done |
| 1.11 | Дашборд РОПа v0: KPI, AI breakdown, manager load, mailbox health, последние пересылки | новое | done |
| 1.12 | Полный redesign UI по `design/ui_kits/crm/` + `colors_and_type.css` | дизайн-система | в работе (фундамент) |

KB, sticky-роутинг, каталог, refresh-цены, DocumentDetector, экспорт в 1С, паузы/state machine — **все за пределами Фазы 1.**

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

### Сессия 2026-05-07 (вечер) — Phase 1.8c (LazyLift email classifier port) + UI mismatch raised

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

## План на следующую сессию

Категоризатор работает в фоне на новых письмах (после `85d1c70`). Перед стартом — посмотреть качество категоризации за прошедшие сутки через `mail:export-for-analysis` или прямой SQL по `email_messages`.

### Вариант A — приоритет (Phase 1.8d). UI refactor под design templates

Оператор явно: «Заявка = список позиций + переписка с заказчиком, не одно письмо. Привести в соответствие с шаблонами».

Шаблоны в `design/ui_kits/crm/`:
- **`04-request-detail.html`** — детальная заявка. Структура:
  - Subnav: «← К списку», breadcrumbs, prev/next по соседним заявкам
  - Hero block: id (M-2026-NNNN) + копировать, title с числом позиций, client (компания), manager, status row (Статус/SLA/Менеджер/Sticky/Возраст/Сумма/Сматчено)
  - Action buttons: «Сформировать КП», «Refresh цен», «Ответить», «Пауза», «Переподчинить», «Закрыть как не наша тема»
  - **Tabs: `Обзор / Переписка (N) / Позиции (N) / Поставщики (N) / Активность (N) / Файлы (N) / Связанные`**
- **`01-pool.html`** — пул менеджера: фильтры, компактный список.
- **`03-requests.html`** — широкий список РОПа.
- **`02-dashboard.html`** — KPI + графики.

**Подзадачи Phase 1.8d:**
1. Tailwind tokens (старый Phase 1.12 Шаг 1: `design-tokens.css` + extend в `tailwind.config.js`).
2. Layout + navigation (Шаг 2 старого плана).
3. `/dashboard/requests/{request}` — Livewire `RequestDetail` с табами:
   - **Обзор** — сводка
   - **Переписка** — chronological список писем (incoming + outgoing) thread'а заявки. Здесь живёт «оригинальное письмо» — но как ОДИН элемент в треде, не главный контент.
   - **Позиции** — список `RequestItem` с действиями (добавить вручную, удалить, edit).
   - **Поставщики** — placeholder Phase 2 (refresh prices), но структуру создать.
   - **Активность** — audit log (`request_assignments`, `request_state_changes`, `routed_mails`).
   - **Файлы** — все вложения из всех писем заявки.
   - **Связанные** — placeholder.
4. `/dashboard/requests` (pool) — список с правильными колонками (`internal_code`, client, items count, status, manager, age).
5. `/dashboard` — KPI tiles по `02-dashboard.html`.

### Вариант B (после A или параллельно). Переключить Request-creation на category=client_request

1. `IncomingMailProcessor.processIfRequest` — гейт `category === client_request AND confidence >= 0.7` (вместо `ai_classification === request`).
2. После категоризации в `MailRouter` — если `client_request`, dispatch `ParseRequestItemsJob` (background) для парсинга позиций.
3. `ParseRequestItemsJob`: вызывает `RequestItemParsingService` + `RequestItemPersister`. Если items > 0 → создаётся Request с позициями.
4. Cleanup CLI `requests:reject` + `requests:cleanup-by-category` для очистки 227 ложно-позитивных Request из Phase 1.8b.

### Вариант C. `\Seen` riddle — расследование

1. Контролируемый тест: отправить «чистое» письмо, проверить UNSEEN сразу.
2. Запустить `mail:sync` — проверить UNSEEN.
3. Если \Seen появляется после sync → виноват `setFetchBody(true)` без `BODY.PEEK[]`. Фикс: либо тянуть body отдельным raw call, либо после fetch явно `removeFlag('Seen')`.
4. Альтернатива: принять `\Seen = «обработано MyLift»` как норму.

### Вариант D. Phase 1.9 — Sent-tracking (`OutgoingMailObserver`)

Привязка исходящих к Request через `In-Reply-To`/`References`. Нужен для табов «Переписка»/«Активность» Phase 1.8d.

---

## ⚠️ Старый план на 2026-05-07 (был — отложен)

Главная цель планировалась — **Phase 1.12 redesign по `design/ui_kits/crm/`**. Перенесено: вместо этого сделали Phase 1.8b. План ниже сохранён для следующего раза.

### Шаг 1. Фундамент — токены + Tailwind config
1. Создать `resources/css/design-tokens.css` — копия CSS-vars из `design/colors_and_type.css` без `@import url(font)` (шрифт грузим через `<link>` в layout).
2. В `resources/css/app.css`:
   ```css
   @import './design-tokens.css';
   @tailwind base;
   @tailwind components;
   @tailwind utilities;
   @layer base {
     html { font-family: var(--font-sans); font-size: var(--fs-base); color: var(--fg-1); }
     body { background: var(--bg-app); }
   }
   ```
3. `tailwind.config.js` — extend.colors / fontSize / fontFamily / borderRadius / boxShadow на `var(--token)` mapping. Сохранить дефолтную spacing scale (4-based уже подходит).
4. `npm run build` локально → push → VPS pull + npm ci + build.

### Шаг 2. Layout + navigation
1. В `layouts/app.blade.php` добавить `<link rel="preconnect" href="https://fonts.googleapis.com">` + `<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">`.
2. Положить `design/assets/logos/mylift-wordmark.svg` → `public/images/mylift-wordmark.svg`.
3. Переписать `layouts/navigation.blade.php`:
   - Top-bar 48px высота, фон `--bg-surface`, разделитель `--border`.
   - Слева: MyZip wordmark.
   - Центр: top-nav links (Дашборд / Заявки / Правила почты) — text-fg-2, активный = text-fg-1 + 2px-bottom-border accent.
   - Справа: workspace pill (`mail@myzip.ru · 3 ящика`), avatar dropdown.
4. Можно опционально добавить left rail 56px с иконками (или скипнуть на этом этапе — single-column main).
5. main: `max-width: 1440px; margin: 0 auto; padding: 20px 24px`.

### Шаг 3. Дашборд по 02-dashboard.html
1. KPI-tiles 5-в-ряд (или адаптивно 6→3→2): `.k` (uppercase 10.5px), `.v` (28px semibold tnum), `.d` (тренд если есть данные за прошлый период).
2. AI-классификация: card с `<h3>` хедером и `.body`, бары на `--sky-500` background.
3. Mailbox health с цветным dot (emerald/amber/grey).
4. «Последние пересылки» / «последние заявки» — компактный log-стиль (12.5px, monospace timestamps).
5. **Не делаем** на этом этапе: heatmap (требует agg по часам), sparklines (history), funnel (нужно много вычислений) — добавим в Phase 2.

### Шаг 4. /dashboard/requests
1. Pool по 03-requests.html / 01-pool.html: компактная таблица, 36px row-h, status-chips (lowercase «в работе», «новая»), monospace для internal_code.
2. Detail по 04-request-detail.html: трёхколоночная вёрстка (исходное письмо | client+manager | thread/timeline). Inline body отображается с design-system styling.

### Шаг 5. /dashboard/mail-rules
1. Список правил по табличному стилю.
2. Editor — вертикальные карточки секций с design tokens.

### Шаг 6 (опционально, если время останется)
1. Phase 1.9 — `OutgoingMailObserver`: при синке Sent папки matchить письма к Request через `In-Reply-To`/`References` и обновлять `last_outbound_at` на Request.
2. Подключить Man2/Man3 ящики через OAuth (mail:oauth url 2 → mail:oauth code 2; то же для 3).

## ⭐ ПРИОРИТЕТ 1 на 2026-05-07: пересмотр анализа почты (Phase 1.8b)

**Решение клиента (2026-05-06 поздно вечером):** текущий pipeline (rules → AI-классификатор по 6 классам → Request если "request") даёт слишком много мусора в пуле. Перейти на подход LazyLift — **анализируем по содержимому, а не по тональности письма**.

**Новый порядок pipeline:**
```
письмо
  → ГЛУБОКИЙ АНАЛИЗ: заявка ли это?
       • парсинг тела (извлечение позиций — партномера, бренды, кол-во)
       • извлечение текста из вложений (PDF/XLSX/JPG/PNG/WEBP/архивы)
       • Vision/OCR на шильдиках, сканах
       • если есть items → это заявка
       • если items пустые → не заявка
  → если ЗАЯВКА: создаём Request + RequestItems (с распарсенными позициями)
  → если НЕ ЗАЯВКА: routing rules (рекламация/бухгалтерия/спам/forward)
```

**Что меняется в текущем коде:**
- **Удалить (или сильно урезать)** `MailClassifierService` как primary-классификатор. AI-класс остаётся справочной меткой, но **не** определяет создание Request.
- **`IncomingMailProcessor`** перестаёт триггериться по `ai_classification=request`. Триггер — наличие распарсенных items.
- **`MailRouter`** теперь:
  1. Loop guard.
  2. **Сначала** прогнать письмо через RequestExtractionPipeline (новое).
  3. Если items.count > 0 → IncomingMailProcessor создаёт Request с items.
  4. Если items.count = 0 → routing rules + (опционально) AI-классификация для меток.

**Что переносим из LazyLift (drop-in или с адаптацией):**

| Компонент LazyLift | Назначение в MyLift |
|---|---|
| `app/Services/RequestItemParsingService.php` | Главный парсер — берёт тело письма + вложения, возвращает items |
| `app/Services/ArchiveExtractionService.php` | Распаковка ZIP/RAR во вложениях |
| `app/Services/EmailTextCleanerService.php` | Чистка цитирования / подписей / служебки в теле |
| `app/Services/SubjectNormalizerService.php` | Нормализация `Re:`/`Fwd:` для дедупа thread'ов |
| `app/Services/Kb/*` (минимум: `RequestContextAnalysisService`, `BrandResolutionService`, `EquipmentUnitMatchingService`, `ParameterExtractionService`) | L1/L2 квалификация — определяет, что распарсенный набор позиций «достаточен», иначе items нет / неполные |
| `app/Prompts/Kb/RequestContextAnalysisPrompt.php` | Промпт для L1 KB |
| `app/Models/Kb/*` (`EquipmentCategory`, `ManufacturerBrand`, `IdentificationParameter`, `ParameterExtractor`, и т.д.) | KB-модели + миграции |
| `database/seeders/Kb/KbInitialSeeder.php` + `data/` | Стартовая база (27 брендов, 15 категорий, 52 параметра) |
| Vision-процессоры (если есть в LazyLift) | Распознавание шильдиков на фото |

Это уже **не «фильтрация Request» (Phase 1.8a)**, а **полноценный Phase 2.0 KB-парсинг**, перенесённый в Phase 1 как фундамент. Foundation предполагал KB на Phase 2, но без него фильтр мусора нерабочий — поэтому подтягиваем сюда.

**Прагматичный план на следующую сессию:**

1. **Изучить LazyLift `RequestItemParsingService` + зависимости** — карта классов, минимальный набор для drop-in.
2. **Скопировать KB-модели + миграции** (модели, ParameterExtractor, IdentificationRule, ManufacturerBrand, EquipmentCategory, jsonb-поля для items).
3. **Скопировать `app/Services/Kb/`** — критическую часть (RequestContextAnalysis, BrandResolution, ParameterExtraction).
4. **Скопировать промпты** `app/Prompts/Kb/*`.
5. **Скопировать `KbInitialSeeder` + data/** — выполнить seed.
6. **Скопировать `RequestItemParsingService`** + цепочку зависимостей.
7. **Адаптировать `IncomingMailProcessor`** под новую логику: вызвать parsing → если items есть → Request + RequestItems с jsonb-полями + L1/L2 KB → AssignRequestJob.
8. **Изменить `MailRouter`** — порядок: parsing first, classifier как метка (не trigger).
9. **CLI `requests:reanalyze --all`** — запустить новый pipeline на 245 имеющихся писем, очистить пул.

**Ожидаемый результат:**
- Пул менеджера содержит только письма с реально распарсенными позициями.
- Внутренние, авто-уведомления, рекламации, спам — отрезаются на этапе «items пустые».
- Re:/Fwd на старые заявки → thread item на существующий Request.
- AI остаётся вспомогательной меткой для UI, не основным критерием.

**Связанные мелочи (после миграции):**
- `requests:cleanup` для очистки 165 текущих Request, не прошедших новый фильтр.
- Phase 1.12 redesign UI — отложить до тех пор, пока pipeline не стабилизируется (нет смысла полировать UI на грязных данных).
- Phase 1.9 Sent-tracking — после стабилизации.

Это **большой объём** — несколько коммитов, может растянуться на 2 сессии. Но это правильный путь: без качественного парсинга MVP бесполезен для продакшна.

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
