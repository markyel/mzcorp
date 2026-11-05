#!/usr/bin/env bash
# Ночная копия рабочей базы mzCorp (cron root, 03:15):
#   15 3 * * * /bin/bash /var/www/mzcorp/deploy/backup/nightly-db.sh >> /var/log/mzcorp-backup.log 2>&1
#
# Что копируется: база из DB_DATABASE в .env (за сменой базы следует сама), индекс писем
# ретрозагрузки storage/app/retro (по нему сборка повторяется без чтения почты) и .env.
# Файлы писем (storage/app/private, ~12 ГБ) сюда не входят: они скачиваются заново из почты;
# полная копия с файлами — deploy/backup/full-backup.sh.
# Проверка: pg_restore --list по архиву и контрольные суммы. Хранится KEEP_DAYS дней.
# Скачать на рабочий компьютер: deploy/backup/pull-backup.sh nightly/<дата>.
set -uo pipefail

APP=/var/www/mzcorp
ROOT=${BACKUP_ROOT:-/root/backups/nightly}
KEEP_DAYS=${KEEP_DAYS:-14}
DAY=$(date +%F)
OUT="$ROOT/$DAY"
log() { echo "[$(date '+%F %T')] $*"; }

env_get() { grep -E "^$1=" "$APP/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
export PGHOST=$(env_get DB_HOST) PGPORT=$(env_get DB_PORT) PGDATABASE=$(env_get DB_DATABASE)
export PGUSER=$(env_get DB_USERNAME) PGPASSWORD=$(env_get DB_PASSWORD)
export PGPORT=${PGPORT:-5432}

umask 077
mkdir -p "$OUT"
log "копия базы $PGDATABASE → $OUT"

if ! pg_dump -Fc --no-owner --no-privileges -f "$OUT/$PGDATABASE.dump"; then
    log "ОШИБКА: pg_dump не прошёл"
    exit 1
fi
if ! pg_restore --list "$OUT/$PGDATABASE.dump" > /dev/null; then
    log "ОШИБКА: архив не читается"
    exit 1
fi
psql -Atc "select relname, n_live_tup from pg_stat_user_tables where relname in ('requests','email_messages','invoices','outbound_quotes','request_items','suppliers') order by 1" > "$OUT/counts.txt"
tar -C "$APP" -czf "$OUT/retro-index-and-env.tgz" storage/app/retro .env
( cd "$OUT" && sha256sum ./*.dump ./*.tgz ./counts.txt > SHA256SUMS )
log "готово: $(du -sh "$OUT" | cut -f1), заявок $(grep '^requests|' "$OUT/counts.txt" | cut -d'|' -f2)"

find "$ROOT" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -print -exec rm -rf {} + | sed 's/^/удалена старая копия: /'
