---
name: queue-retry-after
description: Очередь database — DB_QUEUE_RETRY_AFTER=1900 на проде с 09.11.2026; при 90 по умолчанию долгие задачи выполнялись дважды
metadata:
  type: reference
---

`config/queue.php` database `retry_after` = `DB_QUEUE_RETRY_AFTER` (по умолчанию 90). Задачу, которая идёт дольше retry_after, воркер считает зависшей и отдаёт второму — она выполняется дважды. Видно было 09.11: ручной переразбор ТЗ ДМА стартовал в 10:28:24 и ещё раз в 10:29:56. При этом у задач `$timeout` 180 (разбор позиций, теперь 900), 600 (бот), 900 (импорт цен), 1800 (эмбеддинги каталога).

С 09.11 на проде `DB_QUEUE_RETRY_AFTER=1900` (копия `.env` `/root/backups/env/mzcorp.env.20261109-105937-retry-after`), описано в `.env.example`. **Правило:** retry_after больше самого долгого `$timeout`; новая долгая задача → проверить. Цена: задача, убитая вместе с воркером, повторится только через ~32 мин.

Связано: [[item-parsing-pipeline]], [[new-server-access]].
