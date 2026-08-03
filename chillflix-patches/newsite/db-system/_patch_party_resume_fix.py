#!/usr/bin/env python3
"""Fix Return-to-party bar: watch page was still on old player.js ui104."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
routes = root / "app/routes.php"
player_js = root / "public/assets/js/player.js"
party_js = root / "public/assets/js/continue-party.js"
app_js = root / "public/assets/js/app.js"
main_layout = root / "app/Views/layouts/main.php"
player_layout = root / "app/Views/layouts/player.php"

# 1) Watch page must load current player assets
rt = routes.read_text()
rt2, n = re.subn(
    r"asset\('css/player\.css'\) \. '\?v=20260802-ui104'",
    "asset('css/player.css') . '?v=20260803-ui149'",
    rt,
)
rt3, n2 = re.subn(
    r"asset\('js/player\.js'\) \. '\?v=20260802-ui104'",
    "asset('js/player.js') . '?v=20260803-ui149'",
    rt2,
)
if n + n2 < 2:
    # try broader replace for any old player asset pin on watch_page block
    if "ui104" in rt:
        rt3 = rt.replace("20260802-ui104", "20260803-ui149")
        print("replaced all ui104 -> ui149")
    else:
        raise SystemExit(f"watch player asset pins not found n={n},{n2}")
else:
    print(f"watch assets bumped ({n}/{n2})")
routes.write_text(rt3)

# 2) Player: destroy/save BEFORE soft-nav swaps DOM; expose prepareLeave
js = player_js.read_text()
js = js.replace(
    """  window.addEventListener("cf:softnav", () => {
    if (!active) return;
    try {
      active.destroy();
    } catch (_) {}
    active = null;
  });""",
    """  function preparePartyLeave() {
    if (!active) return;
    try {
      active.destroy();
    } catch (_) {}
    active = null;
  }
  // Save hosting resume before wrapper swap, and again after
  window.addEventListener("cf:before-softnav", preparePartyLeave);
  window.addEventListener("cf:softnav", preparePartyLeave);""",
    1,
)
if "prepareLeave:" not in js:
    js = js.replace(
        """  window.ChillflixPlayer = {
    mount,
    startOnWatch(cfg) {""",
        """  window.ChillflixPlayer = {
    mount,
    prepareLeave: preparePartyLeave,
    startOnWatch(cfg) {""",
        1,
    )
player_js.write_text(js)
print("player prepareLeave before softnav")

# 3) app.js: fire before-softnav prior to HTML swap
app = app_js.read_text()
if "cf:before-softnav" not in app:
    app = app.replace(
        """  function applySoftNavHtml(html, url, push) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var nextWrapper = doc.querySelector('.wrapper');
    var curWrapper = document.querySelector('.wrapper');
    if (!nextWrapper || !curWrapper) {
      window.location.href = url;
      return;
    }""",
        """  function applySoftNavHtml(html, url, push) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var nextWrapper = doc.querySelector('.wrapper');
    var curWrapper = document.querySelector('.wrapper');
    if (!nextWrapper || !curWrapper) {
      window.location.href = url;
      return;
    }
    try { window.dispatchEvent(new Event('cf:before-softnav')); } catch (eBefore) {}
    try { if (window.ChillflixPlayer && typeof window.ChillflixPlayer.prepareLeave === 'function') window.ChillflixPlayer.prepareLeave(); } catch (ePrep) {}""",
        1,
    )
    app_js.write_text(app)
    print("app.js before-softnav")
else:
    print("app.js already has before-softnav")

# 4) continue-party: retry paint after soft-nav / focus (race-safe)
pj = party_js.read_text()
pj = pj.replace(
    '  window.addEventListener("cf:softnav", function () { bootContinue(); paintPartyResume(); });',
    '  window.addEventListener("cf:softnav", function () {\n'
    '    bootContinue();\n'
    '    paintPartyResume();\n'
    '    setTimeout(paintPartyResume, 50);\n'
    '    setTimeout(paintPartyResume, 300);\n'
    '    setTimeout(paintPartyResume, 1000);\n'
    '  });',
    1,
)
# Also paint on pageshow already - ensure visibility
if "visibilitychange\", function () {\n    if (document.visibilityState === \"visible\") bootContinue();" in pj:
    pj = pj.replace(
        '  document.addEventListener("visibilitychange", function () {\n    if (document.visibilityState === "visible") bootContinue();\n  });',
        '  document.addEventListener("visibilitychange", function () {\n'
        '    if (document.visibilityState === "visible") {\n'
        '      bootContinue();\n'
        '      paintPartyResume();\n'
        '    }\n'
        '  });',
        1,
    )
party_js.write_text(pj)
print("continue-party retry paint")

# bump layouts
for layout in (main_layout, player_layout):
    lt = layout.read_text()
    layout.write_text(re.sub(r"\?v=2026080\d-ui\d+", "?v=20260803-ui149", lt))
print("layouts ui149")
print("DONE")
