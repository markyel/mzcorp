---
name: prod-outage-root-log-owner
description: Инцидент 2026-10-03..05 — прод встал на 2.5 дня (воркеры FATAL): дневной лог создан под root забытым tinker-циклом; как диагностировать и чинить, защита через ACL
metadata:
  type: reference
---

**Инцидент 2026-10-03 09:30 → 2026-10-05 08:00 (МСК).** Заявки не создавались, почта не синкалась, рассылки не шли.

**Цепочка:** на проде остался висеть `psysh (loop)` под root (tinker-сессия от 2026-10-02 с бесконечным циклом, спамил `CitedOutboundQuoteRouter` ~330 МБ/сутки) → в полночь 3 октября он первым создал `storage/logs/laravel-2026-10-03.log` под `root:root 0644` → воркеры (`www-data`) падали на любой попытке писать лог (`could not be opened in append mode`) → supervisor после быстрых падений перевёл все `mzcorp-worker`/`mzcorp-default-worker` в FATAL → всё, что через очередь (IMAP sync, парсинг, роутинг, нотификации) остановилось. Web (php-fpm) при этом работал.

**Диагностика (в этом порядке):** `supervisorctl status` → `ls -la storage/logs/*.log` (владелец!) → `ps -eo user,pid,lstart,cmd | grep -E "psysh|artisan"` → `lsof storage/logs/laravel-<today>.log` (кто держит файл).

**Починка:** `kill <psysh>`; `chown www-data:www-data storage/logs/*.log`; `supervisorctl restart mzcorp-worker:* mzcorp-default-worker:*`; при горе стухших периодических джоб (`SyncImapFoldersJob`/`PullImapSeenFlagsJob`/`SyncMailboxFolderJob` — планировщик ставит их заново каждую минуту) — удалить из `jobs`.

**Грабля при чистке `jobs`:** эти джобы `ShouldBeUnique` (uniqueFor 15 мин). Удаление строк из `jobs` НЕ снимает замок `cache_locks` (`laravel_unique_job:App\Jobs\Mail\...`) → до истечения замка новые dispatch тихо отбрасываются (mail:sync пишет «Dispatched», а в очереди пусто и в логе ничего). Либо подождать 15 мин, либо удалить замки из `cache_locks` вместе со строками jobs.

**Защита (сделано 2026-10-05):** `apt install acl`, `chmod g+s storage/logs`, `setfacl -R -m g:www-data:rwX -m d:g:www-data:rwX storage/logs` — файл, созданный root, теперь `root:www-data rw-rw-r--+`, www-data пишет. Проверено.

**Правило:** на проде artisan/tinker только через `sudo -u www-data`; не оставлять tinker-циклы (`while(true)`/`loop`) — после сессии проверять `ps | grep psysh`. См. также [[prod-deploy]].
