#!/usr/bin/env bash
# Ночная копия рабочей базы mzCorp (cron root, 03:15):
#   15 3 * * * /bin/bash /var/www/mzcorp/deploy/backup/nightly-db.sh >> /var/log/mzcorp-backup.log 2>&1
#
# Что копируется: база из DB_DATABASE в .env (за сменой базы следует сама), индекс писем
# ретрозагрузки storage/app/retro (по нему сборка повторяется без чтения почты) и .env;
# база QuoteParts и её .env (сбой QuoteParts копию mzCorp не отменяет).
# Файлы (storage/app обоих приложений) сюда не входят: их раз в неделю забирает на рабочий
# компьютер deploy/backup/pull-files.sh; полная копия с файлами — deploy/backup/full-backup.sh.
# Проверка: pg_restore --list по архиву и контрольные суммы. Хранится KEEP_DAYS дней.
# Скачать на рабочий компьютер: deploy/backup/pull-backup.sh nightly/<дата>. Вне сервера — s3-push.sh.
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

# QuoteParts: своя база и свой .env, в подоболочке — переменные mzCorp не трогаем.
(
    QP=${QP_APP:-/var/www/quoteparts}
    qp_get() { grep -E "^$1=" "$QP/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
    export PGHOST=$(qp_get DB_HOST) PGPORT=$(qp_get DB_PORT) PGDATABASE=$(qp_get DB_DATABASE)
    export PGUSER=$(qp_get DB_USERNAME) PGPASSWORD=$(qp_get DB_PASSWORD)
    export PGPORT=${PGPORT:-5432}
    log "копия базы QuoteParts $PGDATABASE"
    if pg_dump -Fc --no-owner --no-privileges -f "$OUT/quoteparts-$PGDATABASE.dump" \
        && pg_restore --list "$OUT/quoteparts-$PGDATABASE.dump" > /dev/null; then
        tar -C "$QP" -czf "$OUT/quoteparts-env.tgz" .env
    else
        log "ОШИБКА: копия базы QuoteParts не прошла"
        rm -f "$OUT/quoteparts-$PGDATABASE.dump"
    fi
)

( cd "$OUT" && sha256sum ./*.dump ./*.tgz ./counts.txt > SHA256SUMS )
log "готово: $(du -sh "$OUT" | cut -f1), заявок $(grep '^requests|' "$OUT/counts.txt" | cut -d'|' -f2)"

find "$ROOT" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -print -exec rm -rf {} + | sed 's/^/удалена старая копия: /'

# Копия вне сервера: ночная копия и новые файлы — в бакет Yandex Object Storage (если ключ задан).
bash "$(dirname "$0")/s3-push.sh" "$OUT"
