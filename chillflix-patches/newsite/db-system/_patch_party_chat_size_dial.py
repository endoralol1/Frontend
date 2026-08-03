#!/usr/bin/env python3
"""Dial back party chat size — wider than original, less than full-bleed."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
css = root / "public/assets/css/continue-party.css"
main = root / "app/Views/layouts/main.php"
routes = root / "app/routes.php"
player = root / "app/Views/layouts/player.php"
UI = "20260803-ui154"

c = css.read_text()
old = """/* ——— Watch Party chat (below video) ——— */
.cf-party-chat[hidden] { display: none !important; }
.cf-party-chat {
  width: 100%;
  max-width: none;
  box-sizing: border-box;
  margin: 0.75rem 0 0.35rem;
  border-radius: 0.9rem;
  border: 1px solid rgba(255,255,255,.1);
  background:
    linear-gradient(180deg, rgba(255,255,255,.05), transparent 40%),
    rgba(12, 14, 20, .88);
  overflow: hidden;
}
/* Phone: edge-to-edge under the player (cancel .container 15px gutters) */
@media (max-width: 767.98px) {
  .cf-party-chat {
    width: calc(100% + 30px);
    max-width: calc(100% + 30px);
    margin-left: -15px;
    margin-right: -15px;
    margin-top: 0.55rem;
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }
  .cf-party-chat-log {
    height: min(48vh, 20rem);
    padding: .7rem .85rem;
  }
  .cf-party-chat-head {
    padding: .65rem .85rem;
  }
  .cf-party-chat-form,
  .cf-party-chat-login {
    padding-left: .85rem;
    padding-right: .85rem;
  }
  .cf-party-chat-msg-text { font-size: .92rem; }
}
@media (min-width: 768px) {
  .cf-party-chat-log {
    height: min(42vh, 18rem);
  }
}
"""

new = """/* ——— Watch Party chat (below video) ——— */
.cf-party-chat[hidden] { display: none !important; }
.cf-party-chat {
  width: 100%;
  max-width: none;
  box-sizing: border-box;
  margin: 0.65rem 0 0.2rem;
  border-radius: 0.9rem;
  border: 1px solid rgba(255,255,255,.1);
  background:
    linear-gradient(180deg, rgba(255,255,255,.05), transparent 40%),
    rgba(12, 14, 20, .88);
  overflow: hidden;
}
/* Phone: a bit roomier than before, still inset with the player card */
@media (max-width: 767.98px) {
  .cf-party-chat-log {
    height: min(40vh, 16.5rem);
  }
}
@media (min-width: 768px) {
  .cf-party-chat-log {
    height: min(40vh, 16rem);
  }
}
"""

if old not in c:
    raise SystemExit("wide chat css block not found")
c = c.replace(old, new, 1)

# Keep base log height modest if the previous bump is still there
c = c.replace(
    """.cf-party-chat-log {
  height: min(40vh, 16.5rem);
  overflow: auto;
  padding: .6rem .75rem;""",
    """.cf-party-chat-log {
  height: min(38vh, 15.5rem);
  overflow: auto;
  padding: .55rem .7rem;""",
    1,
)
css.write_text(c)
print("dialed back chat size")

for p in (main, routes, player):
    if not p.exists():
        continue
    t = p.read_text()
    nt = re.sub(r"\?v=20260803-ui\d+", f"?v={UI}", t)
    if nt != t:
        p.write_text(nt)
        print("bumped", p.name)

print("OK", UI)
