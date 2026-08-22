#!/usr/bin/env python3
from pathlib import Path

nav_link = '      <a href="<?= e(url(\'/admin/proxy-guard\')) ?>">Proxy guard</a>\n'
for p in Path("/var/www/chillflix-newsite/app/Views/pages/admin").glob("*.php"):
    t = p.read_text()
    if "proxy-guard" in t or "cf-admin-nav" not in t:
        continue
    needle = '      <a href="<?= e(url(\'/home\')) ?>">Site</a>'
    active_storage = '      <a class="is-active" href="<?= e(url(\'/admin/storage\')) ?>">Storage</a>\n' + needle
    # Insert before Site link
    if needle in t and "proxy-guard" not in t:
        t = t.replace(needle, nav_link + needle, 1)
        p.write_text(t)
        print("updated", p.name)

# player layout cache
p = Path("/var/www/chillflix-newsite/app/Views/layouts/player.php")
t = p.read_text()
for old in ("20260822-relay1", "20260821-rescan1", "20260822-guard1"):
    pass
t2 = t.replace("20260822-relay1", "20260822-guard1").replace("20260821-rescan1", "20260822-guard1")
p.write_text(t2)
print("layout ok")
