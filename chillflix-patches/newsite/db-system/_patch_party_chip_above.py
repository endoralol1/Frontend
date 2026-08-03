#!/usr/bin/env python3
"""Place Watch Party chip above the player, right-aligned."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
watch = root / "app/Views/pages/watch.php"
player_js = root / "public/assets/js/player.js"
party_css = root / "public/assets/css/continue-party.css"
main = root / "app/Views/layouts/main.php"
routes = root / "app/routes.php"
player_layout = root / "app/Views/layouts/player.php"
UI = "20260803-ui156"

# --- watch.php: move bar above movie-player ---
w = watch.read_text()
bar = '                <div id="cf-party-chip-bar" class="cf-party-chip-bar" hidden></div>\n'
# remove wherever it currently is
w2 = w.replace(bar, "")
w2 = w2.replace(
    '                <div id="cf-party-chip-bar" class="cf-party-chip-bar" hidden></div>\n\n',
    "",
)
# also handle without trailing newline variants
w2 = re.sub(
    r'\n?\s*<div id="cf-party-chip-bar" class="cf-party-chip-bar" hidden></div>\s*\n?',
    "\n",
    w2,
    count=1,
)

anchor = '                <div id="movie-player"'
if 'id="cf-party-chip-bar"' in w2:
    raise SystemExit("chip bar still present after remove")
if anchor not in w2:
    raise SystemExit("movie-player anchor not found")
w2 = w2.replace(
    anchor,
    '                <div id="cf-party-chip-bar" class="cf-party-chip-bar" hidden></div>\n'
    + anchor,
    1,
)
watch.write_text(w2)
print("chip bar above player")

# --- JS fallback: beforebegin ---
js = player_js.read_text()
old = '''      host.hidden = true;
      player.insertAdjacentElement("afterend", host);
      return host;'''
new = '''      host.hidden = true;
      player.insertAdjacentElement("beforebegin", host);
      return host;'''
if old not in js:
    if 'insertAdjacentElement("beforebegin", host)' in js:
        print("js already beforebegin")
    else:
        raise SystemExit("insertAdjacent afterend not found")
else:
    player_js.write_text(js.replace(old, new, 1))
    print("js beforebegin")

# --- CSS: right side above player ---
css = party_css.read_text()
old_css = """.cf-party-chip-bar[hidden] { display: none !important; }
.cf-party-chip-bar {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  margin: 0.55rem 0 0.15rem;
}"""
new_css = """.cf-party-chip-bar[hidden] { display: none !important; }
.cf-party-chip-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin: 0 0 0.45rem;
}"""
if old_css not in css:
    if "justify-content: flex-end" in css and ".cf-party-chip-bar" in css:
        print("css already right-aligned")
    else:
        raise SystemExit("chip bar css not found")
else:
    party_css.write_text(css.replace(old_css, new_css, 1))
    print("css above-right")

for p in (main, routes, player_layout):
    if not p.exists():
        continue
    t = p.read_text()
    nt = re.sub(r"\?v=20260803-ui\d+", f"?v={UI}", t)
    if nt != t:
        p.write_text(nt)
        print("bumped", p.name)

# verify order
w = watch.read_text()
assert w.find('id="cf-party-chip-bar"') < w.find('id="movie-player"') < w.find('id="cf-party-chat"')
print("OK", UI)
