#!/bin/bash
# Read-only health check of prod (mzCorp + QuoteParts) from a workstation with the `ssh mzcorp` alias.
# Usage: bash deploy/ops/health/health.sh [minutes]   (default 30). Changes nothing on the server.
M=${1:-30}
SP=$(dirname "$0")
scp -q "$SP/mz.php" mzcorp:/tmp/health_mz.php && scp -q "$SP/qp.php" mzcorp:/tmp/health_qp.php || exit 1
ssh mzcorp "M=$M bash -s" <<'REMOTE'
chmod 644 /tmp/health_*.php
bash /var/www/mzcorp/deploy/ops/mode.sh status 2>&1 | grep -E '^\*|mzCorp|QuoteParts' | head -7
echo "== workers"; supervisorctl status | awk '$2!="RUNNING" && $2!="STARTING"{bad=1; print "NOT RUNNING:", $0} END{if(!bad) print "all RUNNING"}'
cd /var/www/mzcorp && sudo -u www-data php /tmp/health_mz.php $M 2>&1 | cut -c1-600
cd /var/www/quoteparts && sudo -u quoteparts php /tmp/health_qp.php $M 2>&1 | cut -c1-400
SINCE_mzcorp=$(date -d "-$M min" "+%Y-%m-%d %H:%M:%S"); SINCE_quoteparts=$(date -u -d "-$M min" "+%Y-%m-%d %H:%M:%S")
for app in mzcorp quoteparts; do if [ "$app" = mzcorp ]; then SINCE=$SINCE_mzcorp; else SINCE=$SINCE_quoteparts; fi
  echo "== $app log ERROR/CRITICAL since $SINCE (top messages)"
  f=/var/www/$app/storage/logs/laravel.log
  awk -v s="[$SINCE]" 'substr($0,1,21) >= s' "$f" | grep -E '^\[[0-9-]+ [0-9:]+\] production\.(ERROR|CRITICAL)' \
    | sed -E 's/^\[[^]]+\] production\.[A-Z]+: //; s/[0-9]{3,}/N/g; s/sc=[A-Za-z0-9_-]+/sc=…/' | cut -c1-140 | sort | uniq -c | sort -rn | head -8
  echo "  warnings: $(awk -v s="[$SINCE]" 'substr($0,1,21) >= s' "$f" | grep -c 'production.WARNING')"
done
rm -f /tmp/health_*.php
REMOTE
