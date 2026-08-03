#!/usr/bin/env python3
"""Open browse sheet when party chat Log in is clicked; bump ui152."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
app_js = root / "public/assets/js/app.js"
aj = app_js.read_text()

old = """  function openBrowseAuth(mode) {
    closeBrowseSettings();
    closeBrowseRequest();
    closeBrowseContact();
    $('#browse-home-view').attr('hidden', true);
    $('#browse-auth-view').removeAttr('hidden').addClass('is-open');
    $('#browse-sheet').addClass('auth-open');
    setBrowseAuthMode(mode || 'login');
  }"""

new = """  function openBrowseAuth(mode) {
    try { openBrowseSheet(); } catch (eOpen) {}
    closeBrowseSettings();
    closeBrowseRequest();
    closeBrowseContact();
    $('#browse-home-view').attr('hidden', true);
    $('#browse-auth-view').removeAttr('hidden').addClass('is-open');
    $('#browse-sheet').addClass('auth-open');
    setBrowseAuthMode(mode || 'login');
  }"""

if old not in aj:
    if "try { openBrowseSheet(); } catch (eOpen) {}" in aj:
        print("openBrowseAuth already opens sheet")
    else:
        raise SystemExit("openBrowseAuth not found")
else:
    app_js.write_text(aj.replace(old, new, 1))
    print("openBrowseAuth opens sheet")

for rel in [
    "app/Views/layouts/main.php",
    "app/routes.php",
    "app/Views/layouts/player.php",
]:
    p = root / rel
    if not p.exists():
        continue
    t = p.read_text()
    nt = re.sub(r"\?v=20260803-ui\d+", "?v=20260803-ui152", t)
    if nt != t:
        p.write_text(nt)
        print("bumped", rel)

# Keep continue-party asset pins in sync if present as literals
for rel in [
    "public/assets/js/continue-party.js",
    "public/assets/css/continue-party.css",
]:
    p = root / rel
    if not p.exists():
        continue
    t = p.read_text()
    if "20260803-ui151" in t:
        p.write_text(t.replace("20260803-ui151", "20260803-ui152"))
        print("replaced in", rel)

wp = (root / "app/Services/WatchParty.php").read_text()
assert "Log in to chat" in wp and "authRequired" in wp
watch = (root / "app/Views/pages/watch.php").read_text()
assert "Log in to join the party chat" in watch
js = (root / "public/assets/js/continue-party.js").read_text()
assert "currentAuthUser" in js
print("OK ui152")
