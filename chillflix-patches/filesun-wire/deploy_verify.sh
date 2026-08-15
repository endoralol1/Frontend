#!/bin/bash
set -euo pipefail
cd /var/www/cinepro

npx tsc --pretty false --module NodeNext --moduleResolution NodeNext --target ES2022 \
  --outDir dist --rootDir src --declaration --sourceMap --skipLibCheck --esModuleInterop --strict \
  src/providers/filesun/filesun.ts

ls -la dist/providers/filesun/

sed -i 's/^CINEPRO_PROVIDER_ALLOWLIST=.*/CINEPRO_PROVIDER_ALLOWLIST=onlyflix,vaplayer,moviebox,flixhqz,cinejoy,novahd,ridomovies,filesun/' .env
grep CINEPRO_PROVIDER_ALLOWLIST .env

# yoru-relay allowlist (for future redeploy)
python3 <<'PY'
from pathlib import Path
hosts = ["filesun.sbs", "embedsun.cc", "vmeas.cloud", "staticmoly.me", "vidmoly.me"]
paths = [
    Path("/var/www/cinepro/workers/yoru-relay.js"),
    Path("/var/www/chillflix-newsite/public/yoru-relay.js"),
    Path("/var/www/chillflix-newsite/workers/yoru-relay.js"),
]
for p in paths:
    if not p.exists():
        continue
    t = p.read_text()
    changed = False
    for host in hosts:
        token = f'"{host}",'
        if token in t:
            continue
        for anchor in ['"ridomovies.su",', '"novahd.cc",', '"cinejoy.to",', '"workers.dev",']:
            if anchor in t:
                t = t.replace(anchor, anchor + f'\n  "{host}",', 1)
                changed = True
                break
    if changed:
        p.write_text(t)
        print(p, "patched")
    else:
        print(p, "unchanged")
PY

python3 /tmp/patch_filesun_newsite.py

mysql -h127.0.0.1 -unewsite -p'c6T3FpoFUD4pXTgcuR8SMy4lYygL' newsite <<'SQL'
INSERT INTO sources (id, name, public_label, enabled, sort_order, notes, updated_at)
VALUES ('filesun', 'FileSuN', 'Tau', 1, 545, 'filesun.sbs embed API', NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), enabled=1, updated_at=NOW();
SELECT id,name,enabled,public_label FROM sources WHERE id IN ('filesun','ridomovies','novahd') ORDER BY id;
SQL

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
print("allowlist", env.get("CINEPRO_PROVIDER_ALLOWLIST"))
print("tmdb_key", "yes" if env.get("TMDB_API_KEY") else "no")
PY

pm2 delete cinepro || true
pm2 start /tmp/cinepro-eco.json
sleep 5
pm2 save

echo "=== probe movie inception ==="
curl -sS "http://127.0.0.1:3001/v1/movies/27205/provider/filesun?probe=true" -o /tmp/filesun-probe.json
python3 - <<'PY'
import json
d=json.load(open("/tmp/filesun-probe.json"))
srcs=d.get("sources") or []
print("probe", len(srcs))
for s in srcs:
    print(" q=", s.get("quality"), "type=", s.get("type"), "url=", (s.get("url") or "")[:100])
for x in d.get("diagnostics") or []:
    print("diag", x)
if d.get("error"):
    print("error", d.get("error"))
PY

echo "=== player ==="
curl -sS "https://vuflix.co/api/player/sources?type=movie&tmdbId=27205&provider=filesun" -o /tmp/filesun-player.json
python3 - <<'PY'
import json
d=json.load(open("/tmp/filesun-player.json"))
srcs=d.get("sources") or []
print("player", d.get("ok"), len(srcs))
for s in srcs:
    print(" q=", s.get("quality"), "label=", s.get("label"), "type=", s.get("type"), "url=", (s.get("url") or "")[:100])
for x in (d.get("diagnostics") or [])[:6]:
    print("diag", x)
PY

echo "=== tv probe got s1e1 ==="
curl -sS "http://127.0.0.1:3001/v1/tv/1399/seasons/1/episodes/1/provider/filesun?probe=true" -o /tmp/filesun-tv.json
python3 - <<'PY'
import json
d=json.load(open("/tmp/filesun-tv.json"))
print("tv", len(d.get("sources") or []), d.get("diagnostics"))
for s in (d.get("sources") or [])[:2]:
    print((s.get("url") or "")[:100])
PY
