#!/usr/bin/env python3
"""Put Watch Party chip in the header logo row, right side — outside player."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
header = root / "app/Views/partials/header.php"
watch = root / "app/Views/pages/watch.php"
player_js = root / "public/assets/js/player.js"
party_css = root / "public/assets/css/continue-party.css"
main = root / "app/Views/layouts/main.php"
routes = root / "app/routes.php"
player_layout = root / "app/Views/layouts/player.php"
UI = "20260803-ui158"

BAR = '<div id="cf-party-chip-bar" class="cf-party-chip-bar" hidden></div>'

# --- header.php: mount in .end ---
h = header.read_text()
if 'id="cf-party-chip-bar"' not in h:
    if '<div class="end"></div>' in h:
        h = h.replace(
            '<div class="end"></div>',
            '<div class="end">\n'
            '                ' + BAR + '\n'
            '            </div>',
            1,
        )
    else:
        raise SystemExit("header .end not found")
    header.write_text(h)
    print("header chip slot")
else:
    print("header chip slot exists")

# --- watch.php: remove any chip bars from player/page ---
w = watch.read_text()
w2, n = re.subn(
    r'\n?\s*<div id="cf-party-chip-bar" class="cf-party-chip-bar" hidden></div>\s*\n?',
    "\n",
    w,
)
if n:
    watch.write_text(w2)
    print(f"removed {n} chip bar(s) from watch.php")
else:
    print("watch.php already clean")

# --- player.js host ---
js = player_js.read_text()
old_host = '''    function partyChipHost() {
      let host = document.getElementById("cf-party-chip-bar");
      if (host) return host;
      const player = document.getElementById("movie-player");
      if (!player || !player.parentNode) return null;
      host = document.createElement("div");
      host.id = "cf-party-chip-bar";
      host.className = "cf-party-chip-bar";
      host.hidden = true;
      player.insertAdjacentElement("afterbegin", host);
      return host;
    }'''

new_host = '''    function partyChipHost() {
      let host = document.getElementById("cf-party-chip-bar");
      if (host) return host;
      const end =
        document.querySelector("header .wrapper .end") ||
        document.querySelector("header .wrapper");
      if (!end) return null;
      host = document.createElement("div");
      host.id = "cf-party-chip-bar";
      host.className = "cf-party-chip-bar";
      host.hidden = true;
      end.appendChild(host);
      return host;
    }'''

if old_host not in js:
    if 'header .wrapper .end' in js:
        print("js host already header")
    else:
        raise SystemExit("partyChipHost block not found")
else:
    player_js.write_text(js.replace(old_host, new_host, 1))
    print("js host -> header")

# --- CSS: header row, right side, outside player ---
css = party_css.read_text()
# Replace the whole chip-bar style block through leave:hover
start = css.find("/* Party chip outside the video player */")
if start < 0:
    start = css.find(".cf-party-chip-bar[hidden]")
if start < 0:
    raise SystemExit("chip css not found")
# find end of chip styles (before next major section or EOF)
# Keep replacing from .cf-party-chip-bar[hidden] through .np-party-leave:hover
m = re.search(
    r"/\* Party chip outside the video player \*/\s*"
    r"\.cf-party-chip-bar\[hidden\][\s\S]*?\.cf-party-chip-bar \.np-party-leave:hover \{[^}]*\}",
    css,
)
if not m:
    m = re.search(
        r"\.cf-party-chip-bar\[hidden\][\s\S]*?\.cf-party-chip-bar \.np-party-leave:hover \{[^}]*\}",
        css,
    )
if not m:
    raise SystemExit("chip css range not found")

new_css = """/* Party chip in header logo row (right) — outside player */
.cf-party-chip-bar[hidden] { display: none !important; }
.cf-party-chip-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin: 0;
  pointer-events: none;
}
.cf-party-chip-bar .np-party-chip {
  pointer-events: auto;
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  padding: .32rem .32rem .32rem .6rem;
  border-radius: .6rem;
  background:
    linear-gradient(180deg, rgba(255,255,255,.08), transparent 50%),
    rgba(220, 53, 69, .18);
  border: 1px solid rgba(220, 53, 69, .45);
  color: #ffc3c9;
  font-size: .7rem;
  font-weight: 750;
  letter-spacing: .02em;
  box-shadow: 0 8px 18px rgba(0,0,0,.28);
  backdrop-filter: blur(12px) saturate(1.2);
  -webkit-backdrop-filter: blur(12px) saturate(1.2);
}
.cf-party-chip-bar .np-party-chip-label {
  white-space: nowrap;
  text-transform: none;
  letter-spacing: .02em;
}
.cf-party-chip-bar .np-party-leave {
  appearance: none;
  border: 0;
  margin: 0;
  padding: .28rem .55rem;
  border-radius: .45rem;
  background: linear-gradient(180deg, #ef5b45, #c43c2e);
  color: #fff;
  font: inherit;
  font-size: .66rem;
  font-weight: 750;
  letter-spacing: .02em;
  text-transform: none;
  cursor: pointer;
}
.cf-party-chip-bar .np-party-leave:hover {
  filter: brightness(1.06);
}

/* Reveal header .end only while the party chip is visible */
body.page-watch header .wrapper .end:has(#cf-party-chip-bar:not([hidden])) {
  display: flex !important;
  align-items: center;
  align-self: center;
  flex: 0 0 auto;
  margin-left: auto;
  min-width: 0;
}
@media (min-width: 992px) {
  /* Sit in the logo band, just left of the floating nav pill */
  body.page-watch header .wrapper .end:has(#cf-party-chip-bar:not([hidden])) {
    margin-right: min(22rem, 42vw);
    padding-top: 0.1rem;
  }
}
@media (max-width: 991.98px) {
  body.page-watch header .wrapper .end:has(#cf-party-chip-bar:not([hidden])) {
    padding-top: 0.05rem;
  }
  body.page-watch header .wrapper .step:has(+ .end #cf-party-chip-bar:not([hidden])) {
    display: none !important;
  }
}"""

css = css[: m.start()] + new_css + css[m.end() :]
party_css.write_text(css)
print("header chip css")

for p in (main, routes, player_layout):
    if not p.exists():
        continue
    t = p.read_text()
    nt = re.sub(r"\?v=20260803-ui\d+", f"?v={UI}", t)
    if nt != t:
        p.write_text(nt)
        print("bumped", p.name)

# verify
assert 'id="cf-party-chip-bar"' in header.read_text()
assert 'id="cf-party-chip-bar"' not in watch.read_text()
assert "header .wrapper .end" in player_js.read_text()
print("OK", UI)
