#!/usr/bin/env bash
# Restart stream-proxy when health dies or nginx shows a proxy 502 spike.
# Admin source-checker only hits CinePro :3001; the player needs :3003.
set -euo pipefail

LOG_FILE="${STREAM_PROXY_WATCHDOG_LOG:-/var/log/chillflix-stream-proxy-watchdog.log}"
ACCESS_LOG="${NGINX_ACCESS_LOG:-/var/log/nginx/access.log}"
LOCK_FILE="${STREAM_PROXY_WATCHDOG_LOCK:-/tmp/chillflix-stream-proxy-watchdog.lock}"

log() {
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $*" >>"$LOG_FILE"
}

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

health_code="$(
  curl -sS -m 3 -o /dev/null -w '%{http_code}' http://127.0.0.1:3003/health 2>/dev/null || echo 000
)"

if [[ "$health_code" != "200" ]]; then
  log "health=$health_code — restarting stream-proxy"
  pm2 restart stream-proxy >/dev/null 2>&1 || true
  exit 0
fi

if [[ -f "$ACCESS_LOG" ]]; then
  mapfile -t lines < <(tail -n 400 "$ACCESS_LOG" | grep '/api/cinepro/proxy' || true)
  total="${#lines[@]}"
  if (( total >= 24 )); then
    fails=0
    for line in "${lines[@]}"; do
      code="$(awk '{print $9}' <<<"$line")"
      if [[ "$code" == "502" || "$code" == "503" ]]; then
        fails=$((fails + 1))
      fi
    done
    # Wedged proxy often still answers /health while media fetch returns 502.
    if (( fails * 2 >= total )); then
      log "proxy failure spike fails=$fails total=$total — restarting stream-proxy"
      pm2 restart stream-proxy >/dev/null 2>&1 || true
    fi
  fi
fi
