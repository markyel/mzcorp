#!/usr/bin/env bash
# Скачать готовую копию с сервера на этот компьютер (Git Bash).
#   bash pull-backup.sh backup-2026-11-02-1530
# Докачивает с места обрыва (до 5 попыток на файл), файлы того же размера пропускает.
# Битые по контрольной сумме файлы удаляет и качает заново один раз. Целая копия получает
# метку .verified — по ней pull-nightly.sh понимает, что день забран.
set -uo pipefail
NAME=${1:?укажите папку копии на сервере, например backup-2026-11-02-1530}
DEST=${DEST:-/c/Users/Boag/mzcorp-backups}/$NAME
TRIES=${TRIES:-5}
mkdir -p "$DEST"
rm -f "$DEST/.verified"

size_of() { [ -f "$1" ] && stat -c %s "$1" || echo 0; }

fetch() {  # fetch <путь на сервере> <локальный файл> <размер>: докачка хвоста через ssh
    local remote=$1 file=$2 size=$3 have try
    for try in $(seq "$TRIES"); do
        have=$(size_of "$file")
        [ "$have" = "$size" ] && return 0
        if [ "$have" -gt "$size" ]; then rm -f "$file"; have=0; fi
        ssh -n -o BatchMode=yes -o ServerAliveInterval=30 -o Compression=no mzcorp \
            "tail -c +$((have + 1)) '$remote'" >> "$file"
        [ "$(size_of "$file")" = "$size" ] && return 0
        echo "  обрыв на $(( $(size_of "$file") / 1048576 )) МБ (попытка $try из $TRIES)"
        sleep 20
    done
    return 1
}

download() {
    local total n=0 failed=0 size path file
    total=$(wc -l < "$DEST/.remote-list")
    while read -r size path; do
        n=$((n + 1)); file="$DEST/${path#./}"
        [ "$(size_of "$file")" = "$size" ] && continue
        mkdir -p "$(dirname "$file")"
        echo "[$n/$total] $path ($((size / 1048576)) МБ)"
        fetch "/root/$NAME/${path#./}" "$file" "$size" || { echo "  не скачан: $path"; failed=1; }
    done < "$DEST/.remote-list"
    return $failed
}

verify() { ( cd "$DEST" && sha256sum -c --quiet SHA256SUMS ) 2>&1; }

ssh -n mzcorp "cd /root/$NAME && find . -type f ! -name '.done-*' -printf '%s %p\n'" > "$DEST/.remote-list" || { echo "сервер не отвечает"; exit 1; }

for round in 1 2; do
    download
    echo "проверка контрольных сумм…"
    if report=$(verify); then
        touch "$DEST/.verified"
        echo "OK: копия цела — $DEST"
        exit 0
    fi
    echo "$report" | grep -v '^sha256sum: WARNING'
    [ "$round" = 2 ] && break
    # битые файлы — удалить и скачать заново
    echo "$report" | sed -n 's/^\(.*\): FAILED.*$/\1/p' | while read -r bad; do rm -f "$DEST/$bad"; done
done
echo "есть расхождения — запустите ещё раз"
exit 1
