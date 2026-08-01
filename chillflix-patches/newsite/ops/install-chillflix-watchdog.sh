#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-/var/www/chillflix.lol}"
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
DEST="${ROOT}/scripts/chillflix-watchdog.sh"
CRON_LINE='* * * * * /bin/bash /var/www/chillflix.lol/scripts/chillflix-watchdog.sh'

install -m 0755 "${SRC_DIR}/chillflix-watchdog.sh" "$DEST"
touch /var/log/chillflix-watchdog.log
chmod 644 /var/log/chillflix-watchdog.log

pm2 set pm2:autodump true >/dev/null 2>&1 || true
pm2 save >/dev/null 2>&1 || true

existing="$(crontab -l 2>/dev/null || true)"
if ! grep -Fq 'chillflix-watchdog.sh' <<<"$existing"; then
  printf '%s\n%s\n' "$existing" "$CRON_LINE" | crontab -
  echo "cron installed"
else
  echo "cron already present"
fi

echo "installed $DEST"
echo "manual test: $DEST && tail -n 5 /var/log/chillflix-watchdog.log"
