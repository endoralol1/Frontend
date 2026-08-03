#!/usr/bin/env python3
"""Move Watch Party chip (code + Leave) outside the video player."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
watch = root / "app/Views/pages/watch.php"
player_js = root / "public/assets/js/player.js"
party_css = root / "public/assets/css/continue-party.css"
player_css = root / "public/assets/css/player.css"
main = root / "app/Views/layouts/main.php"
routes = root / "app/routes.php"
player_layout = root / "app/Views/layouts/player.php"
UI = "20260803-ui155"

# --- watch.php: bar between player and chat ---
w = watch.read_text()
if 'id="cf-party-chip-bar"' not in w:
    anchor = '\n                <div id="cf-party-chat"'
    insert = (
        '\n                <div id="cf-party-chip-bar" class="cf-party-chip-bar" hidden></div>'
        + anchor
    )
    if anchor not in w:
        raise SystemExit("chat anchor not found for chip bar")
    w = w.replace(anchor, insert, 1)
    watch.write_text(w)
    print("chip bar markup")
else:
    print("chip bar markup exists")

# --- player.js: mount chip outside shell ---
js = player_js.read_text()
old_paint = '''    function paintPartyChip() {
      if (!state.party || !els.shell) return;
      let chip = els.shell.querySelector("#np-party-chip");
      if (!chip) {
        chip = document.createElement("div");
        chip.id = "np-party-chip";
        chip.className = "np-party-chip";
        chip.innerHTML =
          '<span class="np-party-chip-label"></span>' +
          '<button type="button" class="np-party-leave" id="np-party-leave" aria-label="Leave party">Leave</button>';
        els.shell.querySelector(".np-top-actions")?.appendChild(chip);
        chip.querySelector("#np-party-leave")?.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          leaveParty(true);
        });
      }
      const label = chip.querySelector(".np-party-chip-label");
      if (label) {
        label.textContent =
          state.party.role === "host"
            ? `Party ${state.party.code} · Host`
            : `Party ${state.party.code}`;
      }
    }'''

new_paint = '''    function partyChipHost() {
      let host = document.getElementById("cf-party-chip-bar");
      if (host) return host;
      const player = document.getElementById("movie-player");
      if (!player || !player.parentNode) return null;
      host = document.createElement("div");
      host.id = "cf-party-chip-bar";
      host.className = "cf-party-chip-bar";
      host.hidden = true;
      player.insertAdjacentElement("afterend", host);
      return host;
    }

    function paintPartyChip() {
      if (!state.party) return;
      const host = partyChipHost();
      if (!host) return;
      // Remove any leftover chip stuck inside the player chrome
      try { els.shell?.querySelector("#np-party-chip")?.remove(); } catch (_) {}
      host.hidden = false;
      let chip = host.querySelector("#np-party-chip");
      if (!chip) {
        chip = document.createElement("div");
        chip.id = "np-party-chip";
        chip.className = "np-party-chip";
        chip.innerHTML =
          '<span class="np-party-chip-label"></span>' +
          '<button type="button" class="np-party-leave" id="np-party-leave" aria-label="Leave party">Leave</button>';
        host.appendChild(chip);
        chip.querySelector("#np-party-leave")?.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          leaveParty(true);
        });
      }
      const label = chip.querySelector(".np-party-chip-label");
      if (label) {
        label.textContent =
          state.party.role === "host"
            ? `Party ${state.party.code} · Host`
            : `Party ${state.party.code}`;
      }
    }'''

if old_paint not in js:
    if "function partyChipHost()" in js:
        print("paintPartyChip already outside")
    else:
        raise SystemExit("paintPartyChip block not found")
else:
    js = js.replace(old_paint, new_paint, 1)
    print("paintPartyChip outside player")

old_stop = '''      state.party = null;
      state.partyHostId = null;
      state.partyUnloadBound = null;
      els.shell?.querySelector("#np-party-chip")?.remove();
      clearPartyFromUrl();'''

new_stop = '''      state.party = null;
      state.partyHostId = null;
      state.partyUnloadBound = null;
      try { els.shell?.querySelector("#np-party-chip")?.remove(); } catch (_) {}
      try {
        document.getElementById("np-party-chip")?.remove();
        const bar = document.getElementById("cf-party-chip-bar");
        if (bar) bar.hidden = true;
      } catch (_) {}
      clearPartyFromUrl();'''

if old_stop not in js:
    if 'document.getElementById("cf-party-chip-bar")' in js:
        print("stopPartyLocal already clears outer chip")
    else:
        raise SystemExit("stopPartyLocal chip clear not found")
else:
    js = js.replace(old_stop, new_stop, 1)
    print("stopPartyLocal clears outer chip")

player_js.write_text(js)

# --- CSS: external bar ---
pc = party_css.read_text()
if ".cf-party-chip-bar" not in pc:
    pc += """

/* Party chip outside the video player */
.cf-party-chip-bar[hidden] { display: none !important; }
.cf-party-chip-bar {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  margin: 0.55rem 0 0.15rem;
}
.cf-party-chip-bar .np-party-chip {
  display: inline-flex;
  align-items: center;
  gap: .45rem;
  padding: .4rem .4rem .4rem .7rem;
  border-radius: .65rem;
  background:
    linear-gradient(180deg, rgba(255,255,255,.06), transparent 50%),
    rgba(220, 53, 69, .16);
  border: 1px solid rgba(220, 53, 69, .42);
  color: #ffc3c9;
  font-size: .74rem;
  font-weight: 750;
  letter-spacing: .03em;
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
  padding: .35rem .65rem;
  border-radius: .5rem;
  background: linear-gradient(180deg, #ef5b45, #c43c2e);
  color: #fff;
  font: inherit;
  font-size: .7rem;
  font-weight: 750;
  letter-spacing: .02em;
  text-transform: none;
  cursor: pointer;
}
.cf-party-chip-bar .np-party-leave:hover {
  filter: brightness(1.06);
}
"""
    party_css.write_text(pc)
    print("outer chip css")
else:
    print("outer chip css exists")

# bump
for p in (main, routes, player_layout):
    if not p.exists():
        continue
    t = p.read_text()
    nt = re.sub(r"\?v=20260803-ui\d+", f"?v={UI}", t)
    if nt != t:
        p.write_text(nt)
        print("bumped", p.name)

print("OK", UI)
