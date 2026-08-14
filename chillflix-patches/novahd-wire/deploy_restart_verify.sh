#!/bin/bash
set -euo pipefail
cd /var/www/cinepro

python3 <<'PY'
import json
from pathlib import Path

env = {"NODE_ENV": "production"}
for line in Path("/var/www/cinepro/.env").read_text().splitlines():
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    k, v = line.split("=", 1)
    env[k.strip()] = v.strip().strip("\"'")

eco = {
    "apps": [
        {
            "name": "cinepro",
            "cwd": "/var/www/cinepro",
            "script": "dist/server.js",
            "env": env,
        }
    ]
}
Path("/tmp/cinepro-eco.json").write_text(json.dumps(eco))
print("allowlist=", env.get("CINEPRO_PROVIDER_ALLOWLIST"))
PY

pm2 delete cinepro || true
pm2 start /tmp/cinepro-eco.json
sleep 3
pm2 save

echo "=== process allowlist ==="
PID=$(pm2 pid cinepro)
tr '\0' '\n' < /proc/$PID/environ | grep CINEPRO_PROVIDER_ALLOWLIST || true

echo "=== probe ==="
curl -sS "http://127.0.0.1:3001/v1/movies/550/provider/novahd?probe=true" > /tmp/novahd-probe.json
python3 - <<'PY'
import json
d=json.load(open("/tmp/novahd-probe.json"))
srcs=d.get("sources") or []
print("count", len(srcs))
if srcs:
    print("type", srcs[0].get("type"), "quality", srcs[0].get("quality"))
    print("url_host", srcs[0].get("url","")[:80])
else:
    print(json.dumps(d)[:500])
PY

echo "=== player api ==="
curl -sS "https://vuflix.co/api/player/sources?type=movie&tmdbId=550&provider=novahd" > /tmp/novahd-player.json
python3 - <<'PY'
import json
d=json.load(open("/tmp/novahd-player.json"))
print("ok", d.get("ok"), "keys", list(d.keys())[:12])
srcs=d.get("sources") or []
print("sources", len(srcs))
for s in srcs[:3]:
    print("-", s.get("provider"), s.get("quality"), s.get("type"), (s.get("url") or "")[:90])
diags=d.get("diagnostics") or []
for x in diags[:5]:
    print("diag", x)
PY

echo "=== tv probe got ==="
curl -sS "http://127.0.0.1:3001/v1/tv/1399/seasons/1/episodes/1/provider/novahd?probe=true" > /tmp/novahd-tv.json
python3 - <<'PY'
import json
d=json.load(open("/tmp/novahd-tv.json"))
print("tv sources", len(d.get("sources") or []))
PY
