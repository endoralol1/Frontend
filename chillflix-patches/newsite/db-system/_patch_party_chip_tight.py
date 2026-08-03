#!/usr/bin/env python3
"""Pin party chip to player top-right without wasting vertical space."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
watch = root / "app/Views/pages/watch.php"
player_js = root / "public/assets/js/player.js"
party_css = root / "public/assets/css/continue-party.css"
main = root / "app/Views/layouts/main.php"
routes = root / "app/routes.php"
player_layout = root / "app/Views/layouts/player.php"
UI = "20260803-ui157"

BAR = '<div id="cf-party-chip-bar" class="cf-party-chip-bar" hidden></div>'

# --- watch.php: first child inside #movie-player ---
w = watch.read_text()
w = re.sub(
    r'\n?\s*<div id="cf-party-chip-bar" class="cf-party-chip-bar" hidden></div>\s*\n?',
    "\n",
    w,
    count=1,
)
anchor = (
    '                <div id="movie-player" class="no-player playable" style="position:relative;">\n'
    '                    <div class="movie-player-wrap">'
)
insert = (
    '                <div id="movie-player" class="no-player playable" style="position:relative;">\n'
    f'                    {BAR}\n'
    '                    <div class="movie-player-wrap">'
)
if anchor not in w:
    # looser match
    m = re.search(
        r'(<div id="movie-player"[^>]*>)\s*(<div class="movie-player-wrap">)',
        w,
    )
    if not m:
        raise SystemExit("movie-player open not found")
    w = w[: m.start()] + m.group(1) + "\n                    " + BAR + "\n                    " + m.group(2) + w[m.end() :]
else:
    w = w.replace(anchor, insert, 1)
watch.write_text(w)
print("chip inside movie-player top")

# --- JS: afterbegin ---
js = player_js.read_text()
old = '''      host.hidden = true;
      player.insertAdjacentElement("beforebegin", host);
      return host;'''
new = '''      host.hidden = true;
      player.insertAdjacentElement("afterbegin", host);
      return host;'''
if old not in js:
    if 'insertAdjacentElement("afterbegin", host)' in js:
        print("js already afterbegin")
    else:
        raise SystemExit("beforebegin insert not found")
else:
    player_js.write_text(js.replace(old, new, 1))
    print("js afterbegin")

# --- CSS: absolute top-right, no flow space ---
css = party_css.read_text()
old_css = """.cf-party-chip-bar[hidden] { display: none !important; }
.cf-party-chip-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin: 0 0 0.45rem;
}"""
new_css = """.cf-party-chip-bar[hidden] { display: none !important; }
.cf-party-chip-bar {
  position: absolute;
  top: 0.45rem;
  right: 0.45rem;
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin: 0;
  pointer-events: none;
}
.cf-party-chip-bar .np-party-chip {
  pointer-events: auto;
}"""
if old_css not in css:
    if "position: absolute" in css[css.find(".cf-party-chip-bar") : css.find(".cf-party-chip-bar") + 220]:
        print("css already absolute")
    else:
        raise SystemExit("chip bar css block not found")
else:
    # The following .np-party-chip rule already exists — merge pointer-events into it if duplicated
    css = css.replace(old_css, new_css, 1)
    # Avoid duplicate .cf-party-chip-bar .np-party-chip { pointer-events } clashing — ok as additive
    # Deduplicate if we now have two consecutive .np-party-chip blocks
    css = css.replace(
        """.cf-party-chip-bar .np-party-chip {
  pointer-events: auto;
}
.cf-party-chip-bar .np-party-chip {
  display: inline-flex;""",
        """.cf-party-chip-bar .np-party-chip {
  pointer-events: auto;
  display: inline-flex;""",
        1,
    )
    party_css.write_text(css)
    print("css absolute top-right")

for p in (main, routes, player_layout):
    if not p.exists():
        continue
    t = p.read_text()
    nt = re.sub(r"\?v=20260803-ui\d+", f"?v={UI}", t)
    if nt != t:
        p.write_text(nt)
        print("bumped", p.name)

w = watch.read_text()
assert "cf-party-chip-bar" in w
assert w.find('id="movie-player"') < w.find('id="cf-party-chip-bar"') < w.find('movie-player-wrap')
print("OK", UI)
