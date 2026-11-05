---
name: personal-inbox-delivery
description: "Доставка оригинала письма в ЛИЧНЫЙ ящик менеджера (жалоба «заявка в CRM есть, в почте нет»)"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Два независимых механизма доставки входящего письма менеджеру:
- **`MailFolderRouter::routeToManager`** — внутри ОБЩЕГО ящика (info@myzip.ru, shared) MOVE письма в подпапку `MZ|<Фамилия>` (для секретаря/карты распределения). Только для shared-ящиков.
- **`MailDeliverToManagerService::deliver`** — IMAP APPEND оригинала (raw RFC822) в **INBOX личного ящика** менеджера (напр. <адрес>, personal). Это то, что менеджер видит «в почте».

Диспатч deliver: `DeliverToManagerInboxJob` (из `AssignmentService::autoAssign` и реассайна) + финальная страховка `mail:backfill-manager-deliveries` (крон каждые 30 мин). Аудит каждой попытки — таблица **`mail_append_audit`** (origin_mailbox_id, target_mailbox_id, status pending/success/failed/skipped, skip_reason, error_message). Идемпотентность — `email_messages.detected_artifacts.inbox_deliveries[]`.

deliver() берёт полный RFC822 через `fetchFullRfc822` = re-FETCH из source-ящика по `imap_uid` (FT_PEEK; raw_source хранит только body, без headers — APPEND'ить его нельзя).

**Грабля (M-2026-4100, «в CRM есть, в почте нет ~34 мин»):** на свежей заявке deliver срабатывает раньше, чем IMAP-sync проставил source `imap_uid` → fetchFullRfc822 = '' → раньше был тихий `return false` (skip_reason `empty_raw_rfc822`), джоб НЕ ретраил, доставку вытягивал только 30-мин backfill-крон. **Фикс:** для свежего письма (есть message_id, created < 6ч) empty RFC822 бросает `TransientImapException` → джоб ретраит (backoff `[30,120,300]`, tries=4) → доставка за ~30-120с. Старые/неизвлекаемые — прежний тихий skip.

**Диагностика жалобы:** `mail_append_audit` по message_id_rfc или email_message_id покажет цепочку попыток; `EmailMessage` с тем же message_id в mailbox менеджера (type=personal) folder=INBOX = доставлено. Команда `mail:diag-inbox`.
