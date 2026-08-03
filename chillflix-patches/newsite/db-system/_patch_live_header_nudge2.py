#!/usr/bin/env python3
"""Tiny extra Live TV header clearance + cache-bust."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
css_path = root / "public/assets/css/live.css"
routes = root / "app/routes.php"
live_php = root / "app/Views/pages/live.php"
UI = "20260803-ui162"

css = css_path.read_text()
reps = [
    ("padding: 4.05rem var(--live-gutter) 5.5rem;", "padding: 4.25rem var(--live-gutter) 5.5rem;"),
    ("padding-top: 3.95rem;", "padding-top: 4.15rem;"),
    ("padding-top: 4.35rem;", "padding-top: 4.55rem;"),
]
ok = False
for a, b in reps:
    if a in css:
        css = css.replace(a, b)
        print(a, "->", b)
        ok = True
if not ok:
    raise SystemExit("padding values not found")
css_path.write_text(css)

for p in (routes, live_php):
    s = p.read_text()
    s2 = re.sub(r"\$liveAssetV = '[^']*'", f"$liveAssetV = '{UI}'", s)
    s2 = re.sub(r"\$assetV = '[^']*'", f"$assetV = '{UI}'", s2)
    if s2 == s:
        raise SystemExit(f"version not updated in {p.name}")
    p.write_text(s2)
    print("bumped", p.name)

assert "4.25rem" in css_path.read_text()
assert UI in routes.read_text()
print("OK", UI)
