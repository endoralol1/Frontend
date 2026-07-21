#!/usr/bin/env bash
# Restart stream-proxy only when it is truly dead/wedged.
# NOTE: a restart briefly interrupts ALL viewers on the proxy (~1–3s blip),
# so we use a long cooldown and a high failure bar — not every spike.
set -euo pipefail

LOG_FILE="${STREAM_PROXY_WATCHDOG_LOG:-/var/log/chillflix-stream-proxy-watchdog.log}"
ACCESS_LOG="${NGINX_ACCESS_LOG:-/var/log/nginx/access.log}"
LOCK_FILE="${STREAM_PROXY_WATCHDOG_LOCK:-/tmp/chillflix-stream-proxy-watchdog.lock}"
# Don't restart more than once per 15 minutes (protects ~50 concurrent viewers).
COOLDOWN_SECONDS="${STREAM_PROXY_WATCHDOG_COOLDOWN_SECONDS:-900}"
COOLDOWN_FILE="${STREAM_PROXY_WATCHDOG_COOLDOWN_FILE:-/tmp/chillflix-stream-proxy-watchdog.cooldown}"

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

restart_proxy() {
  local reason="$1"
  if in_cooldown; then
    log "skip restart ($reason) — cooldown active (${COOLDOWN_SECONDS}s)"
    return 0
  fi
  log "restarting stream-proxy ($reason) — brief blip for all active viewers"
  mark_cooldown
  # Soft reload path isn't available for this Node proxy; restart is required.
  pm2 restart stream-proxy >/dev/null 2>&1 || true
}

health_code="$(
  curl -sS -m 3 -o /dev/null -w '%{http_code}' http://127.0.0.1:3003/health 2>/dev/null || echo 000
)"

# Process dead / not answering: must restart (streams already broken for everyone).
if [[ "$health_code" != "200" ]]; then
  restart_proxy "health=$health_code"
  exit 0
fi

# Wedged-but-alive: only if a large recent sample is mostly 502/503.
# With 50+ users this sample fills quickly; require sustained failure, not a blip.
if [[ -f "$ACCESS_LOG" ]]; then
  mapfile -t lines < <(tail -n 800 "$ACCESS_LOG" | grep '/api/cinepro/proxy' || true)
  total="${#lines[@]}"
  if (( total >= 80 )); then
    fails=0
    for line in "${lines[@]}"; do
      code="$(awk '{print $9}' <<<"$line")"
      if [[ "$code" == "502" || "$code" == "503" ]]; then
        fails=$((fails + 1))
      fi
    done
    # >=75% failures over a fat sample ≈ truly wedged for everyone already.
    if (( fails * 4 >= total * 3 )); then
      restart_proxy "proxy failure spike fails=$fails total=$total"
    fi
  fi
fi
