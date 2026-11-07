#!/usr/bin/env bash
#
# Режим работы mzCorp и QuoteParts: «наблюдение» ↔ «полный». Запуск на сервере от root.
#
#   bash /var/www/mzcorp/deploy/ops/mode.sh status          # что сейчас и чем отличается от режимов
#   bash /var/www/mzcorp/deploy/ops/mode.sh full            # показать, что изменится, + пробные прогоны
#   bash /var/www/mzcorp/deploy/ops/mode.sh full --apply    # переключить
#   bash /var/www/mzcorp/deploy/ops/mode.sh observe --apply # вернуть наблюдение
#
# Наблюдение (с 05.11.2026, перезапуск после аварии): система читает почту и решает, но заявки не
# раздаёт (только подсказывает менеджера), в ящики не пишет, служебных писем сотрудникам не шлёт,
# напоминаний поставщикам нет, 19 задач расписания выключены.
# Полный (решение заказчика 07.11.2026): всё включено, как до аварии: раздача, запись в ящики,
# служебные письма, поставщики, контроль заявок, автозакрытие и сроки счетов, доставка истории
# в личные ящики, очистка raw_source. Шаблоны писем клиентам переключатель НЕ трогает — они
# включаются по одному в настройках.
#
# Меняются только ключи из профилей ниже; перед записью .env копируется в $BACKUP_DIR. Файл
# переписывается на месте (владелец и права сохраняются). После записи — queue:restart mzCorp,
# config:cache и перезапуск воркера QuoteParts. Каждое переключение — строка в $LOG.
# Переменные окружения MZ_APP, QP_APP, BACKUP_DIR, LOG, SKIP_ARTISAN=1 — для проверки на копиях.
set -euo pipefail

MZ_APP=${MZ_APP:-/var/www/mzcorp}
QP_APP=${QP_APP:-/var/www/quoteparts}
BACKUP_DIR=${BACKUP_DIR:-/root/backups/env}
LOG=${LOG:-/var/log/mzcorp-mode.log}
SKIP_ARTISAN=${SKIP_ARTISAN:-0}

# Задачи расписания, выключенные в наблюдении (config/app.php schedule_disabled, ScheduledTaskName).
OBSERVE_SCHEDULE_DISABLED='notifications:dispatch-client,quotes:complete-partial,suppliers:remind,procurement:price-monitors,reports:weekly-generate,support:email-pending-replies,bot:chat-digest,media:autopilot,iqot:dispatch,iqot:monitoring,quotes:cost-plus-recheck,clients:extract-requisites,requests:auto-close-inactive,requests:reassess-stalled,requests:recover-unassigned,requests:check-attention,invoices:check-expiry,mail:prune-raw-source,mail:backfill-manager-deliveries'

# Ключи mzCorp: имя|наблюдение|полный
MZ_KEYS=(
    "ASSIGNMENT_OBSERVE|true|false"
    "MAIL_IMAP_WRITES|false|true"
    "MAIL_SYSTEM_NOTIFICATIONS|false|true"
    "SUPPLIER_REMINDER_ENABLED|false|true"
    "SCHEDULE_DISABLED|${OBSERVE_SCHEDULE_DISABLED}|"
)
# Ключи QuoteParts: напоминания поставщикам и закрытие запросов по сроку.
QP_KEYS=(
    "QP_RFQ_TICK|false|true"
)

usage() {
    sed -n '3,9p' "$0" | sed 's/^# \{0,1\}//'
    exit 2
}

env_get() { # файл ключ → значение (без кавычек) или пусто; «__unset__», если ключа нет
    local v
    if ! v=$(grep -E "^$2=" "$1" | tail -1); then
        echo "__unset__"
        return
    fi
    v=${v#*=}
    v=${v#\"}
    v=${v%\"}
    echo "$v"
}

env_set() { # файл ключ значение — заменить строку ключа или дописать в конец; файл на месте
    local file=$1 key=$2 value=$3 tmp
    tmp=$(mktemp)
    awk -v k="$key" -v v="$value" '
        BEGIN { done = 0 }
        index($0, k "=") == 1 { if (!done) { print k "=" v; done = 1 }; next }
        { print }
        END { if (!done) print k "=" v }
    ' "$file" >"$tmp"
    # Запись в тот же файл: владелец (www-data / quoteparts) и права 640 сохраняются.
    cat "$tmp" >"$file"
    rm -f "$tmp"
}

value_for() { # "имя|наблюдение|полный" режим → значение
    local spec=$1 mode=$2 rest
    rest=${spec#*|}
    if [ "$mode" = observe ]; then echo "${rest%%|*}"; else echo "${rest#*|}"; fi
}

short() { # длинные значения (список задач) — коротко
    local v=$1
    if [ "$v" = "__unset__" ]; then echo "(нет ключа)"; return; fi
    if [ -z "$v" ]; then echo "(пусто)"; return; fi
    if [ ${#v} -gt 40 ]; then echo "$(tr ',' '\n' <<<"$v" | grep -c .) задач(и)"; return; fi
    echo "$v"
}

# Печатает таблицу различий для режима; возвращает число ключей, которые нужно поменять.
diff_for() {
    local mode=$1 changes=0 spec key want have
    printf '%-28s %-22s %-22s\n' "Ключ" "Сейчас" "$( [ "$mode" = observe ] && echo Наблюдение || echo Полный )"
    for spec in "${MZ_KEYS[@]}"; do
        key=${spec%%|*}; want=$(value_for "$spec" "$mode"); have=$(env_get "$MZ_APP/.env" "$key")
        [ "$have" = "$want" ] && mark=' ' || { mark='*'; changes=$((changes + 1)); }
        printf '%s %-26s %-22s %-22s\n' "$mark" "mzCorp $key" "$(short "$have")" "$(short "$want")"
    done
    for spec in "${QP_KEYS[@]}"; do
        key=${spec%%|*}; want=$(value_for "$spec" "$mode"); have=$(env_get "$QP_APP/.env" "$key")
        [ "$have" = "$want" ] && mark=' ' || { mark='*'; changes=$((changes + 1)); }
        printf '%s %-26s %-22s %-22s\n' "$mark" "QuoteParts $key" "$(short "$have")" "$(short "$want")"
    done
    return $changes
}

current_mode() {
    local m
    for m in observe full; do
        if diff_for "$m" >/dev/null; then echo "$m"; return; fi
    done
    echo "смешанный"
}

mode_label() { case $1 in observe) echo "наблюдение" ;; full) echo "полный" ;; *) echo "$1" ;; esac; }

artisan_mz() { [ "$SKIP_ARTISAN" = 1 ] && { echo "  (пропущено) php artisan $*"; return; }; sudo -u www-data php "$MZ_APP/artisan" "$@"; }
artisan_qp() { [ "$SKIP_ARTISAN" = 1 ] && { echo "  (пропущено) php artisan $*"; return; }; sudo -u quoteparts php "$QP_APP/artisan" "$@"; }

# Что сделают включаемые задачи прямо сейчас — их собственные пробные прогоны, без изменений.
preflight() {
    [ "$SKIP_ARTISAN" = 1 ] && { echo "(пробные прогоны пропущены)"; return; }
    echo
    echo "Пробные прогоны задач, которые включатся (ничего не меняют):"
    local c
    for c in "requests:auto-close-inactive --dry-run" "invoices:check-expiry --dry-run" "requests:recover-unassigned --dry-run" \
        "requests:check-attention --dry-run" "mail:backfill-manager-deliveries --dry-run" "quotes:complete-partial --dry-run"; do
        echo "  — $c"
        # shellcheck disable=SC2086
        (cd "$MZ_APP" && timeout 180 sudo -u www-data php artisan $c 2>&1 | grep -vE '^\s*[|+]' | grep -E 'Закрыто|Нашли|Найдено|overdue|Готово|Сухой|ничего' | tail -2 | sed 's/^/      /') || true
    done
}

apply() {
    local mode=$1 stamp spec key want
    [ "$(id -u)" = 0 ] || [ "$SKIP_ARTISAN" = 1 ] || { echo "Запускать от root." >&2; exit 1; }
    stamp=$(date +%Y%m%d-%H%M%S)
    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"
    cp -p "$MZ_APP/.env" "$BACKUP_DIR/mzcorp.env.$stamp"
    cp -p "$QP_APP/.env" "$BACKUP_DIR/quoteparts.env.$stamp"
    echo "Копии .env: $BACKUP_DIR/{mzcorp,quoteparts}.env.$stamp"

    for spec in "${MZ_KEYS[@]}"; do
        key=${spec%%|*}; want=$(value_for "$spec" "$mode")
        [ "$(env_get "$MZ_APP/.env" "$key")" = "$want" ] || env_set "$MZ_APP/.env" "$key" "$want"
    done
    for spec in "${QP_KEYS[@]}"; do
        key=${spec%%|*}; want=$(value_for "$spec" "$mode")
        [ "$(env_get "$QP_APP/.env" "$key")" = "$want" ] || env_set "$QP_APP/.env" "$key" "$want"
    done

    # mzCorp: конфиг не кэширован (сайт и cron читают .env сразу); если кэш есть — пересобрать.
    if [ -f "$MZ_APP/bootstrap/cache/config.php" ]; then artisan_mz config:cache; fi
    artisan_mz queue:restart
    # QuoteParts: конфиг кэширован — без config:cache изменение не подействует.
    artisan_qp config:cache
    if [ "$SKIP_ARTISAN" != 1 ]; then supervisorctl restart 'quoteparts-worker:*' >/dev/null; fi

    echo "[$(date '+%F %T')] режим: $(mode_label "$mode") · ${SUDO_USER:-$(whoami)} · копии $stamp" >>"$LOG"
}

[ $# -ge 1 ] || usage
cmd=$1
shift
apply_flag=0
for a in "$@"; do
    case $a in
        --apply) apply_flag=1 ;;
        *) usage ;;
    esac
done

case $cmd in
    status)
        echo "Сейчас: $(mode_label "$(current_mode)")"
        echo
        diff_for full || true
        echo
        echo "* — отличается от полного режима. Последние переключения:"
        tail -n 5 "$LOG" 2>/dev/null || echo "  (не было)"
        ;;
    observe | full)
        set +e
        diff_for "$cmd"
        changes=$?
        set -e
        if [ "$changes" -eq 0 ]; then
            echo
            echo "Уже режим «$(mode_label "$cmd")» — менять нечего."
            exit 0
        fi
        if [ "$apply_flag" -ne 1 ]; then
            [ "$cmd" = full ] && preflight
            echo
            echo "Изменится ключей: $changes. Переключить: $0 $cmd --apply"
            exit 0
        fi
        apply "$cmd"
        echo
        echo "Готово: режим «$(mode_label "$cmd")»."
        [ "$cmd" = full ] && echo "Шаблоны писем клиентам не тронуты — включаются в настройках по одному."
        ;;
    *) usage ;;
esac
