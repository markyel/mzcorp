---
name: manager-analytics
description: "Раздел «Аналитика» по менеджерам + виджеты дашборда: файлы, метрики, согласованные определения, открытые вопросы"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Раздел **«Аналитика»** (метрики по менеджерам). Доступ: head_of_sales / director / secretary / admin.

## Где что лежит
- Route: `/dashboard/analytics` → `analytics.index` (группа `role:head_of_sales,secretary,director,admin` в routes/web.php, рядом с mail.index). Nav-пункт «Аналитика» в `layouts/navigation.blade.php`.
- View-обёртка: `resources/views/analytics/index.blade.php` → `<livewire:analytics.index />`.
- Компонент: `app/Livewire/Analytics/Index.php` (период 7/30/90 + произвольный диапазон customFrom/customTo; мультивыбор менеджеров `managerIds`; WithPagination для детальной таблицы).
- Blade: `resources/views/livewire/analytics/index.blade.php`.
- **Вся агрегация — в `app/Services/Analytics/ManagerAnalyticsService.php`** (единый источник для раздела И виджетов дашборда).
- Виджеты на дашборде (для privileged): `Dashboard\Index::managerClosedDynamics()` + `managerWonLost()` → те же сервис-методы; blade-вставка в `livewire/dashboard/index.blade.php` между графиком inflow и heatmap (график динамики + компактная таблица Успех/Потеря, ссылка «Аналитика →»).

## Сервис-методы
- `closedDynamics($from,$to,$mgrIds)` — мультилиния: закрытые (won+lost) по дням и менеджерам, **X = дата закрытия** (`requests.closed_at` + status, MSK-бакеты). Палитра PALETTE, цвет по индексу.
- `wonLostByManager(...)` — won/lost/open/total + win_rate, **когорта по дате создания**. Сортировка по числу закрытых (won+lost) desc. (UI показывает только закрытые — поле open в UI убрано.)
- `timeToCloseByManager(...)` — время `closed_at - created_at`, split won/lost, avg + медиана (`PERCENTILE_CONT(0.5) WITHIN GROUP`), когорта по дате создания, только закрытые.
- `requestDetailsQuery(...)` — Builder для детальной таблицы (пагинация в компоненте). **Только closed** (whereIn status closed_won/closed_lost), когорта по дате создания. Под-запросы (литералы, без биндингов → пагинация-count ок):
  - first_reaction_at = `MIN(request_state_changes.created_at WHERE by_user_id IS NOT NULL)`;
  - quote_sent_at = `MIN(quotations.sent_at WHERE status='sent')`;
  - clarifications_count = `COUNT(clarification_questions JOIN clarification_batches WHERE batch.request_id=… AND batch.status IN ('sent','answered'))`.

## Что считаем в аналитике: «ДОШЛО ДО МЕНЕДЖЕРА» (финал, с заказчиком 2026-06-05)
Принцип (формулировка заказчика «надо учитывать всё что дошло до менеджера»): заявка попадает в аналитику, если **дошла до пула менеджера = `assigned_user_id IS NOT NULL`**, независимо от того, кто/как её потом закрыл (менеджер вручную, LLM, cron). Способ закрытия НЕ важен.
- **Не учитываем**: (1) не дошедшее до менеджера — неназначенные авто-закрытия cron'а (напр. `parser_no_content`: 42 из 46 без assigned, cron `requests:recover-unassigned`); отсекается самим фильтром по assigned. (2) Разовую pre-launch заливку / чистку фантомов — `NON_WORK_CLOSE_EVENTS` = `bulk_close_historic` (закрыла ~1413 старых заявок как off_topic) + `cleanup.internal_phantom`: они назначены, но это не активность периода; отсекаем по событию ПОСЛЕДНЕГО закрывающего перехода.
- **Учитываем** (хотя закрыто авто): off_topic, что LLM авто-закрыл, но заявка была назначена (~49+18); авто-закрытия реальных потерь (no_client_response_to_quote ~122 авто, invoice_unpaid ~98 авто — cron по таймауту, но дошли до менеджера и сработаны).
- Механика: `excludeNonWorkClose($q)` = `whereNotIn('requests.id', fromSub(DISTINCT ON request_id ... event) WHERE event IN NON_WORK_CLOSE_EVENTS)`. Фильтр по assigned уже есть в closedDynamics/wonLostByManager/timeToCloseByManager/requestDetailsQuery (атрибуция по assigned_user_id); в lostReasonsBreakdown добавлен `whereNotNull(assigned_user_id)`, а `excluded` = (все lost периода) − kept (для сноски в UI). Виджеты дашборда зовут те же методы.
- Эффект (14 дн): потери 2079 → **598** учтённых, excluded 1482. Win/lost (когорта создания) won 268 / lost 597.
- Эволюция подхода (важно для контекста, НЕ повторять отменённое): ed02564 reason-only (ОТМЕНЁН — резал ручной триаж, не ловил заливку) → fc062ec механизм+причина (ОТМЕНЁН — лишний) → **80e148e финал: «дошло до менеджера» (assigned) + исключение разовой заливки**. e9cac89 — сам отчёт причин.

## Отчёт «Причины Потери» (блок 2c)
- `lostReasonsBreakdown($from,$to,$mgrIds)` → `{total, excluded, rows:[{reason,label,count,share}]}`. Базис — **дата ЗАКРЫТИЯ** (`closed_at`), не когорта по созданию (причина проставляется в момент закрытия; совпадает с вопросом заказчика «при закрытии за период»). label из `ClosedLostReason::label()`, null → «Причина не указана». Карточка: таблица + бары + сноска про `excluded`.

## Отчёт «Топ позиций» (продажи/потери), коммит 33809f3
Отдельная страница `/dashboard/analytics/positions` (`analytics.positions`, та же роль-гейт; `App\Livewire\Analytics\Positions`; ссылка из шапки analytics.index). По каждому `catalog_item`: продано (SUM parsed_qty + COUNT сделок по closed_won) vs проиграно (по closed_lost), из закрытых заявок по дате закрытия. Сортировка won/lost, поиск по name/sku/brand_article, период 30/90/365/all, пагинация 50/стр (стр.1 = Топ-50). Агрегатор — `ManagerAnalyticsService::topPositionsQuery()` (Query\Builder для paginate; GROUP BY catalog_item_id, FILTER по статусу; `excludeNonWorkClose` чистит ТОЛЬКО потери — победы не затрагивает). Учитываются только catalog-matched активные позиции; единица = parsed_qty (в ед. позиции, без приведения). У позиций с отчётом IQOT — инлайн-разворот со сравнением «наша цена vs офферы» (бейдж «IQOT: мин. X ₽ · N офф»), тот же блок, что в разделе IQOT: вынесен в общий partial `resources/views/livewire/iqot/_comparison.blade.php` (ожидает `$pos` = IqotPosition); `Positions::iqotByCatalogId()` грузит отчёты для позиций текущей страницы. Коммит 86c1cf8.

## Согласованные определения метрик (с заказчиком)
- **Динамика обработанных** = закрытые (won+lost) **по дате закрытия**.
- **Первая реакция** = первый переход статуса менеджером (`request_state_changes.by_user_id IS NOT NULL`) — явного поля нет.
- **Успех/Потеря, время закрытия, детализация** = **когорта по дате СОЗДАНИЯ** заявки (created_at в периоде), **только закрытые** (won/lost). «В работе» из этих блоков убрано (заказчик: раздел про закрытые).
- **Атрибуция** = `requests.assigned_user_id` (текущий владелец).
- **КП** = `quotations.sent_at` (status=sent); время до КП = sent_at − created_at.
- Менеджеры = `User::active()->role(Role::requestHandlerRoles())` = **Manager + HeadOfSales** (директор/секретарь/админ в список линий НЕ входят).

## Круговая (донат) Успех/Потеря по менеджерам
- Зелёный сектор = успех, красный = потеря; каждый сектор поделён на сегменты по менеджерам (оттенки greens[]/reds[] по индексу). Считается из `wonLostByManager` (берутся только won+lost>0).
- Подписи — **выноски (leader lines)** с de-collision по вертикали (`$place()`), разнос влево/вправо по `cos(mid-90)`. Квадратик-свотч — **со стороны, обращённой к диаграмме**: у правых подписей слева от имени (`$rInnerX`), у левых — справа после имени (`$lInnerX`); линия заканчивается на грани свотча у диаграммы. viewBox 720×240, донат центрирован (cx=360).

## Открытые вопросы / возможное продолжение
- Заказчику предложено: при желании переключить «закрытые» блоки (донат/сводка/детализация) с **даты создания** на **дату закрытия** («что закрыто за период»). Сейчас — по дате создания. График динамики уже по дате закрытия.
- Если выноски тесно лягут при многих менеджерах на одной стороне — крутить `$gap` или прятать тонкие сегменты в тултип.
- Донат пока только в разделе; можно вынести и на дашборд.
- Времязатратность similar-поиска тут ни при чём; все запросы аналитики — обычный SQL по requests/state_changes/quotations/clarifications, без векторов.
