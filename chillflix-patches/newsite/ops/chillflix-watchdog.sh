#!/usr/bin/env bash
# Bring the main Chillflix Next.js app back if it dies or wedges.
# Mirrors stream-proxy-watchdog: health probe + cooldown + flock.
set -euo pipefail

LOG_FILE="${CHILLFLIX_WATCHDOG_LOG:-/var/log/chillflix-watchdog.log}"
LOCK_FILE="${CHILLFLIX_WATCHDOG_LOCK:-/tmp/chillflix-watchdog.lock}"
COOLDOWN_SECONDS="${CHILLFLIX_WATCHDOG_COOLDOWN_SECONDS:-120}"
COOLDOWN_FILE="${CHILLFLIX_WATCHDOG_COOLDOWN_FILE:-/tmp/chillflix-watchdog.cooldown}"
HEALTH_URL="${CHILLFLIX_WATCHDOG_URL:-http://127.0.0.1:3000/}"
APP_NAME="${CHILLFLIX_WATCHDOG_APP:-chillflix}"

log() {
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $*" >>"$LOG_FILE"
}

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

in_cooldown() {
  if [[ ! -f "$COOLDOWN_FILE" ]]; then
    return 1
  fi
  local last now age
  last="$(cat "$COOLDOWN_FILE" 2>/dev/null || echo 0)"
  now="$(date +%s)"
  age=$((now - last))
  (( age < COOLDOWN_SECONDS ))
}

mark_cooldown() {
  date +%s >"$COOLDOWN_FILE"
}

pm2_status() {
  pm2 jlist 2>/dev/null | python3 -c '
import json,sys
try:
  data=json.load(sys.stdin)
except Exception:
  print("unknown"); raise SystemExit(0)
for p in data:
  if p.get("name")=="'"$APP_NAME"'":
    print(p.get("pm2_env",{}).get("status","missing"))
    raise SystemExit(0)
print("missing")
' 2>/dev/null || echo "unknown"
}

restart_app() {
  local reason="$1"
  if in_cooldown; then
    log "skip restart ($reason) — cooldown active (${COOLDOWN_SECONDS}s)"
    return 0
  fi
  log "restarting ${APP_NAME} ($reason)"
  mark_cooldown
  # Reset PM2 "errored" stop-state, then ensure process is up.
  pm2 reset "$APP_NAME" >/dev/null 2>&1 || true
  pm2 restart "$APP_NAME" --update-env >/dev/null 2>&1 || pm2 start "$APP_NAME" >/dev/null 2>&1 || true
  sleep 2
  local code
  code="$(curl -sS -m 5 -o /dev/null -w '%{http_code}' "$HEALTH_URL" 2>/dev/null || echo 000)"
  log "after restart health=${code} status=$(pm2_status)"
}

status="$(pm2_status)"
health="$(curl -sS -m 4 -o /dev/null -w '%{http_code}' "$HEALTH_URL" 2>/dev/null || echo 000)"

# PM2 gave up (errored/stopped) or process missing — must restart.
if [[ "$status" == "errored" || "$status" == "stopped" || "$status" == "missing" ]]; then
  restart_app "pm2_status=${status} health=${health}"
  exit 0
fi

# Online in PM2 but not answering HTTP — wedged / crashed without exit.
if [[ "$health" != "200" && "$health" != "301" && "$health" != "302" && "$health" != "308" ]]; then
  # One quick recheck to avoid flapping on a single slow request.
  sleep 2
  health2="$(curl -sS -m 4 -o /dev/null -w '%{http_code}' "$HEALTH_URL" 2>/dev/null || echo 000)"
  if [[ "$health2" != "200" && "$health2" != "301" && "$health2" != "302" && "$health2" != "308" ]]; then
    restart_app "health=${health}->${health2} status=${status}"
  fi
fi
