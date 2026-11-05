#!/usr/bin/env bash
# Забрать последнюю ночную копию (nightly-db.sh) на рабочий компьютер и убрать старые.
# Запускается планировщиком Windows раз в день (Git Bash):
#   bash deploy/backup/pull-nightly.sh >> /c/Users/Boag/mzcorp-backups/pull.log 2>&1
set -uo pipefail
cd "$(dirname "$0")/../.."
DEST=${DEST:-/c/Users/Boag/mzcorp-backups}
KEEP_DAYS=${KEEP_DAYS:-30}
echo "=== $(date '+%F %T')"

latest=$(ssh -o BatchMode=yes -o ConnectTimeout=20 mzcorp 'ls -1 /root/backups/nightly 2>/dev/null | sort | tail -1') || { echo "сервер не отвечает"; exit 1; }
if [ -z "$latest" ]; then
    echo "на сервере нет ночных копий"
    exit 1
fi
DEST="$DEST" bash deploy/backup/pull-backup.sh "backups/nightly/$latest" | tail -n 2

find "$DEST/backups/nightly" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -print -exec rm -rf {} + 2>/dev/null | sed 's/^/удалена: /'
