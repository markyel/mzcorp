---
name: usage-stats
description: "Раздел «Использование системы» (/dashboard/usage, director+admin): время в системе (heartbeat+оценка), письма/матчи/уточнения по дням и менеджерам"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Раздел статистики активности менеджеров (Фаза, commit 6060725, на проде). Доступ: **только директорат + админ** (`role:director,admin`, нав-пункт «Использование»). Маршрут `usage-stats.index` (GET /dashboard/usage), Livewire `App\Livewire\UsageStats\Index`, сервис `App\Services\Analytics\ManagerUsageStatsService::report(from,to,ids)`. Фильтры периода (7/30/90 + произвольный) и менеджеров — как в [[manager-analytics]]. Итоги по менеджерам + детализация по дням.

**Метрики** (по дням × менеджер, менеджеры = request-handler роли Manager+РОП):
- **Письма (ручные)** — `email_messages` outbound, `is_draft=false`, `draft_author_user_id` (составлено в CRM, не Sent-синк), **И исключены авто-уведомления** через `whereNotExists client_notifications_sent.outgoing_email_message_id`. КРИТИЧНО: `ClientNotificationService` при системном триггере ставит автором `assignedUser`, поэтому order_received/quote_followup_reminder/invoice_*/revival_offer ложно выглядели письмами менеджера — на проде это было ~98.7% (все менеджеры 30д: 4344→57; Агрызков 748→12). Та же фильтрация в email-ветке оценки времени (иначе авто-таймстампы раздували время: Агрызков ~103ч→~12ч). Прямые отправки из почтового клиента и так исключены (нет draft_author). Авто-напоминания поставщикам (SupplierReminderService, author=inquiry.createdBy) — мизер (~6/30д), не вычитаем. NB: менеджеры ведут осн. переписку ПРЯМО в клиенте мимо CRM (у Агрызкова 1362 из 3108 исходящих — прямые) — это и есть «работа вне системы».
- **Сопоставления каталогу (ручные)** — `request_items.quality_assessment_payload->catalog_match` (`method=manual_link`, `by_user_id`, `matched_at` ISO8601). Редкое событие (на 29.06 всего ~24 за всё время).
- **Уточняющие вопросы** — `clarification_questions` отправленных батчей (`clarification_batches.status=sent`, `created_by_user_id`, `sent_at`). Тоже почти не используется (17 батчей/3 вопроса).

**Время в системе — ГИБРИД:**
- *Heartbeat* (точно, вперёд): JS в `layouts/app.blade.php` пингует POST `/heartbeat` (`heartbeat.ping` → `HeartbeatController`) раз в 60с, пока `document.visibilityState==='visible'`. Контроллер `insertOrIgnore` текущей минуты в `user_activity_minutes` (unique `(user_id, minute)` → дедуп). Время/день = COUNT минут. Пишут ВСЕ авторизованные.
- *Оценка* (для дней до heartbeat, помечается «~»): сессионизация таймстампов действий (письма, state_changes, assignments, clarifications, request_user_views, ai_decisions) с разрывом 30 мин, одиночное событие=1 мин. JSONB-матчи в оценку НЕ входят (дорогой full-scan; на время влияют слабо).

**TZ-конвенция (важно):** все timestamps проекта MSK-naive (`timestamp without time zone`, app.tz=Europe/Moscow, сессия БД Europe/Moscow). Поэтому группировка по дню — `DATE(col)` напрямую, границы периода — MSK-строки БЕЗ `->utc()`. (NB: [[manager-analytics]] ManagerAnalyticsService использует `->utc()`+`AT TIME ZONE` — потенциально ±3ч скос на границах; здесь сделано иначе и корректно.)

**Прунинг:** `usage:prune-activity-minutes --days=365` (PruneUserActivityMinutesCommand), крон daily 05:00. Таблица узкая.

Перф: `report()` ~300мс на проде (request_items всего ~13k/24МБ; email_messages 16ГБ — это TOAST'нутый raw_source, мои колонки его не детоастят). Грабли отладки: `php artisan tinker /tmp/file.php` в non-TTY может уйти в REPL-ожидание stdin и «висеть» (вывод буферизуется, флашится на kill) — для прод-проверок надёжнее `--execute=` или `echo | tinker file`. Связано: [[prod-deploy]].
