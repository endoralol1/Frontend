#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

set -a
if [[ -f .env.local ]]; then
    # shellcheck disable=SC1091
    . ./.env.local
fi
set +a

export NODE_OPTIONS="--dns-result-order=ipv4first --require ${ROOT}/services/stream-proxy/register.cjs"
export STREAM_PROXY_PORT="${STREAM_PROXY_PORT:-3003}"

if [[ ( "${NODE_ENV:-}" == "production" || "${STREAM_PROXY_PROD:-}" == "1" ) && -f "${ROOT}/dist/stream-proxy/server.cjs" ]]; then
    exec node "${ROOT}/dist/stream-proxy/server.cjs"
fi

exec npx tsx services/stream-proxy/server.ts
