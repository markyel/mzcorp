#!/usr/bin/env bash
# Забрать ночные копии (nightly-db.sh) на рабочий компьютер и убрать старые.
# Запускается планировщиком Windows раз в день (Git Bash):
#   bash deploy/backup/pull-nightly.sh >> /c/Users/Boag/mzcorp-backups/pull.log 2>&1
# Берёт последние RECENT дней с сервера, которые здесь ещё не забраны целыми (нет .verified):
# пропущенный день (компьютер выключен, оборвалась связь) докачается при следующем запуске.
set -uo pipefail
cd "$(dirname "$0")/../.."
DEST=${DEST:-/c/Users/Boag/mzcorp-backups}
KEEP_DAYS=${KEEP_DAYS:-30}
RECENT=${RECENT:-3}
echo "=== $(date '+%F %T')"

# Один запуск за раз: ручной прогон и планировщик не пишут в один файл. Брошенная метка (>12 ч) снимается.
LOCK="${DEST%/}/.lock-pull-nightly"
mkdir -p "$(dirname "$LOCK")"
find "$LOCK" -maxdepth 0 -mmin +720 -exec rmdir {} \; 2>/dev/null
mkdir "$LOCK" 2>/dev/null || { echo "уже идёт другой запуск — пропускаю"; exit 0; }
trap 'rmdir "$LOCK"' EXIT

days=$(ssh -n -o BatchMode=yes -o ConnectTimeout=20 mzcorp "ls -1 /root/backups/nightly 2>/dev/null | sort | tail -$RECENT") || { echo "сервер не отвечает"; exit 1; }
if [ -z "$days" ]; then
    echo "на сервере нет ночных копий"
    exit 1
fi
status=0
for day in $days; do
    [ -f "$DEST/backups/nightly/$day/.verified" ] && continue
    echo "--- $day"
    DEST="$DEST" bash deploy/backup/pull-backup.sh "backups/nightly/$day" | grep -vE '^\[[0-9]+/[0-9]+\]' || status=1
done

find "$DEST/backups/nightly" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -print -exec rm -rf {} + 2>/dev/null | sed 's/^/удалена: /'
exit $status
