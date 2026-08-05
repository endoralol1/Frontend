#!/usr/bin/env bash
# Bring the main Chillflix Next.js app back if it dies or wedges.
# IMPORTANT: under high load a slow health probe looks like "down" — do NOT
# restart in that case (restart storms cause Cloudflare 502s).
set -euo pipefail

LOG_FILE="${CHILLFLIX_WATCHDOG_LOG:-/var/log/chillflix-watchdog.log}"
LOCK_FILE="${CHILLFLIX_WATCHDOG_LOCK:-/tmp/chillflix-watchdog.lock}"
COOLDOWN_SECONDS="${CHILLFLIX_WATCHDOG_COOLDOWN_SECONDS:-180}"
COOLDOWN_FILE="${CHILLFLIX_WATCHDOG_COOLDOWN_FILE:-/tmp/chillflix-watchdog.cooldown}"
HEALTH_URL="${CHILLFLIX_WATCHDOG_URL:-http://127.0.0.1:3000/}"
APP_NAME="${CHILLFLIX_WATCHDOG_APP:-chillflix}"
# Allow slow Next.js under scrape load (was 4s → false 000 + || echo 000 → 000000).
HEALTH_TIMEOUT_SECONDS="${CHILLFLIX_WATCHDOG_HEALTH_TIMEOUT:-12}"
# When 1-minute load exceeds this, skip restarts (site is overloaded, not dead).
LOAD_SKIP_THRESHOLD="${CHILLFLIX_WATCHDOG_LOAD_SKIP:-10}"

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

normalize_health() {
  # curl -w '%{http_code}' prints 000 on timeout, AND `|| echo 000` may append
  # another 000 → "000000". Always collapse to a 3-digit code.
  local code="${1:-}"
  code="$(echo "$code" | tr -d '\r\n' | grep -oE '[0-9]+' | head -1 || true)"
  if [[ "$code" =~ ^[0-9]{3}$ ]]; then
    echo "$code"
  elif [[ "$code" =~ ^0+$ ]]; then
    echo "000"
  else
    echo "000"
  fi
}

probe_health() {
  local raw
  raw="$(curl -sS -m "$HEALTH_TIMEOUT_SECONDS" -o /dev/null -w '%{http_code}' "$HEALTH_URL" 2>/dev/null || true)"
  if [[ -z "$raw" ]]; then
    raw="000"
  fi
  normalize_health "$raw"
}

load1() {
  # First field of /proc/loadavg
  awk '{printf "%.0f", $1}' /proc/loadavg 2>/dev/null || echo 0
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
  local load_now
  load_now="$(load1)"
  if (( load_now >= LOAD_SKIP_THRESHOLD )); then
    log "skip restart ($reason) — load1=${load_now} >= ${LOAD_SKIP_THRESHOLD} (overloaded, not dead)"
    return 0
  fi
  if in_cooldown; then
    log "skip restart ($reason) — cooldown active (${COOLDOWN_SECONDS}s)"
    return 0
  fi
  log "restarting ${APP_NAME} ($reason) load1=${load_now}"
  mark_cooldown
  pm2 reset "$APP_NAME" >/dev/null 2>&1 || true
  pm2 restart "$APP_NAME" --update-env >/dev/null 2>&1 || pm2 start "$APP_NAME" >/dev/null 2>&1 || true
  sleep 3
  local code
  code="$(probe_health)"
  log "after restart health=${code} status=$(pm2_status) load1=$(load1)"
}

status="$(pm2_status)"
health="$(probe_health)"

# PM2 gave up (errored/stopped) or process missing — must restart (unless overloaded).
if [[ "$status" == "errored" || "$status" == "stopped" || "$status" == "missing" ]]; then
  restart_app "pm2_status=${status} health=${health}"
  exit 0
fi

# Online in PM2 but not answering HTTP — wedged / crashed without exit.
if [[ "$health" != "200" && "$health" != "301" && "$health" != "302" && "$health" != "308" ]]; then
  sleep 3
  health2="$(probe_health)"
  if [[ "$health2" != "200" && "$health2" != "301" && "$health2" != "302" && "$health2" != "308" ]]; then
    restart_app "health=${health}->${health2} status=${status}"
  fi
fi
