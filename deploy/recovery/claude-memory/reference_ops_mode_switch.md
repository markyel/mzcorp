---
name: ops-mode-switch
description: deploy/ops/mode.sh — переключатель «наблюдение ↔ полный» для mzCorp и QuoteParts; что в него входит и что покажет пробный full
metadata:
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-11-09T06:41:12.100Z
---

`bash /var/www/mzcorp/deploy/ops/mode.sh status | full | observe [--apply]` от root на сервере (коммит 1dee04cc, 07.11.2026). Без `--apply` — таблица ключей и для `full` пробные прогоны включаемых задач; с `--apply` — копии `.env` в `/root/backups/env/`, правка ключей на месте (владелец/права сохраняются), `queue:restart`, `config:cache` + рестарт воркера QuoteParts, строка в `/var/log/mzcorp-mode.log`.

Ключи: mzCorp `ASSIGNMENT_OBSERVE`, `MAIL_IMAP_WRITES`, `MAIL_SYSTEM_NOTIFICATIONS`, `SUPPLIER_REMINDER_ENABLED`, `SCHEDULE_DISABLED` (19 задач ↔ пусто); QuoteParts `QP_RFQ_TICK`. `MAIL_FOLDER_SYNC=true` в обоих режимах. Шаблоны писем клиентам (`client_notification_templates.is_enabled`, все 7 выключены) переключатель не трогает — решение заказчика 07.11. «Полный» = всё как до аварии (заказчик выбрал все группы, включая автозакрытие, сроки счетов и доставку истории в личные ящики).

**08.11.2026 16:46 включён «полный»** (копии `.env` `*.20261108-164627`). 09.11 автозакрытие закрыло 306 (не 193: в понедельник прибавился рабочий день). Мониторинг: `bash deploy/ops/health/health.sh 30` (read-only). На 07.11 режим не переключали. Пробный full: автозакрытие 193 (98 КП + 95 «счёт не оплачен»), сроки счетов 50, дораздача 9, тревоги 2, доставка истории 1 059 писем; `mail:prune-raw-source` необратим. Совет заказчику: переходить после импорта свежих оплат 1С.

Связано: [[autoclose-inactive]], [[personal-inbox-delivery]], [[email-storage-pruning]].
