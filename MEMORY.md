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
| 1.3 | Модели email-инфры: `Mailbox`, `EmailMessage`, `EmailAttachment` + миграции | новое | в работе |
| 1.4 | `webklex/laravel-imap`, `MailboxConnector`, первый `SyncMailboxJob` (без AI) | новое | pending |
| 1.5 | `MailRoutingRule` + `RoutedMail` + UI `/dashboard/mail-rules` | новое | pending |
| 1.6 | AI-классификация писем (`gpt-4o-mini`): `request \| reclamation \| accounting \| general \| spam \| other` | `OpenAIChatService` из LazyLift + новый промпт | pending |
| 1.7 | `MailLabelService` (IMAP custom labels) + `MailForwarder` | новое | pending |
| 1.8 | Минимальный `Request` + `RequestItem` (урезанные поля Фазы 1, без KB-расширений) + создание из `IncomingMailProcessor` | из LazyLift, урезаем | pending |
| 1.9 | `OutgoingMailObserver` (Sent-tracking, привязка к `Request` через `In-Reply-To`/`References`) | новое | pending |
| 1.10 | UI «Мои заявки» — минимальный пул менеджера | новое (Livewire 4) | pending |
| 1.11 | Дашборд РОПа v0: health-check ящиков, счётчики писем по типам, метрики AI | новое | pending |

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
- Стартовали Фазу 1 с задачи 1.1 (документация).

## Следующие шаги

1. Закоммитить `CLAUDE.md` + `MEMORY.md` + переписанный `README.md` — задача 1.1 закрыта.
2. Перейти к 1.2: установить `spatie/laravel-permission`, миграции ролей, сидер 4 ролей, базовый login.
