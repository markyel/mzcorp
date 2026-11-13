#!/usr/bin/env bash
# Забрать файлы приложений (storage/app mzCorp и QuoteParts: вложения писем, фото, прайсы)
# на рабочий компьютер. Планировщик Windows раз в неделю (Git Bash):
#   bash deploy/backup/pull-files.sh >> /c/Users/Boag/mzcorp-backups/pull-files.log 2>&1
# Качает только новые и изменённые по размеру файлы одним tar-потоком через ssh; оборванный
# поток докачивается следующим проходом (до PASSES). Удалённые на сервере файлы здесь остаются.
set -uo pipefail
DEST=${DEST:-/c/Users/Boag/mzcorp-backups/files}
PASSES=${PASSES:-3}
echo "=== $(date '+%F %T')"

# Один запуск за раз: ручной прогон и планировщик не пишут в один файл. Брошенная метка (>12 ч) снимается.
LOCK="${DEST%/}/.lock-pull-files"
mkdir -p "$(dirname "$LOCK")"
find "$LOCK" -maxdepth 0 -mmin +720 -exec rmdir {} \; 2>/dev/null
mkdir "$LOCK" 2>/dev/null || { echo "уже идёт другой запуск — пропускаю"; exit 0; }
trap 'rmdir "$LOCK"' EXIT

sync_app() {  # sync_app <имя> <каталог приложения на сервере>
    local name=$1 app=$2 dir="$DEST/$1" pass count
    mkdir -p "$dir"
    ssh -n -o BatchMode=yes mzcorp "cd $app && find storage/app -type f ! -path 'storage/app/private/livewire-tmp/*' -printf '%s\t%p\n'" > "$dir/.remote-list" \
        || { echo "$name: сервер не отвечает"; return 1; }
    for pass in $(seq "$PASSES"); do
        ( cd "$dir" && find storage -type f -printf '%s\t%p\n' 2>/dev/null ) > "$dir/.local-list"
        awk -F'\t' 'NR == FNR { have[$2] = $1; next } !($2 in have) || have[$2] != $1 { print $2 }' \
            "$dir/.local-list" "$dir/.remote-list" > "$dir/.missing"
        count=$(wc -l < "$dir/.missing")
        if [ "$count" -eq 0 ]; then
            echo "$name: все $(wc -l < "$dir/.remote-list") файлов на месте, $(du -sh "$dir/storage" | cut -f1)"
            return 0
        fi
        echo "$name: проход $pass — качаю $count файлов ($(awk -F'\t' 'NR == FNR { need[$1]; next } $2 in need { s += $1 } END { printf "%d МБ", s / 1048576 }' "$dir/.missing" "$dir/.remote-list"))"
        ssh -o BatchMode=yes -o ServerAliveInterval=30 mzcorp "cd $app && tar --ignore-failed-read -cf - -T -" < "$dir/.missing" \
            | tar -xf - -C "$dir" || echo "$name: поток оборвался"
    done
    echo "$name: не всё скачано — догрузится при следующем запуске"
    return 1
}

status=0
sync_app mzcorp /var/www/mzcorp || status=1
sync_app quoteparts /var/www/quoteparts || status=1
exit $status
