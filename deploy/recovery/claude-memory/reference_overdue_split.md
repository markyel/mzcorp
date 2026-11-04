---
name: overdue-split
description: "«Просрочено» разнесено: наш долг (SlaBreach) vs «Клиент молчит» (AwaitingClient) по тому, на чьей стороне мяч"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-07-30T21:41:40.902Z
---

Директор контролирует менеджеров через бакет «Просрочено» — чтобы взбодрить тех, кто НЕ сделал свою работу. Но раньше `attention_reason=sla_breach` вешался на ВСЕ не-терминальные статусы с истёкшим дедлайном (наследие фазы «минимизации причин»), и список мешал «наш долг» с «клиент молчит после КП». На проде из 473 overdue только 211 были нашим долгом, 262 — молчание клиента (кейс-повод M-2026-9412: quoted + «Просрочена», хотя менеджер всё сделал вовремя).

**Разнесение (commit на 2026-07-31):** единый источник правды — `RequestStatus::isWaitingOnClient()` = `quoted / under_review / awaiting_client_clarification / invoiced` («мяч у клиента»: КП/уточнение/счёт мы уже сделали). В `AttentionService::compute()` ПОСЛЕ расчёта дедлайна: если `reason===SlaBreach && $status->isWaitingOnClient()` → `reason = AwaitingClient` («Клиент молчит»). Дедлайны/сроки/sweep/уведомление менеджеру НЕ менялись (заказчик выбрал оставить алярм-уведомление и для клиентских).

- **Наш долг** (`new/assigned/in_progress/awaiting_invoice`) → `sla_breach`, бакет **«Просрочено»**, красный алярм. `in_progress` всегда наш долг, даже если ждём поставщика (заказчик выбрал 2 группы, не 3).
- **Клиент молчит** → `awaiting_client`, новый бакет **«🤫 Клиент молчит»** (Pool: `attention_level=1 + reason=awaiting_client`), янтарная info-подсветка (не красная), текст строки «🤫 молчит N» вместо «просрочено N». Гейт янтаря — ТОЛЬКО level=1 (до дедлайна level=0 «через N» — нейтрально, иначе весь пост-КП пул позеленел бы янтарём).

Затронуто: `RequestStatus::isWaitingOnClient()`; `AttentionService::compute()`; `Pool.php` (allowed buckets + `statusesForBucket('silence')` по isWaitingOnClient + render-фильтр + bucketCounts + сортировка); `pool.blade` ($bucketChips silence amber + $isClientSilence в $isInfoFlag с гейтом level=1 + attnText). **Бэкфилл выполнен** (653 строки sla_breach→awaiting_client по клиентским статусам, БЕЗ смены level и без уведомлений — прямой UPDATE, иначе recompute сбросил бы level в 0 и sweep устроил бы шторм 262 уведомлений). Enum `AwaitingClient` до этого в логике не использовался (только в enum/комментариях) — репрпоузнут; label остался «Жду клиента», icon 👤. Связано: [[autoclose-inactive]] (клиент-молчит → дрейф к closed_lost), [[duplicated-source-of-truth]] (предикат в enum, не хардкод-списки).
