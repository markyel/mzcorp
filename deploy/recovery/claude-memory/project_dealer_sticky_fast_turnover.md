---
name: dealer-sticky-fast-turnover
description: "Дилерская автопометка считает поток заявок за окно (не '8 одновременно открытых') — ловит дистрибьюторов с быстрым оборотом; кейс chasti-stock.by"
metadata:
  node_type: memory
  type: project
  originSessionId: <скрыто>
---

РЕШЕНО 2026-06-29 (commit 13e5628, на проде). Жалоба РОПа: «правило дистрибьютор, отменяющее sticky по клиенту, не работает» — все заявки Эколифта (`<адрес>`, домен chasti-stock.by, дистрибьютор РБ) липли к одному менеджеру (uid=16 Агрызков).

**Был корень:** email НЕ помечался дилером, хотя 22 заявки всего. `DealerEmailService::autoMarkIfNeeded` ставил флаг только при **≥8 ОДНОВРЕМЕННО открытых** заявок. Клиент быстро квотирует/закрывает → пик open_now=5 → порог 8 по открытым не достигался → Уровень 2 (client_email sticky) клеил каждую новую к Агрызкову. Подтверждено `request_assignments.reason`=`auto_sticky:{"kind":"client"}` на всех.

**Фикс:** `autoMarkIfNeeded` теперь считает ПОТОК заявок за скользящее окно (ЛЮБОЙ статус), а не open-статусы. Новый конфиг `dealer.auto_window_days` (env `DEALER_AUTO_WINDOW_DAYS`, дефолт 30; 0 = вся история). Порог `dealer.auto_threshold` остался 8. Оба правятся в Настройках РОПа (группа «Распределение заявок»). Проверка прода: chasti-stock.by = 15 заявок за 30 дней ≥ 8 → пометится на СЛЕДУЮЩЕЙ входящей (self-healing, бэкфилла не делали). Уже открытые заявки остаются у Агрызкова.

**Архитектура sticky** (`AssignmentService::pickStickyManager`, 4 уровня): 0 direct_mailbox → 1 catalog_item_id → 2 client_email → 3 parsed_article/name. Дилерский флаг отменяет **ТОЛЬКО Уровень 2** (`pickStickyByClientEmail`). Остаточный нюанс: часть писем Эколифта идёт в личный ящик Агрызкова (`kind:"direct_mailbox"`, Уровень 0) — приклеятся к нему ВСЕГДА, флаг не отменяет 0/1/3 (by design). Если всплывёт снова — обсудить ручной флаг «дистрибьютор» на организации ([[clients-section]]) и отмену sticky на 0/1/3.

Связано: [[request-access-delegation]]. БД только на проде (ssh, см. [[prod-deploy]]).
