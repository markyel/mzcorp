#!/usr/bin/env bash
# Скачать готовую копию с сервера на этот компьютер (Git Bash). Докачивает: файлы с тем же размером пропускает.
#   bash pull-backup.sh backup-2026-11-02-1530
set -uo pipefail
NAME=${1:?укажите папку копии на сервере, например backup-2026-11-02-1530}
DEST=${DEST:-/c/Users/Boag/mzcorp-backups}/$NAME
mkdir -p "$DEST"

ssh mzcorp "cd /root/$NAME && find . -type f ! -name '.done-*' -printf '%s %p\n'" > "$DEST/.remote-list" || { echo "сервер не отвечает"; exit 1; }
total=$(wc -l < "$DEST/.remote-list"); n=0
while read -r size path; do
    n=$((n + 1)); local_file="$DEST/${path#./}"
    if [ -f "$local_file" ] && [ "$(stat -c %s "$local_file")" = "$size" ]; then continue; fi
    mkdir -p "$(dirname "$local_file")"
    echo "[$n/$total] $path ($((size / 1048576)) МБ)"
    scp -q -C "mzcorp:/root/$NAME/${path#./}" "$local_file" || echo "  не скачан: $path — запустите ещё раз"
done < "$DEST/.remote-list"

echo "проверка контрольных сумм…"
( cd "$DEST" && sha256sum -c --quiet SHA256SUMS ) && echo "OK: копия цела — $DEST" || echo "есть расхождения — запустите ещё раз"
