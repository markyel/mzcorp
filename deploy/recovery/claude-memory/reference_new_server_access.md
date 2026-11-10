---
name: new-server-access
description: "Как ассистент заходит на новый прод Reg.ru (ssh mzcorp по ключу), выкладка mzCorp и QuoteParts после аварии — заменяет prod-deploy"
metadata:
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-11-07T13:59:31.980Z
---

Новый прод (Reg.ru RD-59257, с 05–06.11.2026 рабочий): `ssh mzcorp` — алиас в `~/.ssh/config` этого компьютера (`HostName <IP>`, ключ `~/.ssh/id_ed25519_mzcorp_regru`, подпись `claude-mzcorp-deploy@boag7-pc`, добавлен в `/root/.ssh/authorized_keys` 07.11). Вход по паролю остаётся, см. [[ssh-password-stays]]. На другом компьютере — свой ключ так же (порядок в `deploy/recovery/HANDOFF.md`, «Сервер»).

- **mzCorp** `/var/www/mzcorp`, всё от `www-data`: `git pull --ff-only` → при миграциях `php artisan migrate --force` → `route:cache` (маршруты кэшированы) → `view:clear` → `queue:restart`. Конфиг НЕ кэширован (`.env` читается сразу).
- **QuoteParts** `/var/www/quoteparts`, всё от `quoteparts`: `git pull`, `composer install --no-dev -o`, `migrate --force`, `npm ci && npm run build`, `config:cache`, `route:cache`, `view:cache`, `supervisorctl restart 'quoteparts-worker:*'`. Конфиг кэширован. Коммиты там — `git -c user.name=markyel -c user.email=<адрес> commit`. Полный набор тестов QuoteParts (SQLite in-memory, безопасно) — `php -d memory_limit=2G vendor/bin/phpunit` (на 128M падает по памяти).
- Выкладка на прод — только с явного «да» пользователя на каждую правку: классификатор авто-режима блокирует выкладку, сертификаты и DNS без такого подтверждения.
- В `python3` на этом Windows-компьютере — заглушка Microsoft Store: для правок файлов — Edit, не python.

**Why:** старая заметка [[prod-deploy]] — про сервер Beget до сбоя. **How to apply:** перед работой `git pull` в обоих проектах (работа идёт с двух компьютеров; 07.11 локальная копия отставала на 62 коммита).

**Почта QuoteParts (10.11.2026):** `MAIL_MAILER` в `.env` QuoteParts после восстановления был `log` — коды входа и приглашения поставщикам не уходили (YuanQi «не приходит код»). Переключён на `smtp` (Beget, `<адрес>`, вход проверен пробным `EsmtpTransport::start()` без отправки). `deploy/ops/mode.sh` этот ключ не трогает. У mzCorp `MAIL_MAILER=log` по-прежнему намеренно — служебное там только через `SystemNotificationMailer` ([[staff-access-link]]).
