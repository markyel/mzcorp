#!/usr/bin/env bash
# Полная копия данных mzCorp + QuoteParts на сервере (запуск от root, только чтение баз и файлов).
# Порядок по ценности: 1) схема обеих баз, 2) данные обеих баз, 3) файлы storage, 4) конфиги сервера.
# Код не копируем — он на GitHub (markyel/mzcorp, markyel/quoteparts).
#
#   nohup bash /root/full-backup.sh > /root/full-backup.log 2>&1 &
#   tail -f /root/full-backup.log
#
# Повторный запуск с тем же STAMP пропускает уже готовые шаги (метка .done).
set -uo pipefail

STAMP=${STAMP:-$(date +%F-%H%M)}
OUT=/root/backup-$STAMP
mkdir -p "$OUT"
JOBS=${JOBS:-4}
log() { echo "[$(date +%T)] $*"; }

env_get() { grep -E "^$2=" "$1/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }

step() {  # step <name> <command...>: выполнить один раз, отметить .done
    local name=$1; shift
    if [ -f "$OUT/.done-$name" ]; then log "skip $name (готово)"; return 0; fi
    log "start $name"
    if "$@"; then touch "$OUT/.done-$name"; log "done  $name"; else log "FAIL  $name (код $?)"; fi
}

db_env() {  # db_env <app dir>: переменные подключения из .env приложения
    export PGHOST=$(env_get "$1" DB_HOST) PGPORT=$(env_get "$1" DB_PORT) PGDATABASE=$(env_get "$1" DB_DATABASE)
    export PGUSER=$(env_get "$1" DB_USERNAME) PGPASSWORD=$(env_get "$1" DB_PASSWORD)
    local ssl=$(env_get "$1" DB_SSLMODE); export PGSSLMODE=${ssl:-prefer}
    export PGPORT=${PGPORT:-5432}
}

check_versions() {  # pg_dump не старше сервера — иначе откажется
    local srv cli
    srv=$(psql -Atc 'show server_version_num' 2>&1) || { log "  нет связи с базой $PGHOST: $srv"; return 1; }
    cli=$(pg_dump --version | grep -oE '[0-9]+' | head -1)
    log "  сервер $PGHOST/$PGDATABASE: $srv, pg_dump: $cli"
    [ "$cli" -ge "$(( srv / 10000 ))" ] || { log "  pg_dump $cli старше сервера нет — поставить postgresql-client-$(( srv / 10000 ))"; return 1; }
}

dump_db() {  # dump_db <app dir> <name>
    db_env "$1"
    check_versions || return 1
    step "$2-schema" pg_dump --schema-only --no-owner --no-privileges -f "$OUT/$2-schema.sql"
    step "$2-rowcounts" sh -c "psql -Atc \"select relname, n_live_tup, pg_size_pretty(pg_total_relation_size(relid)) from pg_stat_user_tables order by pg_total_relation_size(relid) desc\" > '$OUT/$2-tables.txt'; psql -Atc 'select pg_size_pretty(pg_database_size(current_database()))' > '$OUT/$2-size.txt'"
    # Каталожный формат: параллельно и по файлу на таблицу — оборванную докачку видно и можно повторить.
    step "$2-data" pg_dump -Fd -j "$JOBS" --no-owner --no-privileges -f "$OUT/$2-db"
}

log "копия в $OUT"
command -v pg_dump >/dev/null || { log "нет pg_dump: apt-get install -y postgresql-client"; exit 1; }
df -h /root | tail -1

dump_db /var/www/mzcorp mzcorp
dump_db /var/www/quoteparts quoteparts

# Файлы приложений: вложения писем, фото, выгрузки, прайсы (без кэша и логов).
step files-mzcorp tar -C /var/www/mzcorp -czf "$OUT/mzcorp-storage.tgz" --exclude=storage/framework --exclude=storage/logs storage/app .env
step files-quoteparts tar -C /var/www/quoteparts -czf "$OUT/quoteparts-storage.tgz" --exclude=storage/framework --exclude=storage/logs storage/app .env

# Конфиги сервера: nginx, supervisor, cron, сертификаты, php-fpm пул QuoteParts, ключи доступа к GitHub.
step server-config tar -czf "$OUT/server-config.tgz" --ignore-failed-read \
    /etc/nginx /etc/supervisor /etc/letsencrypt /etc/php/8.3/fpm/pool.d /var/spool/cron/crontabs \
    /root/qp-db-pass /home/quoteparts/.ssh

( cd "$OUT" && find . -type f ! -name 'SHA256SUMS' ! -name '.done-*' -print0 | xargs -0 sha256sum > SHA256SUMS )
du -sh "$OUT"/* | sort -h
log "готово: $OUT"
