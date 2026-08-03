#!/usr/bin/env python3
"""Clear absolute header overlap on Live TV page — push content down."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
live_css = root / "public/assets/css/live.css"
routes = root / "app/routes.php"
live_php = root / "app/Views/pages/live.php"
UI = "20260803-ui160"

css = live_css.read_text()

old = """.live-p {
  --live-bg: #0b0d12;
  --live-panel: #141820;
  --live-line: rgba(255, 255, 255, 0.08);
  --live-text: #f2f4f7;
  --live-muted: #9aa3b2;
  --live-accent: #db6937;
  --live-accent-2: #c43c2e;
  --live-gutter: 16px;
  padding: 1rem var(--live-gutter) 5.5rem;
  color: var(--live-text);
}"""

new = """.live-p {
  --live-bg: #0b0d12;
  --live-panel: #141820;
  --live-line: rgba(255, 255, 255, 0.08);
  --live-text: #f2f4f7;
  --live-muted: #9aa3b2;
  --live-accent: #db6937;
  --live-accent-2: #c43c2e;
  --live-gutter: 16px;
  /* Clear absolute site header (logo / EN / admin) */
  padding: 3.85rem var(--live-gutter) 5.5rem;
  color: var(--live-text);
}"""

if "padding: 3.85rem var(--live-gutter)" in css:
    print("base clearance exists")
elif old in css:
    css = css.replace(old, new, 1)
    print("base padding-top clearance")
else:
    raise SystemExit("live-p block not found")

old_m = """  .live-p {
    --live-gutter: 16px;
    padding-top: 0.85rem;
  }"""

new_m = """  .live-p {
    --live-gutter: 16px;
    padding-top: 3.75rem;
  }"""

if "padding-top: 3.75rem" in css:
    print("mobile clearance exists")
elif old_m in css:
    css = css.replace(old_m, new_m, 1)
    print("mobile padding-top clearance")
else:
    raise SystemExit("mobile live-p padding not found")

marker = "/* Clear absolute header on desktop Live TV */"
if marker not in css:
    css += f"""

{marker}
@media (min-width: 992px) {{
  .live-p {{
    padding-top: 4.15rem;
  }}
}}
"""
    print("desktop clearance appended")

live_css.write_text(css)

for p in (routes, live_php):
    t = p.read_text()
    nt = t
    nt = re.sub(r"\$liveAssetV = '2026080\d-ui\d+';", f"$liveAssetV = '{UI}';", nt)
    nt = re.sub(r"\$assetV = '2026080\d-ui\d+';", f"$assetV = '{UI}';", nt)
    if nt != t:
        p.write_text(nt)
        print("bumped", p.name)

c = live_css.read_text()
assert "padding: 3.85rem" in c
assert "padding-top: 3.75rem" in c
assert "padding-top: 4.15rem" in c
assert re.search(
    r"@media \(max-width: 991\.98px\) \{\s*\.live-p \{[^}]+\}\s*\.live-hero",
    c,
    re.S,
), "mobile media structure broken"
print("OK", UI)
