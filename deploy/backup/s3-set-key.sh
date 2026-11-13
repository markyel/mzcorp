#!/usr/bin/env bash
# Ввод ключа доступа к бакету копий (Yandex Object Storage). Запускает владелец, ключ в чат не попадает:
#   ssh -t mzcorp 'bash /var/www/mzcorp/deploy/backup/s3-set-key.sh'
# Пишет /root/.config/rclone/rclone.conf (только root) и проверяет запись пробным файлом в nightly/.
set -euo pipefail
BUCKET=${S3_BUCKET:-mzcorp-backup}
CONF=/root/.config/rclone/rclone.conf

read -r -p "Идентификатор ключа (YCA…): " key_id
read -r -s -p "Секретный ключ (не отображается): " secret; echo
[ -n "$key_id" ] && [ -n "$secret" ] || { echo "пусто — ничего не записано"; exit 1; }

umask 077
mkdir -p "$(dirname "$CONF")"
cat > "$CONF" <<CONF
[ycs3]
type = s3
provider = Other
endpoint = https://storage.yandexcloud.net
region = ru-central1
access_key_id = $key_id
secret_access_key = $secret
no_check_bucket = true
CONF
unset secret

probe=$(mktemp); date > "$probe"
if rclone copyto "$probe" "ycs3:$BUCKET/nightly/.probe-$(date +%F-%H%M%S)" && rclone lsf "ycs3:$BUCKET/nightly/" > /dev/null; then
    echo "OK: ключ сохранён, бакет $BUCKET доступен на запись"
else
    echo "ОШИБКА: бакет $BUCKET не принял запись — проверьте ключ и роль storage.uploader, запустите ещё раз"
fi
rm -f "$probe"
