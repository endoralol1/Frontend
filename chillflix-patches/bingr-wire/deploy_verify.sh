#!/bin/bash
set -euo pipefail
cd /var/www/cinepro

mkdir -p src/providers/bingr dist/providers/bingr
cp -f /tmp/bingr.ts src/providers/bingr/bingr.ts

npx tsc --pretty false --module NodeNext --moduleResolution NodeNext --target ES2022 \
  --outDir dist --rootDir src --declaration --sourceMap --skipLibCheck --esModuleInterop --strict \
  src/providers/bingr/bingr.ts

ls -la dist/providers/bingr/

sed -i 's/^CINEPRO_PROVIDER_ALLOWLIST=.*/CINEPRO_PROVIDER_ALLOWLIST=onlyflix,vaplayer,moviebox,flixhqz,cinejoy,novahd,ridomovies,filesun,bingr/' .env
grep CINEPRO_PROVIDER_ALLOWLIST .env

# yoru-relay allowlist (for future redeploy)
python3 <<'PY'
from pathlib import Path
hosts = [
    "bingr.one",
    "api.bingr.one",
    "hdghartv.cc",
    "streamraiwind.stream",
    "wormhole.filmu.in",
    "filmu.in",
]
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
        for anchor in ['"filesun.sbs",', '"embedsun.cc",', '"ridomovies.su",', '"novahd.cc",', '"cinejoy.to",', '"workers.dev",']:
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

python3 /tmp/patch_bingr_newsite.py

mysql -h127.0.0.1 -unewsite -p'c6T3FpoFUD4pXTgcuR8SMy4lYygL' newsite <<'SQL'
INSERT INTO sources (id, name, public_label, enabled, sort_order, notes, updated_at)
VALUES ('bingr', 'Bingr', 'Upsilon', 1, 555, 'api.bingr.one/stream scrapers', NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), enabled=1, public_label=VALUES(public_label), updated_at=NOW();
SELECT id,name,enabled,public_label,sort_order FROM sources WHERE id IN ('bingr','filesun','ridomovies','novahd') ORDER BY sort_order,id;
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
PY

pm2 delete cinepro || true
pm2 start /tmp/cinepro-eco.json
sleep 5
pm2 save

echo "=== probe movie inception ==="
curl -sS "http://127.0.0.1:3001/v1/movies/27205/provider/bingr?probe=true&fresh=true" -o /tmp/bingr-probe.json
python3 - <<'PY'
import json
d=json.load(open("/tmp/bingr-probe.json"))
srcs=d.get("sources") or []
print("probe", len(srcs))
for s in srcs:
    print(" q=", s.get("quality"), "type=", s.get("type"), "url=", (s.get("url") or "")[:140])
for x in d.get("diagnostics") or []:
    print("diag", x)
if d.get("error"):
    print("error", d.get("error"))
PY

echo "=== player ==="
curl -sS "https://vuflix.co/api/player/sources?type=movie&tmdbId=27205&provider=bingr" -o /tmp/bingr-player.json
python3 - <<'PY'
import json
d=json.load(open("/tmp/bingr-player.json"))
srcs=d.get("sources") or []
print("player", d.get("ok"), len(srcs))
for s in srcs:
    print(" q=", s.get("quality"), "label=", s.get("label"), "type=", s.get("type"), "url=", (s.get("url") or "")[:140])
for x in (d.get("diagnostics") or [])[:6]:
    print("diag", x)
PY

echo "=== tv probe breaking bad s1e1 ==="
curl -sS "http://127.0.0.1:3001/v1/tv/1396/seasons/1/episodes/1/provider/bingr?probe=true&fresh=true" -o /tmp/bingr-tv.json
python3 - <<'PY'
import json
d=json.load(open("/tmp/bingr-tv.json"))
print("tv", len(d.get("sources") or []), d.get("diagnostics"))
for s in (d.get("sources") or [])[:2]:
    print((s.get("url") or "")[:140], s.get("quality"))
PY
