# План восстановления mzCorp и QuoteParts после потери баз Beget (ноябрь 2026)

Составлен 03.11.2026. Начинать следующую сессию отсюда. По ходу работ отмечать сделанное прямо в этом файле: `[x]`, дата, коммит.

## Состояние на 05.11.2026 09:30 (сверено на сервере)

- Новый прод — Reg.ru `89.108.90.105` (`ssh mzcorp`; старый Beget — `mzcorp-beget`): 8 ядер, 16 ГБ, 201 ГБ, PostgreSQL 16.15 + vector 0.8.7 + pg_trgm, nginx, PHP 8.3, сертификат; `mzcorp.ru` смотрит сюда.
- Приложение на базе **`MyLift_retro`** (1,3 ГБ), собранной ретрозагрузкой из почты: ~119 тыс. писем, 17,8 тыс. заявок (04.05–02.11) с назначениями и историей статусов, ~18 тыс. КП, 5,5 тыс. счетов, 31 тыс. позиций каталога, 10 ящиков, 10 пользователей. Базы `MyLift` (19 МБ) и `quoteparts` (пустая) — не используются.
- Код ретрозагрузки (`retro:scan|build|hydrate|attachments|totals`, `app/Services/Retro`, `config/retro.php`) жил только на сервере — внесён в git `cac67ec7`, сервер переставлен на этот коммит. **`deploy/recovery/RETRO_PLAN.md`, на который ссылается `config/retro.php`, в репозитории нет** — добавить с того компьютера, где он есть.
- Первая копия базы: `/root/backup-2026-11-05-0921` (182 МБ, `pg_dump -Fd`) и на рабочем компьютере `C:\Users\Boag\mzcorp-backups\backup-2026-11-05-0921` (суммы сверены). Копий по расписанию ещё нет.
- **Не сделано:** обработчики очередей (supervisor пуст), `schedule:run` в cron, отправка почты (`MAIL_MAILER=log`), копии по расписанию (§9), QuoteParts (§7).

## 0. Что случилось и что уцелело

**Хронология.** 02.11.2026 у Beget пропала связь со всем сразу: VPS 84.54.31.54, облачные базы и DNS (домены `mzcorp.ru`, `*.beget.app` не резолвились). 03.11 заказчик сообщил, что **обе управляемые базы потеряны безвозвратно**:
- mzCorp: `nakoocirope.beget.app` / `MyLift`, PostgreSQL + pgvector 0.7.4;
- QuoteParts: `ceetothebdrelhug.beget.app`, PostgreSQL 16.

Собственных копий баз не было ни на сервере, ни на рабочем компьютере. Скрипты копии написали за день до этого, но запустить их не успели.

**Неизвестно, выяснить первым делом:**
- жив ли сам VPS 84.54.31.54 (на нём `storage/app` обоих приложений, `.env` с ключами, конфиги nginx/supervisor, сертификаты, `/root/qp-db-pass`, deploy-ключ GitHub у пользователя `quoteparts`);
- жива ли почта платформы на Beget (`myzip@quoteparts.net`, `support@quoteparts.net`);
- есть ли у Beget хоть какие-то копии (снимки баз, WAL, диск VPS). Запросить письменно.

| Что | Где сейчас | Восстановление |
|---|---|---|
| Код mzCorp | GitHub `markyel/mzcorp` (публичный) + рабочий компьютер | `git clone` |
| Код QuoteParts | GitHub `markyel/quoteparts` (приватный) | `git clone` по новому deploy-ключу |
| Структура обеих баз | миграции: 293 mzCorp, 49 QuoteParts | `php artisan migrate` |
| Роли, шаблоны уведомлений, база знаний | сидеры (`RolesAndPermissionsSeeder`, `ClientNotificationTemplatesSeeder`, `Kb/*`, `ChangelogSeeder`) | `db:seed --class=…` |
| Вся почта | Яндекс 360: info@, личные ящики, папки `MZ\|<менеджер>`, «Отправленные», orders@ | синхронизация ящиков (см. §5) |
| Каталог и цены | 1С (`CATALOG_SYNC_URL`) | `catalog:sync-from-url`, `catalog:embed` |
| Внешние сервисы | Директ, Метрика, Calltouch, каналы TG/MAX/ВК/Дзен, IQOT, Liftway | перезапросить по API |
| Описание решений и настроек | `MEMORY.md`, `CLAUDE.md`, этот план | вручную |

**Потеряно безвозвратно:** вся ручная работа в CRM. Это статусы и история заявок, назначения и делегирования, комментарии, правки позиций, КП-черновики, счета в mzCorp, справочник поставщиков mzCorp (флаги «внесистемный», агенты, группы), реестр клиентов и привязки, переписка ботов, журнал `mail_decisions`, медиаплан и публикации, аутсорс-задачи, пресс-релизы, Директ-фразы и их разборы. В QuoteParts — кабинеты поставщиков, их цены, запросы, переписка, прайсы.

## 1. Решения, которые нужны от заказчика

1. [x] Где поднимаем (заказан 04.11.2026, ждём выдачи): **выделенный сервер RD-59257, Москва, Волгоградский пр-т, Ubuntu Server 24.04: Xeon E3-1270v3 3,5 ГГц, 4 ядра / 8 потоков, 16 ГБ DDR3, 2 × 240 ГБ SSD** (5 810 ₽/мес с НДС по скидке 30 %, без скидки 8 300 ₽; цена при продлении не подтверждена; 1 IPv4 и безлимитный трафик 100 Мбит/с входят). Виртуальные серверы Reg.ru после аварии Beget раскуплены. Обе базы — на этом же сервере (свой PostgreSQL 16 + pgvector), не управляемые. Размер памяти — оценка без замеров, старый сервер недоступен. После выдачи проверить: конфигурацию, RAID1, SMART дисков, журнал ошибок железа. Снимков от провайдера нет, ресурсы кликом не расширить: копии вне этого сервера обязательны с первого дня (§9). Копирование 240 ГБ по каналу 100 Мбит/с занимает около 6 часов.
2. Куда переносим DNS `mzcorp.ru`, `quoteparts.ru`, `quoteparts.net` (у Beget нельзя: вместе с ним лёг и DNS). Варианты: DNS Reg.ru, Яндекс Cloud DNS. У какого регистратора сами домены?
3. Глубина восстановления заявок из почты: только новые с момента запуска, или пересоздать открытые за последние N недель (рекомендую 4 недели, см. §6).
4. Переходим ли с VPS Beget совсем (рекомендую да) или Beget остаётся зеркалом.
5. Кто из сотрудников какой роли (`App\Enums\Role`: `manager`, `head_of_sales`, `secretary`, `director`, `procurement` — снабжение, `admin`, `claims_manager` — рекламации) — список ниже, роли подтвердить.

## 2. Этап 0 — сегодня, до сервера

- [ ] Письмо в Beget: любые копии обеих баз и диска VPS на любую дату; статус VPS 84.54.31.54 и почты quoteparts.net.
- [ ] Если VPS жив — **сразу** снять копию файлов и `.env`: `deploy/backup/full-backup.sh` (шаги с базами упадут, файлы и конфиги снимутся), затем `deploy/backup/pull-backup.sh` на компьютер.
- [x] Сервер заказан и выдан 04.11.2026 (решение 1), IP в панели Reg.ru. Железо совпало с заказом; RAID1 есть; SMART обоих SSD без ошибок (наработка 46 361 и 57 955 ч, индикатор износа 98 и 91 из 100), короткий самотест пройден. Тест памяти прошёл только частично, ошибок не показал.
- [ ] Перенести DNS доменов к независимому провайдеру, TTL 300.
- [ ] Собрать ключи, которые придётся получить заново (если `.env` с VPS не спасём):

| Сервис | Переменные / где | Где взять |
|---|---|---|
| OpenAI | `OPENAI_API_KEY`, `OPENAI_FALLBACK_API_KEY`, `OPENAI_PROXY_KEY`, `OPENAI_BASE_URL` | кабинет OpenAI / прокси |
| Вход через Яндекс (ящики) | `YANDEX_OAUTH_CLIENT_ID/SECRET/REDIRECT_URI` | oauth.yandex.ru, приложение mzCorp; redirect — новый домен/тот же |
| Директ | `YANDEX_DIRECT_CLIENT_ID/SECRET` есть в локальном `.env`; `YANDEX_DIRECT_TOKEN/REFRESH_TOKEN` — заново | oauth.yandex.ru |
| Метрика | `YANDEX_METRIKA_TOKEN`, `YANDEX_METRIKA_COUNTERS` | oauth.yandex.ru |
| DaData (реестр клиентов) | `DADATA_API_KEY` | dadata.ru |
| Clipping Magic (раздел «Фото») | `CLIPPINGMAGIC_API_ID/SECRET` | clippingmagic.com |
| IQOT | ключ в настройках (`app_setting('iqot.api_key')`), `IQOT_*` | IQOT |
| Каталог 1С | `CATALOG_SYNC_URL`, `CATALOG_IMPORT_TOKEN` | 1С-интегратор |
| Боты Telegram / MAX | в базе: «Настройки → Боты» (`BotSettings`, зашифровано) | @BotFather, кабинет MAX (сертификат Минцифры, см. MEMORY 09.10) |
| Каналы медиаплана (VK, Telegram, MAX, Дзен) | в базе: `media_channels.encrypted_secrets` | VK ID 54820364 и ключ сообщества, боты каналов |
| Маркетинговые сервисы | `marketing_services.encrypted_secrets` | — |
| QuoteParts ↔ mzCorp | `QUOTEPARTS_URL`, `QUOTEPARTS_API_TOKEN`, `QUOTEPARTS_WEBHOOK_SECRET` | выпустить заново (§7) |
| Почта отправки | `MAIL_*` — есть в локальном `.env`; `MAIL_OUTBOUND_FALLBACK_DSN` | Яндекс 360 |
| Пароли ящиков / токены входа Яндекса | в базе: `mailboxes.encrypted_credentials` | войти заново в «Почта → ящики» |

`APP_KEY` mzCorp: если `.env` VPS не спасён — новый (`key:generate`); старых зашифрованных данных всё равно нет.

## 3. Этап 1 — сервер Reg.ru

Один сервер для обоих приложений и обеих баз. Всё ставить и запускать от правильных пользователей: mzCorp — `www-data`, QuoteParts — `quoteparts`. **Никогда не запускать artisan/tinker/git от root** (инцидент 03.10, см. MEMORY «Прод: tinker и pull только под www-data»).

```bash
apt update && apt -y upgrade
timedatectl set-timezone Europe/Moscow
fallocate -l 4G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile && echo '/swapfile none swap sw 0 0' >> /etc/fstab && sysctl vm.swappiness=10
apt -y install nginx supervisor certbot python3-certbot-nginx git unzip ufw \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl \
  php8.3-bcmath php8.3-imap php8.3-redis php8.3-imagick antiword catdoc poppler-utils
# PostgreSQL 16 + pgvector из репозитория PGDG
install -d /usr/share/postgresql-common/pgdg && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt noble-pgdg main" > /etc/apt/sources.list.d/pgdg.list
apt update && apt -y install postgresql-16 postgresql-16-pgvector postgresql-client-16
# Node 20 для сборки фронта (оба проекта: npm ci && npm run build)
curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt -y install nodejs
# composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw allow 8443 && ufw enable
```

- [x] Пакеты, PHP 8.3, Node 20, composer, ufw (22, 80, 443, 8443) поставлены 04.11.2026. Swapfile не нужен: провайдер настроил swap 16 ГБ на LVM.
- [x] SSH: вход по паролю **не отключать** (решение заказчика 04.11: нужен доступ с других компьютеров), ключи работают рядом с паролем. fail2ban настроен: 6 попыток за 10 минут, бан на 10 минут.
- [ ] Сменить пароль root из письма провайдера на свой длинный (заказчик сам, `passwd`).
- [x] PostgreSQL 16.15 поставлен 04.11.2026: слушать только `localhost`. Настройки под 16 ГБ: `shared_buffers=4GB`, `effective_cache_size=10GB`, `work_mem=32MB`, `maintenance_work_mem=1GB`, `max_connections=100`.
- [x] Базы и пользователи созданы 04.11.2026, pgvector 0.8.7. Пароли сгенерированы в `/root/db-passwords` на сервере; перенести в менеджер паролей (не в git):
  ```sql
  create user mylift with password '…';  create database "MyLift" owner mylift;
  create user quoteparts with password '…'; create database quoteparts owner quoteparts;
  \c "MyLift"  -- от postgres
  create extension if not exists vector; create extension if not exists pg_trgm;
  \c quoteparts
  create extension if not exists vector; create extension if not exists pg_trgm;
  ```
  В `.env`: `DB_HOST=127.0.0.1`, `DB_SSLMODE=disable`.
- [ ] Пользователь `quoteparts` (system user), свой пул PHP-FPM `/run/php/quoteparts.sock` (`/etc/php/8.3/fpm/pool.d/quoteparts.conf`, `user=quoteparts`).
- [ ] nginx:
  - `mzcorp.ru`: конфиг `deploy/nginx/mzcorp.conf` (root `/var/www/mzcorp/public`, gzip для `application/json`);
  - `quoteparts.ru` (сотрудники и API, прямо);
  - `quoteparts.net` — 443 прямо и 8443 с PROXY protocol только от Гонконга (`mzhk` 43.225.57.182). Общий сниппет `snippets/quoteparts-app.conf` — восстановить по CLAUDE.md QuoteParts (или из `server-config.tgz`, если VPS спасён).
- [x] Сертификат mzcorp.ru + www выпущен 04.11.2026, www перенаправляется на mzcorp.ru (иначе разные сессии и ошибка 419). `certbot renew --dry-run` дважды упал: Let's Encrypt не дождался ответа DNS Beget (`query timed out looking up A`). Перенести DNS с Beget до следующего продления (примерно начало января 2027) и повторить проверку.
- [ ] Сертификаты quoteparts.ru и quoteparts.net (после переключения DNS).
- [ ] Гонконг (`ssh mzhk`): в `/etc/nginx/stream-passthrough.conf` заменить адрес бэкенда 84.54.31.54 → новый IP, `nginx -t && systemctl reload nginx`.

## 4. Этап 2 — приложения и пустые базы

**mzCorp** (`/var/www/mzcorp`, владелец `www-data`):

```bash
git clone https://github.com/markyel/mzcorp.git /var/www/mzcorp && chown -R www-data:www-data /var/www/mzcorp
cd /var/www/mzcorp && sudo -u www-data bash -c 'composer install --no-dev -o && cp .env.example .env'
# заполнить .env (§2); затем:
sudo -u www-data php artisan key:generate   # только если старый APP_KEY не спасён
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --class=RolesAndPermissionsSeeder --force
sudo -u www-data php artisan db:seed --class=ClientNotificationTemplatesSeeder --force
sudo -u www-data php artisan db:seed --class='Database\Seeders\Kb\KbInitialSeeder' --force   # проверить, что он вызывает остальные сидеры database/seeders/Kb/*
sudo -u www-data bash -c 'npm ci && npm run build'
sudo -u www-data php artisan storage:link && sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache && sudo -u www-data php artisan view:cache
```

Сделано 04.11.2026 на новом сервере: composer, минимальный `.env` (база, новый `APP_KEY`, без внешних ключей), все 293 миграции на пустой базе без ошибок (157 таблиц, vector- и trgm-индексы на месте), сидеры ролей, шаблонов и базы знаний, фронт, кеши. nginx отдаёт сайт по HTTP. `TestUsersSeeder` не запускать: это тестовые учётки. Permissions в таблице — 0 намеренно, права через Gates.

- [x] **Нумерация заявок (выставлена 04.11: 30000) — продолжить выше старых номеров**, иначе новые заявки получат номера из старых писем (`M-2026-…` в темах привязываются по номеру): `insert into request_code_sequences (year, last_value, created_at, updated_at) values (2026, 30000, now(), now());` (последний известный — около M-2026-21800).
- [ ] supervisor: `cp deploy/supervisor/mzcorp-worker.conf /etc/supervisor/conf.d/` (4× `mzcorp-worker` mail-sync,default; `mzcorp-default-worker`; `mzcorp-catalog-worker`), `supervisorctl reread && supervisorctl update`.
- [ ] cron от `www-data`: `* * * * * cd /var/www/mzcorp && php artisan schedule:run >> /dev/null 2>&1`.
- [x] Первый администратор создан 04.11.2026 (роль `admin`), пароль в `/root/mzcorp-admin-password` на сервере.
- [ ] Сотрудники. Известно (имя → латиница): Роденков Александр (Alexander Rodenkov), Дмитрий (Dmitry, id 9), Курзаев Илья (Ilya Kurzaev, РОП), Васюхно Андрей (Andrey Vasukhno), Головнев Владимир (Vladimir Golovnev), Боев Андрей (Andrey Boev), Румянцев Дмитрий (Dmitry Rumyantsev), Агрызков Сергей (Sergey Agryzkov), Якубович Дмитрий (Dmitry Yakubovich), Яковлева Дарья (Daria Yakovleva), Соколова Ольга Андреевна (Olga Sokolova), Петрова Марина (Marina Petrova), Милючихин Владимир (Vladimir Milyuchikhin), Илья Юрков (Ilya Yurkov). Адреса — `имя.фамилия@myzip.ru` (проверить у каждого). Роли — подтвердить у заказчика. Создавать через «Администрирование → Менеджеры» или tinker под www-data.

**QuoteParts** (`/var/www/quoteparts`, владелец `quoteparts`): новый deploy-ключ (`ssh-keygen -t ed25519 -f ~quoteparts/.ssh/github_quoteparts`, публичный — в GitHub → markyel/quoteparts → Deploy keys, read-only), `~quoteparts/.ssh/config` с `Host github.com`, затем:

```bash
sudo -u quoteparts bash -c 'git clone git@github.com:markyel/quoteparts.git /var/www/quoteparts && cd /var/www/quoteparts && composer install --no-dev -o && cp .env.example .env'
# .env: QP_STAFF_DOMAIN=quoteparts.ru, QP_SUPPLIER_DOMAIN=quoteparts.net, DB_*, OPENAI_*, MAIL_*
sudo -u quoteparts bash -c 'cd /var/www/quoteparts && php artisan key:generate && php artisan migrate --force && npm ci && npm run build && php artisan config:cache && php artisan route:cache && php artisan view:cache'
```

- [ ] supervisor `quoteparts-worker` (от `quoteparts`), cron `schedule:run` от `quoteparts`.
- [ ] Проверочная копия для тестов на Postgres: `~quoteparts/ci`, схема `phpunit` (CLAUDE.md QuoteParts).

**Проверка этапа:** `php artisan migrate:status` (всё Ran), открываются `https://mzcorp.ru/login`, `https://quoteparts.ru`, `https://quoteparts.net/help`; `supervisorctl status` — всё RUNNING; `queue:watchdog` молчит.

## 5. Этап 3 — почта (первым делом: чтобы пошли новые заявки)

- [ ] Завести ящики в «Почта → ящики» и войти через Яндекс (OAuth). Список по последнему состоянию: общий `info@myzip.ru` и личные ящики сотрудников (Васюхно, Румянцев, Якубович, Агрызков, Головнев, Курзаев, Петрова, Милючихин и др., всего было 12+), `orders@myzip.ru` (канал внесистемных поставщиков — подключается в QuoteParts, §7), `rfq@`, `bot-intake@mzcorp.ru` (неактивный ящик-носитель заявок из ботов — `BotIntakeStore`). Свериться с MEMORY.md (разделы про ящики, `MAIL_INTERNAL_DOMAINS=myzip.ru,mylift.ru,mzcorp.ru`, `order@myzip.ru` — веб-форма сайта).
- [ ] **Первая синхронизация ящика не тянет историю**: ставит водяной знак на текущий максимум UID (`SyncMailboxFolderJob`, first-time watermark). То есть после подключения mzCorp видит только новые письма — заявки не посыплются из архива. Это и нужно на старте.
- [ ] Правила распределения, sticky-окна, проценты нагрузки (`load_weight`), режим близнецов (`assignment.twin_mode=similar`, `assignment.twin_similarity=70` — эксперимент, включён 30.10), «Пилот» QuoteParts, ящики «Почты» для копий переписки QP — ввести по MEMORY.md.
- [ ] **Проверка:** тестовое письмо на info@ → заявка создана, распределена, письмо скопировано менеджеру в папку `MZ|<менеджер>`, на странице «Качество почты» решение записано.

## 6. Этап 4 — история (после того как приём новых заявок работает)

> **Изменено 04.11.2026:** заказчик решил загрузить историю до включения системы. Шаги 2–4 ниже заменены планом `deploy/recovery/RETRO_PLAN.md`: готовые команды запускают раскладку в ящиках, а зеркало истории блокирует последующую загрузку.

Порядок важен: сначала каталог (иначе позиции не сопоставятся), потом почта, потом заявки.

1. [ ] Каталог из 1С: `catalog:sync-from-url`, затем `catalog:embed` (≈35 тыс. позиций, несколько часов и OpenAI-расход — запускать в фоне вечером). Проверить поиск по артикулу и по названию.
2. [ ] Шапки истории ящиков (поиск в «Почте» по прошлым письмам): `mail:history-mirror` (по ящикам, `--budget`, `--shard=k/n` для параллели). Было ~0,9 млн шапок, тела подкачиваются по открытию.
3. [ ] Заявки из прошлых писем — **только за выбранный период** (решение 3) и **не раскладывая заново письма менеджерам** (раскладка уже есть в Яндексе, повтор даст дубли в папках `MZ|…`). **Готовой команды нет** — `mail:sync` умеет только `--mailbox`, `--folder`, `--sync`, а упомянутые в комментарии `SyncMailboxFolderJob` «`mail:reingest-uid` / `--since-uid`» не реализованы. Написать в начале этапа команду ретро-загрузки: письма INBOX/Sent за период → `email_messages` без доставки менеджерам и без перекладки по папкам → `mail:create-requests` (без `--apply` — посмотреть, с `--apply` — создать) → ответственный — по папке `MZ|<менеджер>`, куда письмо было разложено в Яндексе. Проверить на 5–10 письмах, потом на всём периоде.
4. [ ] Статусы восстановленных заявок — по «Отправленным»: КП и счета распознаются штатными детекторами при синхронизации Sent за период. Номер 1С — из тем писем.
5. [ ] Справочник поставщиков: `suppliers:mine-from-outbound` (по нашим исходящим запросам), затем вручную флаги: внесистемные (EVE, OTI ×3, JF/JIEFENG, BUENO LIFT ×2, ПЭА ukolova@), агент UniSystem, группы.
6. [ ] Клиенты: `clients:extract-requisites` → `clients:registry-sync` (DaData/ЕГРЮЛ), `requests:backfill-webform-clients`, `requests:backfill-forwarded-clients`.
7. [ ] Директ и Метрика: `direct:sync`, `direct:stats`, `metrika:pull` (после новых токенов).
8. [ ] Если VPS спасён: сохранить `mzcorp-storage.tgz` / `quoteparts-storage.tgz` отдельно, в `storage/app` нового сервера не смешивать. Без базы файлы ни к чему не привязаны: вложения писем при повторной загрузке скачаются заново из Яндекса. Ценное из архива — фото товаров каталога и обложки медиаплана, выгрузки цен; их разобрать вручную по мере надобности.

## 7. Этап 5 — QuoteParts заново

- [ ] Компания-реселлер «Мой Зип» и сотрудники платформы (вход ссылкой).
- [ ] API-токен для mzCorp (`ApiToken::issue('mzcorp')`), вебхук на `https://mzcorp.ru/...` с секретом → `QUOTEPARTS_API_TOKEN`, `QUOTEPARTS_WEBHOOK_SECRET` в `.env` mzCorp.
- [ ] Ящик внесистемных: `orders@myzip.ru` через «QuoteParts → Внесистемные поставщики → Подключить через Яндекс» (или `SetMailboxCommand --channel=offsystem`). Ящики платформы `myzip@quoteparts.net`, `support@quoteparts.net` (`--channel=support`) — если почта Beget жива; иначе завести почту домена заново у другого провайдера.
- [ ] Пул позиций: `quoteparts:sync-pool` из mzCorp.
- [ ] Поставщики: заново пригласить тех, кто был в кабинете; внесистемным просто отправлять запросы (флаг в mzCorp).
- [ ] Свежие цены октября частично лежат письмами в `orders@` — после подключения ящика их можно разобрать повторно (решить, нужно ли).

## 8. Этап 6 — интеграции и каналы

- [ ] Боты Telegram и MAX: «Настройки → Боты» — токены, вебхуки (`/api/bot/telegram`, `/api/bot/max`, секрет в заголовке), проверка связи. Канал MAX требует сертификат Минцифры в `MAX_EXTRA_CA`.
- [ ] Медиаплан: каналы VK, Telegram, MAX, Дзен (`media_channels` — ключи), темы медиаплана заново (описание в MEMORY: темы, `industry_digest`, `media:news-fetch`).
- [ ] IQOT (мониторинг цен), Liftway-фид (`LIFTWAY_FEED_*`, фид Директа под `YANDEX_DIRECT_FEED_TOKEN`), Calltouch-метки в Директе.
- [ ] Авто-КП: правила (MEMORY «Правила авто-КП»: v3 — 200 тыс., 3 позиции, срок известен; Liftway cost+15%).

## 9. Этап 7 — копии и сторож (обязательно до того, как люди начнут работать в системе)

- [ ] Ежедневная копия обеих баз и `storage/app`: `deploy/backup/full-backup.sh` по cron в 03:00 → хранилище S3 Reg.ru (бакет с хранением 14 дней) + еженедельно на рабочий компьютер `deploy/backup/pull-backup.sh`.
- [ ] Раз в месяц — пробное восстановление копии на пустую базу и `migrate:status`.
- [ ] Включить в Reg.ru «Резервное копирование» сервера (ежедневное, 5,26 ₽/ГБ в месяц).
- [ ] Внешний сторож доступности. Down-детектор был только спроектирован (MEMORY 05.10), в коде его нет: `health:check` по cron, `/health` для UptimeRobot/healthchecks.io, тревога в Telegram — написать.
- [ ] Позже (по желанию) — тёплое зеркало: потоковая репликация PostgreSQL на второй сервер. Теперь база своя — права администратора есть.

## 10. Этап 8 — люди

- [ ] Объявление сотрудникам: что потеряно (история заявок до даты восстановления), что работает, как войти (пароли заново), где искать старую переписку (в почте Яндекса и в «Почте» mzCorp после `history-mirror`).
- [ ] Первые дни — следить за «Качеством почты», очередями, распределением.

## Грабли (из прошлых сессий, не наступать)

- artisan/tinker/git на сервере — только от владельца (`www-data` / `quoteparts`), после фоновой задачи `ps -eo cmd | grep psysh` должно быть пусто.
- После правок сервисов — `php artisan queue:restart` (воркеры держат код в opcache).
- Новые Tailwind-классы без `npm run build` не работают.
- Весь набор тестов mzCorp не гонять против боевой базы (`RefreshDatabase`) — только Unit без БД или отдельная тестовая база; у QuoteParts тесты на Postgres — только в схеме `phpunit`.
- Детекторы почты — перед выкладкой `mail:detector-replay` (корпус `storage/app/mail-corpus/baseline.json` **потерян вместе с сервером, если VPS не спасён** — собрать новый по мере накопления писем).

## Подготовленные скрипты

- `deploy/backup/full-backup.sh` — копия обеих баз (схема + данные в каталожном формате, размеры таблиц), `storage/app` и `.env` обоих приложений, конфиги сервера, контрольные суммы. Запуск от root: `nohup bash /root/full-backup.sh > /root/full-backup.log 2>&1 &`. Пути баз берёт из `.env` приложений — на новом сервере работает без правок.
- `deploy/backup/pull-backup.sh` — скачать копию на компьютер с докачкой и проверкой сумм (Git Bash).
