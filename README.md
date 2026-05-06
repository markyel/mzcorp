# MyLift

Внутренняя CRM-прослойка между входящей email-почтой клиентов (Yandex 360) и корпоративной базой (1С) для отдела продаж лифтовых запчастей **MyZip / MyLift** (mylift.ru, myzip.ru).

> Это **не** публичный каталог-сайт — это инструмент, которым отдел продаж пользуется ежедневно для приёмки, распределения и трекинга клиентских заявок на запчасти.

## Что MyLift делает

- Собирает входящие заявки из общих и личных корпоративных ящиков (`sales@…`, `info@…`, личные).
- Распределяет заявки между менеджерами по правилам справедливости и стикинеса (sticky по `catalog_item_id`).
- Помогает менеджеру: показывает каталог (read-only из корп. базы), запрашивает актуализацию цен у поставщиков.
- Мониторит исходящую переписку (в т.ч. отправленную напрямую из Yandex веб-клиента), детектирует ключевые события (КП отправлено, счёт отправлен).
- Контролирует SLA, шлёт напоминания, ведёт follow-up.
- Экспортирует summary заявки в корп. базу при закрытии.

## Что MyLift НЕ делает

- Не генерирует КП (создаются менеджером в корп. базе или внешнем инструменте).
- Не выставляет счета (создаются в корп. базе).
- Не управляет каталогом / ценами / ассортиментом — всё в корп. базе, MyLift только читает.
- Не является «системой правды» по бизнес-документам.

## Стек

| Слой | Технология |
|---|---|
| Backend | Laravel 12, PHP 8.3 |
| БД | PostgreSQL 16 + pgvector 0.7.4 |
| UI | Livewire 4 + кастомные Blade-страницы (без Filament) |
| Tailwind | v4 (токены — `design/colors_and_type.css`) |
| Email | `webklex/laravel-imap` (IMAP) + Symfony Mailer (SMTP) — напрямую с Yandex 360, без n8n |
| AI | OpenAI API (`gpt-4o`, `gpt-4o-mini`), pgvector для эмбеддингов |
| Auth | `spatie/laravel-permission` + Laravel Gates/Policies |
| Очереди | database driver, supervisor для воркеров |
| Деплой | Ubuntu 24.04 VPS (Beget), Nginx, Let's Encrypt |

## Роли пользователей

- **Менеджер** (5-6) — работает со своим пулом заявок.
- **РОП** — управление распределением, настройки, дашборды.
- **Секретарь** — контроль маршрутизации.
- **Директорат** — аналитика + куратор Knowledge Base.

## Структура репозитория

```
app/                    Laravel application code
config/
database/
  migrations/           Schema migrations (pgvector enabled in production)
  seeders/
deploy/
  nginx/mzcorp.conf     Production vhost
design/                 Design system: tokens, voice, HTML mockups
  colors_and_type.css   All design tokens
  ui_kits/crm/          High-fidelity screen mockups (pool, dashboard, ...)
  uploads/MyLift_Foundation.md  Product specification (source of truth)
public/
resources/
routes/
storage/
tests/
CLAUDE.md               AI-assistant instructions
MEMORY.md               Project state between sessions
```

## Документация

- **`CLAUDE.md`** — правила и стиль для AI-агентов (стек, нейминг, архитектура, типичные ошибки).
- **`MEMORY.md`** — текущая фаза разработки, открытые вопросы, журнал сессий, известные грабли.
- **`design/README.md`** — voice, copywriting, visual foundations.
- **`design/uploads/MyLift_Foundation.md`** — полная спецификация продукта (источник истины).

## Окружение

### Локальная разработка

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

### Production

Развёрнут на `https://mzcorp.ru` (VPS Beget). Деплой — `git pull` + `composer install --no-dev` + `migrate` + кеши + `supervisorctl restart`. Подробнее — `MEMORY.md` § «Деплой-процесс».

## Лицензия

Внутренний проект MyLift / MyZip. Не для публичного распространения.
