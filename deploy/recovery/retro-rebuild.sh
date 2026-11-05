#!/bin/bash
# Пересборка ретрозагрузки на сервере. Запуск от root:
#   nohup bash deploy/recovery/retro-rebuild.sh MyLift_retro > /var/tmp/retro-rebuild.log 2>&1 &
#
# Дочитывает новые письма, сохраняет загруженные тексты, копирует чистую базу MyLift
# в указанную, собирает заявки, возвращает тексты и докачивает недостающие.
# Письма наружу не отправляет, почту не меняет. Базу, на которую смотрит сайт, сначала отключить.
set -u
TARGET="${1:?Укажите базу: MyLift_retro}"
if [ "$TARGET" = "MyLift" ]; then
  echo "MyLift — чистая копия для пересборок, в неё собирать нельзя без отдельного решения"
  exit 1
fi
cd /var/www/mzcorp
LOG=storage/logs/retro
sudo -u www-data mkdir -p "$LOG"
CACHE=/var/lib/postgresql/retro-bodies.copy
BOXES=$(sudo -u postgres psql -d MyLift -tAc "select email from mailboxes order by id")

echo "=== $(date +%H:%M) сканирование"
for b in $BOXES; do
  sudo -u www-data php artisan retro:scan --mailbox="$b" >> "$LOG/scan-$b.log" 2>&1
  echo "$b: $(grep -vE '^\s*$' "$LOG/scan-$b.log" | tail -n 1 | cut -c1-100)"
done

if sudo -u postgres psql -lqt | cut -d'|' -f1 | grep -qw "$TARGET"; then
  echo "=== $(date +%H:%M) сохраняю тексты"
  sudo -u postgres psql -d "$TARGET" -qc "\copy (select mailbox_id, folder, imap_uid, body_plain, body_html, history_has_attachments, detected_artifacts->'retro'->'attachments' from email_messages where body_plain is not null and imap_uid is not null) to '$CACHE'"
fi

echo "=== $(date +%H:%M) пересборка"
U=$(grep "^DB_USERNAME=" .env | cut -d= -f2 | tr -d '"')
sudo -u postgres psql -qc "DROP DATABASE IF EXISTS \"$TARGET\""
sudo -u postgres psql -qc "CREATE DATABASE \"$TARGET\" OWNER \"$U\""
sudo -u postgres pg_dump -d MyLift --no-owner --no-acl | sudo -u postgres psql -q -d "$TARGET" 2>&1 | grep -E "ERROR" | head -n 5 | cut -c1-140
sudo -u postgres psql -d "$TARGET" -q <<SQL
SELECT format('ALTER TABLE public.%I OWNER TO %I', tablename, '$U') FROM pg_tables WHERE schemaname='public' \gexec
SELECT format('ALTER SEQUENCE public.%I OWNER TO %I', sequence_name, '$U') FROM information_schema.sequences WHERE sequence_schema='public' \gexec
SELECT format('ALTER VIEW public.%I OWNER TO %I', viewname, '$U') FROM pg_views WHERE schemaname='public' \gexec
GRANT ALL ON SCHEMA public TO "$U";
DELETE FROM jobs;
SQL
sudo -u www-data php artisan retro:build --database="$TARGET" 2>&1 | cut -c1-520

if [ -f "$CACHE" ]; then
  echo "=== $(date +%H:%M) возвращаю тексты"
  sudo -u postgres psql -d "$TARGET" -q <<SQL
CREATE TEMP TABLE bodies (mailbox_id bigint, folder varchar(255), imap_uid bigint, body_plain text, body_html text, has boolean, names jsonb);
\copy bodies from '$CACHE'
CREATE INDEX ON bodies (mailbox_id, folder, imap_uid);
UPDATE email_messages e SET body_plain = b.body_plain, body_html = b.body_html, history_has_attachments = b.has,
  detected_artifacts = jsonb_set(e.detected_artifacts, '{retro,attachments}', COALESCE(b.names, '[]'::jsonb))
FROM bodies b WHERE e.mailbox_id = b.mailbox_id AND e.folder = b.folder AND e.imap_uid = b.imap_uid AND e.related_request_id IS NOT NULL;
SQL
fi

echo "=== $(date +%H:%M) докачка текстов"
for b in $BOXES; do
  sudo -u www-data php artisan retro:hydrate --database="$TARGET" --mailbox="$b" >> "$LOG/hydrate-$b.log" 2>&1 &
  sleep 2
done
wait

for scope in documents open; do
  echo "=== $(date +%H:%M) файлы: $scope"
  for b in $BOXES; do
    sudo -u www-data php artisan retro:attachments --database="$TARGET" --scope="$scope" --mailbox="$b" >> "$LOG/attachments-$scope-$b.log" 2>&1 &
    sleep 2
  done
  wait
done
sudo -u www-data php artisan retro:attachments --database="$TARGET" --link-only 2>&1 | tail -n 1
echo "=== $(date +%H:%M) суммы из PDF"
sudo -u www-data php artisan retro:totals --database="$TARGET" 2>&1 | tail -n 12 | cut -c1-80

sudo -u postgres psql -d "$TARGET" -tAc "select status, count(*) from requests group by 1 order by 2 desc"
sudo -u postgres psql -d "$TARGET" -tAc "select document_type, count(*), count(email_attachment_id) from outbound_quotes group by 1"
echo "Задач в очереди: $(sudo -u postgres psql -d "$TARGET" -tAc 'select count(*) from jobs')"
echo "=== $(date +%H:%M) ГОТОВО"
