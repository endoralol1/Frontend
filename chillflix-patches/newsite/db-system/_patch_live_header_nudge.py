#!/usr/bin/env python3
"""Tiny extra Live TV header clearance + force cache-bust."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
css_path = root / "public/assets/css/live.css"
routes = root / "app/routes.php"
live_php = root / "app/Views/pages/live.php"
UI = "20260803-ui161"

css = css_path.read_text()
replacements = [
    ("padding: 3.85rem var(--live-gutter) 5.5rem;", "padding: 4.05rem var(--live-gutter) 5.5rem;"),
    ("padding: 3.55rem var(--live-gutter) 5.5rem;", "padding: 4.05rem var(--live-gutter) 5.5rem;"),
    ("padding-top: 3.75rem;", "padding-top: 3.95rem;"),
    ("padding-top: 3.45rem;", "padding-top: 3.95rem;"),
    ("padding-top: 4.15rem;", "padding-top: 4.35rem;"),
    ("padding-top: 3.9rem;", "padding-top: 4.35rem;"),
]
changed = False
for a, b in replacements:
    if a in css:
        css = css.replace(a, b)
        changed = True
        print("css", a, "->", b)
if "padding: 4.05rem" not in css:
    raise SystemExit("failed to set base padding")
if "padding-top: 3.95rem" not in css:
    raise SystemExit("failed to set mobile padding")
css_path.write_text(css)
print("css written", changed)

for p in (routes, live_php):
    s = p.read_text()
    s2 = re.sub(r"\$liveAssetV = '[^']*'", f"$liveAssetV = '{UI}'", s)
    s2 = re.sub(r"\$assetV = '[^']*'", f"$assetV = '{UI}'", s2)
    if s2 == s:
        raise SystemExit(f"version not updated in {p}")
    p.write_text(s2)
    print("bumped", p.name)

print("OK", UI)
