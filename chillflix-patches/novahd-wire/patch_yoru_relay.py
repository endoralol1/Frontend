#!/usr/bin/env python3
from pathlib import Path

MARKER = '"cinejoy.to",'
INSERT = (
    '"cinejoy.to",\n'
    '  // NovaHD API + edge HLS\n'
    '  "novahd.cc",\n'
    '  "workers.dev",'
)

roots = [Path("/var/www/cinepro/workers/yoru-relay.js")]
roots += list(Path("/var/www").rglob("yoru-relay.js"))
seen = set()
for p in roots:
    try:
        rp = str(p.resolve())
    except Exception:
        continue
    if rp in seen or not p.is_file():
        continue
    seen.add(rp)
    text = p.read_text(encoding="utf-8")
    if "novahd.cc" in text:
        print(f"{rp}: already has novahd")
        continue
    if MARKER not in text:
        print(f"{rp}: no cinejoy marker")
        continue
    p.write_text(text.replace(MARKER, INSERT, 1), encoding="utf-8")
    print(f"{rp}: patched")
