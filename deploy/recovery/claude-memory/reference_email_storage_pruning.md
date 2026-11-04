---
name: email-storage-pruning
description: Раздувание email_messages.raw_source (~464КБ/письмо) переполнило БД → read-only; прунер mail:prune-raw-source + ежедневный крон
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-10-27T19:30:18.491Z
---

**Инцидент 2026-06-22 ~14:08:** Beget Postgres перешёл в **read-only** (`SQLSTATE[25006] cannot execute UPDATE in a read-only transaction`, `transaction_read_only=on`, не реплика) — квота хранилища исчерпана. Симптом: КАЖДЫЙ web-запрос падает 500 (Laravel не может записать сессию, session driver=database). Диагностика: `pg_database_size` = 17 ГБ, из них **`email_messages` = 16 ГБ (94%)**. Оператор расширил квоту (панель Beget) — вернуло запись. **Я не могу менять квоту Beget; в read-only нельзя ни DELETE, ни VACUUM (любая чистка = запись).**

**Корень раздувания:** `email_messages.raw_source` (сырой RFC822 с base64-вложениями) = **~464 КБ/письмо** в среднем (body_html 13КБ, body_plain 2КБ — мелочь). Темп: июнь 19k писем ≈ 9 ГБ raw/мес, ускоряется. Данные молодые (raw старше 90д = 0, система с мая). raw_source нужен ТОЛЬКО до доставки оригинала в личный ящик (`DeliverToManagerInboxJob`/`MailDeliverToManagerService`, ~30 мин) и для редкого ре-декода имён вложений (`mail:rebuild-attachment-names`); ре-парс читает вложения С ДИСКА (`email_attachments.file_path`), не из raw_source.

**Фикс (commit 5ecc700, 2026-06-22):** `mail:prune-raw-source {--apply}{--days=3}{--chunk=500}` (dry-run по умолчанию, батчами) — обнуляет `raw_source` у писем старше N дней, которые **(A)** связаны с ЗАКРЫТОЙ заявкой (closed_won/closed_lost, через `relatedRequest`) **ИЛИ (B)** не классифицированы как заявка (`category=irrelevant`). raw_source ОТКРЫТЫХ заявок СОХРАНЯЕТСЯ (заказчик: окно 3 дня только для закрытых + не-заявок). Ежедневный крон `mail:prune-raw-source --apply --days=3` в 04:30 MSK (routes/console.php) — ограничивает рост по мере закрытия заявок.

**Первый прогон:** обнулено **17 181** письмо (~7.6 ГБ), raw_source осталось у 9 441 (было 26 596). Заказчик отказался от **VACUUM FULL** (лочит таблицу) → сделал обычный `VACUUM email_messages` (non-blocking) → dead 17.6k→1, ~7.6 ГБ освобождено **под переиспользование** (размер таблицы/квота Beget НЕ уменьшились — autovacuum/VACUUM возвращает место для reuse, а не ОС; таблица перестаёт расти, пока не съест освобождённое). Чтобы реально вернуть место Beget — нужен VACUUM FULL в окне (отложено).

**2026-10-27, алерт Beget «lazylift: Disk < 3 GB»** (сервер lazylift = БД MyLift 29 ГБ + чужая Lazylift 5,6 ГБ; QuoteParts на другом сервере). raw_source снова 21 ГБ: ~15 ГБ у писем БЕЗ заявки (треды поставщиков по supplier_inquiry_id, исходящие без заявки, post_sale) — правила A/B их не брали, рост ~9 ГБ/мес. Добавлено правило **(C) related_request_id IS NULL** (черновики не трогаем), c607b5b3; первый прогон обнулил 27 987 писем, raw 21 ГБ→5,8 ГБ, обычный VACUUM (1:49) → TOAST-чанков 7,8M→2,95M. Таблица осталась 26 ГБ (место под reuse); диск сервера вернёт только VACUUM FULL (~8 ГБ свободных нужно) или расширение диска. Разбор офферов поставщиков читает вложения/.eml С ДИСКА, не raw_source. Ещё: failed_jobs 685 AppendToSentFolderJob (12–18.10) = 369 МБ, не чищены.

**На будущее:** если рост снова тревожит — (1) ужать окно (`--days=1`), (2) добавить inline-обнуление raw_source после успешной доставки в `DeliverToManagerInboxJob` (агрессивнее, но требует гард на не-доставляемые), (3) не хранить raw_source у outbound вовсе. См. [[personal-inbox-delivery]], [[prod-deploy]].
