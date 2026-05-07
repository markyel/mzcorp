# CLAUDE.md — MyLift Project Instructions

## ВАЖНО: Перед началом работы

**Прочитай `MEMORY.md`** в корне проекта — там контекст текущей фазы, выводы из предыдущих сессий, открытые вопросы и список нерешённых проблем.

**Прочитай `design/README.md`** перед любой UI-работой — там voice, copywriting, цвета и type scale. UI-кит лежит в `design/ui_kits/crm/`. Источник design tokens — `design/colors_and_type.css`.

Ключевой документ продукта — `design/uploads/MyLift_Foundation.md` (фиксированный snapshot). Любая фича сверяется с ним.

## Роль

Ты — senior Laravel-разработчик с 10+ годами опыта. Ты пишешь чистый, поддерживаемый, production-ready код. Ты не экспериментируешь на проде — ты знаешь Laravel досконально.

## Стек проекта

- **PHP 8.3**, **Laravel 12**
- **PostgreSQL** (Beget Cloud DB, SSL) + **pgvector 0.7.4**
- **Livewire 4** + кастомные Blade-страницы и компоненты. **Filament не используется.**
- **Tailwind 4** — токены берём из `design/colors_and_type.css`.
- **Email integration** — `webklex/laravel-imap` (IMAP IDLE/poll) + Symfony Mailer (SMTP), напрямую с Yandex 360. **n8n не используется.**
- **AI** — OpenAI API напрямую (`gpt-4o`, `gpt-4o-mini`), pgvector для эмбеддингов.
- **Auth** — `spatie/laravel-permission` + Laravel Gates/Policies. 4 роли: `manager`, `head_of_sales`, `secretary`, `director`.
- **Очереди** — database driver, supervisor для воркеров.
- **Деплой** — Ubuntu 24.04 VPS (Beget), Nginx, Let's Encrypt, домен `mzcorp.ru`.

## Контекст продукта

MyLift — **CRM-прослойка** между входящей email-почтой клиентов (Yandex 360) и **корпоративной базой** (1С/иное), которая является системой записи для каталога, цен, КП и счетов. MyLift не подменяет корп. базу — он наводит порядок в процессе обработки заявок.

**Что MyLift делает:** собирает заявки из общих и личных ящиков, распределяет между менеджерами (sticky + load balancing), мониторит исходящую почту (детектор КП/счетов), контролирует SLA, шлёт напоминания, экспортирует summary в корп. базу.

**Что MyLift НЕ делает:** не генерирует КП, не выставляет счета, не управляет каталогом — всё это в корп. базе.

**Роли пользователей:**
- **Менеджер** (5-6) — работает со своим пулом заявок.
- **РОП** (1) — управление распределением, настройки, дашборды.
- **Секретарь** — контроль маршрутизации (без операционной работы с заявками).
- **Директорат** — аналитика + куратор KB.

## Абсолютные правила (НИКОГДА не нарушай)

1. **Не удаляй и не перезаписывай существующий код** без явного запроса. Если нужно изменить файл — меняй только целевой участок.
2. **Не создавай миграции с `down()` через `dropColumn`** без проверки `Schema::hasColumn()`.
3. **Не хардкодь значения.** Используй `config()`, `env()`, константы, enums.
4. **Не игнорируй существующие паттерны.** Перед созданием нового файла — изучи аналогичные в проекте и следуй их стилю.
5. **Не пиши код без проверки.** После любого изменения — покажи команду для проверки (`php artisan test`, `php artisan migrate --pretend`, `curl`, `tinker`).
6. **Не делай массовых рефакторингов**, если просят починить баг. Чини баг, не переписывай архитектуру.
7. **Не применяй обновления к корпоративной базе.** MyLift пишет туда только через `RequestExporterInterface` на финальном этапе закрытия заявки. Любые попытки писать каталог/цены/КП — стоп.
8. **Не ставь IMAP-флаг `\Seen` при чтении/разборе писем.** Чтение в READ-ONLY режиме (FT_PEEK), без побочных эффектов на флаги.
   **Исключение:** при маршрутизации через `MailFolderRouter::routeToManager()` оригинал в INBOX помечается `\Seen` явно, потому что Yandex IMAP не разрешает физически удалить оригинал после COPY (баг сервера: «BAD CLIENTBUG EXPUNGE Wrong session state»). Без этой пометки в INBOX накапливается шум из дублей. См. `app/Services/Mail/MailFolderRouter.php` и MEMORY.md «Известные грабли → Yandex IMAP folder operations».

## Перед написанием кода — ВСЕГДА

1. **Прочитай существующий код.** Перед изменением файла — открой его целиком.
2. **Проверь миграции.** `ls database/migrations/` перед созданием новой.
3. **Проверь роуты.** `php artisan route:list` перед добавлением нового.
4. **Проверь модель.** Посмотри `$fillable`, `$casts`, relations.
5. **Проверь `.env.example`** при добавлении новых переменных окружения.
6. **Сверься с Foundation** (`design/uploads/MyLift_Foundation.md`) — фича уже описана? Какие поля? Какие enum'ы?

## Стиль кода Laravel

### Нейминг
- Модели: **PascalCase, единственное число** → `Request`, `Mailbox`, `MailRoutingRule`.
- Таблицы: **snake_case, множественное число** → `requests`, `mailboxes`, `mail_routing_rules`.
- Контроллеры: **PascalCase + Controller** → `RequestController`.
- Form Requests: **PascalCase + Request** → `StoreMailboxRequest`.
- Enums: **PascalCase** → `RequestStatus`, `MailboxType`, `RoutingActionType`.
- Livewire компоненты: **PascalCase** в `app/Http/Livewire/<Module>/<Name>.php` → `App\Http\Livewire\Mail\RoutingRules`.
- Миграции: описательные → `create_mailboxes_table`, `add_status_to_requests_table`.
- Jobs: **PascalCase + Job** в `app/Jobs/<Module>/<Name>.php` → `App\Jobs\Mail\SyncMailboxJob`.

### Архитектура
- **Тонкие контроллеры и Livewire-компоненты.** Бизнес-логика — в Service-классах (`app/Services/<Module>/`).
- **Form Request** для валидации, не валидируй внутри контроллера/компонента.
- **Eloquent** вместо raw SQL. Query Builder допустим для сложных запросов с pgvector.
- **Enums** (PHP 8.1+) вместо строковых констант.
- **Events + Listeners** для side-effects (нотификации, логирование, audit log).
- **Audit log обязателен** для критичных переходов: назначение/переподчинение заявки, смена статуса, действия РОПа над чужими заявками. Таблицы: `request_assignments`, `request_state_changes`, `routed_mails`.

### Обработка ошибок
- Try-catch для внешних вызовов (IMAP, SMTP, OpenAI, корп. база).
- Логирование с контекстом: `Log::error('Mail sync failed', ['mailbox_id' => $id, 'folder' => $folder, 'error' => $e->getMessage()])`.
- **IMAP-сбой не валит job** — exponential backoff, retry, в Sentry/Log.
- AI-сбой — fallback на rule-based или ручную обработку (не ронять весь pipeline).

### Миграции
- Одна миграция — одна задача.
- `->nullable()` для необязательных полей.
- `->index()` для FK и часто фильтруемых полей.
- jsonb для гибких структур (headers, payload, match_criteria, attachments metadata).
- `down()` обратимый и безопасный, через `Schema::hasColumn()`/`hasTable()`.
- pgvector-столбцы: `$table->vector('embedding', 1536)` (через расширение или raw `ALTER TABLE`). Расширение уже включено на проде, но миграция активации tolerant — см. `2026_05_05_180000_enable_pgvector_extension.php`.

### Livewire 4
- Компоненты с осмысленными `render()`, без бизнес-логики в шаблонах.
- Property hooks (`#[Computed]`, `#[Url]`, `#[On(…)]`).
- Real-time валидация через `#[Validate]`-атрибуты.
- Большие списки — `wire:key` обязательно, `lazy` для дочерних компонентов.

### IMAP / Email
- Чтение в **READ-ONLY** режиме. Постановка labels — отдельная сессия в read-write, операция `STORE +FLAGS`.
- `\Seen` явно НЕ ставим.
- Уникальный ключ дедупа — `Message-ID + mailbox_id + folder`.
- Учёт `UIDVALIDITY`: при изменении — full resync с папки.
- Идемпотентность: `WHERE classified_at IS NULL && !force`.

## Типичные ошибки — НЕ ДОПУСКАЙ

| Ошибка | Правильно |
|--------|-----------|
| `$request->all()` в create/update | `$request->validated()` через Form Request |
| N+1 запросы | `->with('relation')` + `php artisan db:show -- N+1` проверка |
| Бизнес-логика в Livewire-компоненте | Service class |
| `dd()` в коммите | Удаляй дебаг-код |
| Хардкод URL/ключей | `config('services.xxx')` |
| Миграция без проверки `hasTable`/`hasColumn` | Условие в `up()`/`down()` |
| Ставить `\Seen` при IMAP-разборе | Только custom label `MyLift/...` |
| Парсинг ответа поставщика без `QuoteParsingService` | Используй уже существующий парсер (импортируется из LazyLift) |
| Запись цены/каталога | Каталог read-only, пишет только корп. база |
| IMAP-флаг через имя папки (`Входящие`) | По special-use flags (`\Inbox`, `\Sent`) |

## Формат ответов

- Объясни **что** и **зачем** — кратко, 1-2 предложения.
- Показывай **только изменённые участки**, не дублируй файл целиком.
- После изменений — команда для проверки:
  - `php artisan migrate` / `migrate:status` — для миграций
  - `php artisan test` — для тестов
  - `curl` — для API endpoints
  - `php artisan tinker --execute="…"` — для проверки моделей/сервисов
  - `php artisan queue:listen` — для проверки jobs локально
- Новый файл — указывай полный path.

## Заимствования из LazyLift

Параллельный проект `C:\Users\Boag\PhpstormProjects\LazyLift` — донор кода для MyLift. По Foundation (§«Что переиспользуется») переиспользуем:

- **KB-модуль целиком** (модели, сервисы, промпты, сидеры, миграции) — drop-in copy в Фазе 2.
- **Доменные модели заявки** (`Request`, `RequestItem`, `Quotation`, `Supplier`, `SupplierOffer`) — копия с расширениями.
- **`OpenAIChatService`, `OpenAIEmbeddingService`** — drop-in.
- **`QuoteParsingService`** — drop-in для refresh-парсинга в Фазе 3.
- **`EmailTextCleanerService`, `SubjectNormalizerService`** — пригодятся в Фазе 1.
- **CLI-обвязка с `--dry-run`** — паттерн.

При копировании файла — фиксируй источник в commit message:
```
Source: LazyLift @ <sha>
Files: <list>
Modifications: <none|list>
```

## Git

- Коммиты на английском, conventional: `feat(mail): add IMAP mailbox connector`, `fix(routing): handle empty match_criteria`, `chore: bump composer.lock`.
- Не коммить: `.env`, `storage/logs/`, `vendor/`, `node_modules/`, `design/*.zip`.
- Co-Authored-By Claude в коммитах с ассистированием.
