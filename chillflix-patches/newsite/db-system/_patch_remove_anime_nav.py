#!/usr/bin/env python3
from pathlib import Path
import re

nav = Path("/var/www/chillflix-newsite/app/Views/partials/bottom-nav.php")
css = Path("/var/www/chillflix-newsite/public/assets/css/app.css")
layout = Path("/var/www/chillflix-newsite/app/Views/layouts/main.php")

t = nav.read_text()
t = re.sub(r"\$navAnime = is_active\('/anime'\);\n", "", t)
t = re.sub(
    r"\s*<a href=\"<?= e\(url\('/anime'\)\) ?>\" class=\"bottom-nav-item<?= \$navAnime \? ' active' : '' ?>\"<?= \$navAnime \? ' aria-current=\"page\"' : '' ?>>\n"
    r"\s*<span class=\"bottom-nav-icon\"><i class=\"uil uil-star\" aria-hidden=\"true\"></i></span>\n"
    r"\s*<span class=\"bottom-nav-label\">Anime</span>\n"
    r"\s*</a>\n",
    "\n",
    t,
)
if 'bottom-nav-label">Anime' in t or "url('/anime')" in t and "bottom-nav-item" in t:
    # fallback line scan
    lines = t.splitlines(True)
    out = []
    i = 0
    while i < len(lines):
        line = lines[i]
        if "url('/anime')" in line and "bottom-nav-item" in line:
            while i < len(lines) and "</a>" not in lines[i]:
                i += 1
            i += 1
            continue
        if "$navAnime" in line:
            i += 1
            continue
        out.append(line)
        i += 1
    t = "".join(out)
if 'bottom-nav-label">Anime' in t:
    raise SystemExit("Anime nav item still present")
nav.write_text(t)
print("nav ok")

c = css.read_text()
# Fix the two bottom-nav 6-col overrides added for anime
c = c.replace(
    """/* anime-main-nav */
@media (max-width: 991.98px) {
  .bottom-nav-inner {
    grid-template-columns: repeat(6, 1fr) !important;
    max-width: 34rem !important;
    gap: 0.05rem !important;
  }""",
    """/* bottom-nav 5 items */
@media (max-width: 991.98px) {
  .bottom-nav-inner {
    grid-template-columns: repeat(5, 1fr) !important;
    max-width: 28rem !important;
    gap: 0.1rem !important;
  }""",
)
c = c.replace(
    """  /* 6-item nav: keep labels readable without feeling crushed */
  .bottom-nav-inner {
    grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
    max-width: 36rem !important;
    padding: 0.3rem 0.25rem !important;
  }""",
    """  /* 5-item nav */
  .bottom-nav-inner {
    grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
    max-width: 28rem !important;
    padding: 0.35rem !important;
  }""",
)
css.write_text(c)
print("css ok")

lt = layout.read_text()
for old in ("20260803-ui138", "20260803-ui137", "20260803-ui139"):
    if old in lt and old != "20260803-ui139":
        lt = lt.replace(old, "20260803-ui139")
if "20260803-ui139" not in lt:
    # force bump from whatever ui
    lt = re.sub(r"\?v=20260803-ui\d+", "?v=20260803-ui139", lt)
layout.write_text(lt)
print("layout", "ui139" in layout.read_text())

for i, line in enumerate(nav.read_text().splitlines(), 1):
    if i <= 40 and ("bottom-nav-label" in line or "$nav" in line or "bottom-nav-item" in line):
        print(f"{i}:{line.rstrip()}")
