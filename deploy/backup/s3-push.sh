#!/usr/bin/env bash
# Отправка копий в бакет Yandex Object Storage (вне сервера). Вызывается из nightly-db.sh:
#   bash s3-push.sh <папка ночной копии>
# 1) ночная копия → nightly/<дата>/; 2) новые файлы storage/app mzCorp и QuoteParts → files/<приложение>/.
# Ключ у сервера — роль storage.uploader: удалять он не может; старое стирает жизненный цикл бакета
# (nightly/ через 14 дней, затёртые версии через 14 дней). Ключ вводится s3-set-key.sh.
set -uo pipefail
BUCKET=${S3_BUCKET:-mzcorp-backup}
DAY_DIR=${1:?папка ночной копии}
log() { echo "[$(date '+%F %T')] $*"; }

if ! [ -s /root/.config/rclone/rclone.conf ]; then
    log "S3: ключ не задан (deploy/backup/s3-set-key.sh) — пропускаю"
    exit 0
fi
OPTS=(--transfers 4 --checkers 16 --retries 5 --low-level-retries 20 --stats 0 --stats-log-level NOTICE)
status=0

log "S3: ночная копия $(basename "$DAY_DIR") → $BUCKET/nightly/"
rclone copy "${OPTS[@]}" "$DAY_DIR" "ycs3:$BUCKET/nightly/$(basename "$DAY_DIR")" || { log "S3: ОШИБКА отправки ночной копии"; status=1; }

# Файлы не меняются после записи — сравниваем по размеру, без чтения метаданных каждого объекта.
for app in mzcorp:/var/www/mzcorp quoteparts:/var/www/quoteparts; do
    name=${app%%:*} dir=${app#*:}
    rclone copy "${OPTS[@]}" --size-only --exclude 'private/livewire-tmp/**' \
        "$dir/storage/app" "ycs3:$BUCKET/files/$name/storage/app" || { log "S3: ОШИБКА отправки файлов $name"; status=1; }
done
[ $status = 0 ] && log "S3: готово"
exit $status
