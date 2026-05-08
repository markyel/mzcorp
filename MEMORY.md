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
| 1.9-outbound | `OutgoingMailObserver` (Sent-tracking, наши исходящие → тред в UI) | новое | pending |
| 1.10 | UI `/dashboard/requests` — пул менеджера + карточка заявки + inline attachments | новое (Livewire 4) | done (Phase 1.10 → пересобрано в Phase 1.8d с 7 табами) |
| 1.11 | Дашборд РОПа v0: KPI, AI breakdown, manager load, mailbox health, последние пересылки | новое | done |
| 1.12 | Полный redesign UI по `design/ui_kits/crm/` + `colors_and_type.css` | дизайн-система | done (закрыто как Phase 1.8d) |
| 1.13 | Pool redesign + topbar v2 (`03-requests.html`) + email-style isolation iframe srcdoc + image preview/lightbox + RFC 6266 attachment download + UI управления менеджерами (CRUD + soft-archive + OAuth verification_code attach) + manual reassign в карточке заявки | новое | done (`f2a778e`..`a06fccd`) |

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

## План на следующую сессию

После сессии 2026-05-08 закрыта Phase 1.13 (UI менеджеров + OAuth-привязка ящиков + manual reassign + email-style isolation + attachments UX). Все три ящика на проде с живыми OAuth-токенами, новые менеджеры заводятся через браузер.

### Приоритет 1 — Phase 1.9 outbound (Sent-tracking)

`OutgoingMailObserver` — синк папки Sent у каждого ящика, привязка наших исходящих к существующим Request через `In-Reply-To` / `References` / subject `M-2026-NNNN`. Поля у `EmailMessage` уже есть (`direction = outbound`, `mailbox_id`, headers). Логика похожа на inbound:
1. Sync Sent папку (тот же `SyncMailboxFolderJob` с folder='Sent', работает).
2. Для каждого нового outbound message — найти Request через те же 5 уровней что в `InboundReplyLinker` (можно переиспользовать класс, добавив `tryLinkOutbound`).
3. Если matched — поставить `related_request_id`, обновить `Request.last_outbound_at`. Это даст:
   - Тред в UI «Переписка» Phase 1.8d покажет outbound сообщения вместе с inbound.
   - Дашборд РОПа сможет считать KPI «time-to-quote» (от inbound до первого outbound).
4. Compose-кнопка в UI — Phase 1.9 next iteration. Сначала observer, потом editor.

### Приоритет 2 — мониторинг новых писем

После всех изменений Phase 1.8e + 1.9-inbound нужно следить за качеством на boevом потоке:

```bash
# raw логи suspicious thread links (потенциально неверный AI clarifier choice)
sudo grep 'suspicious thread link' /var/www/mzcorp/storage/logs/laravel.log | tail -20

# parser empty items за сутки (письма попали как Pending без позиций — РОПу разобрать)
sudo -u www-data php artisan tinker --execute='
$pending = App\Models\Request::where("status", "pending")->where("created_at", ">", now()->subDay())->count();
echo "Pending за сутки: ".$pending.PHP_EOL;
'

# AI clarifier reasoning — для оценки точности 5-го уровня
sudo grep 'ThreadClarificationAi: matched by AI' /var/www/mzcorp/storage/logs/laravel.log | tail -20
```

Если pending'и накапливаются — добавить UI-кнопку «Закрыть как не наша тема» для РОПа (Phase 2 действие, сейчас disabled).

### Бэклог низкого приоритета

- ~~**man2/man3 OAuth re-issue**~~: **закрыто 2026-05-08.** Ящики переподключены через новый UI `/dashboard/managers/{id}/edit` (Phase 1.13). `production.ERROR` про истёкший access_token прекратились.
- **Round-robin догон**: Иванов 165, M2/M3 ~54 (сейчас неравный, естественно догонится). После Phase 1.9 outbound можно подумать про sticky-роутинг (Foundation Phase 2).
- **Dashboard polish**: heatmap inflow-by-hour, sparklines, funnel — после стабильного boevoго потока 2-4 недели.
- **Phase 2.0 KB-сервисы**: `BrandResolutionService`, `EquipmentUnitMatchingService`, `ParameterExtractionService` из LazyLift `app/Services/Kb/` — Foundation Phase 2.
- **Manual edit позиций в UI**: в Detail табе «Позиции» добавить inline-edit (qty, unit, name) и кнопку «удалить». Сейчас всё disabled.
- **Backfill старого корпуса**: ~$20-40 OpenAI, ~30-60 мин. Оператор отказался — старые заявки уже отработаны или закрыты.

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
