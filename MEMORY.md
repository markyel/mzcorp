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
sudo -u www-data php artisan config:cache route:cache view:cache
sudo supervisorctl restart mzcorp-worker:*
```

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
6. **Формат IMAP-меток в Yandex 360** — какой именно синтаксис custom keywords корректно отображается в Яндекс веб-клиенте. **Тестируем на боевом ящике в начале Фазы 1**, документируем результат тут.
7. **Полный набор правил маршрутизации** — какие категории помимо очевидных (рекламации, бухгалтерия) команда хочет различать. **Собираем примеры реальных писем в начале Фазы 1.**

## Известные грабли

- **Beget DB case-sensitive** — БД создана как `MyLift` с заглавной. В `.env` `DB_DATABASE=MyLift`. Проверено.
- **pgvector whitelist Beget** — расширение должно быть в whitelist кластера. Уже сделано. Миграция активации tolerant (no-op если расширения нет).
- **`webklex/laravel-imap` — pure PHP**, не требует расширения `imap`. Это плюс на Beget VPS.
- **Yandex папки по-русски** (`Входящие`, `Отправленные`) — мониторим через IMAP special-use flags (`\Inbox`, `\Sent`), **не по именам**.
- **`UIDVALIDITY`** — при смене делаем full resync с папки.
- **Origin SSH-alias на VPS** — на VPS изначально был SSH origin с alias `github.com-mzcorp`. Под `www-data` алиас не резолвится, переключили на HTTPS. Если кто-то снова поставит SSH — напоминать сменить.

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
- Решение: полный redesign, поэтапно. План — в разделе «План на 2026-05-07» ниже.

## План на 2026-05-07

Главная цель сессии — **Phase 1.12 redesign по `design/ui_kits/crm/`**. Объёмная работа на несколько коммитов.

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
