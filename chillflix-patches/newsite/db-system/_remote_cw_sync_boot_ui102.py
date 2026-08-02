#!/usr/bin/env python3
"""ui102: Fix CW boot sync scope + watch player cache pin + cookie on sync."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
ver = "20260802-ui102"

# ---------- app.js: replace broken out-of-scope boot sync ----------
aj = root / "public/assets/js/app.js"
at = aj.read_text()

old_boot = """
  /* ns-boot-sync-ui100 */
  try {
    authApi('/api/auth/me', { method: 'GET', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.user) {
          try { nsSyncLibrary(); } catch (e) {}
        }
      }).catch(function () {});
  } catch (eBoot) {}
"""

new_boot = """
/* ns-boot-sync-ui102
 * Previous boot called authApi()/nsSyncLibrary() OUTSIDE their IIFE scope, so
 * authApi threw ReferenceError and logged-in library sync never ran on home.
 * That left Continue Watching stuck on whatever was in the local cookie (often 1 item)
 * even when MySQL had the full history.
 */
(function () {
  function base() {
    return (window.CF_BASE || (window.APP && APP.baseUrl) || '').replace(/\\/$/, '');
  }
  function bootSync() {
    if (typeof window.nsSyncLibrary !== 'function') return;
    fetch(base() + '/api/auth/me', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.user) {
          try { window.nsSyncLibrary(); } catch (e) {}
        }
      })
      .catch(function () {});
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootSync);
  } else {
    bootSync();
  }
})();
"""

if old_boot not in at:
    # already replaced?
    if "ns-boot-sync-ui102" in at:
        print("boot sync already ui102")
    else:
        raise SystemExit("old boot sync block not found")
else:
    at = at.replace(old_boot, "\n" + new_boot + "\n", 1)
    print("boot sync replaced")

# Write cookie mirror after successful CW merge in nsSyncLibrary
needle = """          try { localStorage.setItem('cf_continue_v1', JSON.stringify(merge.merged)); } catch (e3) {}
          // Upload only local-newer / local-only rows (max 12 per sync)
"""
repl = """          try { localStorage.setItem('cf_continue_v1', JSON.stringify(merge.merged)); } catch (e3) {}
          // Keep cookie mirror in sync so home recovery has the FULL list, not one title
          try {
            var mKeys = Object.keys(merge.merged || {}).sort(function (a, b) {
              return (Number(merge.merged[b].updated) || 0) - (Number(merge.merged[a].updated) || 0);
            });
            var compact = mKeys.slice(0, 16).map(function (k) {
              var row = merge.merged[k] || {};
              return {
                id: row.id, type: row.type, title: row.title || '',
                poster: row.poster || '', backdrop: '',
                year: row.year || '', season: row.season, episode: row.episode,
                t: row.t || 0, d: row.d || 0, updated: row.updated || Date.now()
              };
            }).filter(function (r) { return r && r.id; });
            document.cookie = 'cf_continue_v1=' + encodeURIComponent(JSON.stringify(compact))
              + ';path=/;max-age=31536000;SameSite=Lax';
          } catch (eCookieMirror) {}
          // Upload only local-newer / local-only rows (max 12 per sync)
"""
if needle not in at:
    if "Keep cookie mirror in sync" in at:
        print("cookie mirror already in sync")
    else:
        raise SystemExit("sync cookie needle not found")
else:
    at = at.replace(needle, repl, 1)
    print("sync cookie mirror added")

aj.write_text(at)

# ---------- continue-party.js: pull server CW on home boot ----------
cp = root / "public/assets/js/continue-party.js"
ct = cp.read_text()
if "/* cw-server-pull-ui102 */" not in ct:
    pull = r'''
  /* cw-server-pull-ui102 */
  function pullServerContinue() {
    try {
      var url = base() + "/api/user/library";
      fetch(url, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      })
        .then(function (r) {
          if (!r.ok) return null;
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok || !data.user) return;
          var serverItems = data.continueWatching || [];
          if (!serverItems.length) return;
          var map = normalizeMap({});
          try {
            map = normalizeMap(JSON.parse(localStorage.getItem(CW_KEY) || "{}") || {});
          } catch (e) {
            map = {};
          }
          // merge cookie too
          try {
            readCookieMirror().forEach(function (row) {
              var k = rowKey(row);
              if (!k) return;
              if (!map[k] || (Number(row.updated) || 0) > (Number(map[k].updated) || 0)) map[k] = row;
            });
          } catch (e2) {}
          serverItems.forEach(function (item) {
            if (!item || !item.key) return;
            var cur = map[item.key];
            var row = {
              id: item.id,
              type: item.type === "tv" ? "tv" : "movie",
              title: item.title || "",
              poster: item.poster || "",
              backdrop: item.backdrop || "",
              year: item.year || "",
              season: item.season,
              episode: item.episode,
              t: item.t || 0,
              d: item.d || 0,
              updated: (Number(item.updated) || 0) < 1e12 ? (Number(item.updated) || 0) * 1000 : Number(item.updated) || Date.now(),
            };
            if (!cur || (Number(row.updated) || 0) >= (Number(cur.updated) || 0) || (Number(row.t) || 0) > (Number(cur.t) || 0) + 2) {
              map[item.key] = Object.assign({}, cur || {}, row);
            }
          });
          try {
            localStorage.setItem(CW_KEY, JSON.stringify(map));
          } catch (e3) {}
          try {
            var keys = Object.keys(map).sort(function (a, b) {
              return (map[b].updated || 0) - (map[a].updated || 0);
            });
            var compact = keys.slice(0, 16).map(function (k) {
              var row = map[k] || {};
              return {
                id: row.id, type: row.type, title: row.title || "",
                poster: row.poster || "", backdrop: "",
                year: row.year || "", season: row.season, episode: row.episode,
                t: row.t || 0, d: row.d || 0, updated: row.updated || Date.now()
              };
            });
            document.cookie =
              "cf_continue_v1=" +
              encodeURIComponent(JSON.stringify(compact)) +
              ";path=/;max-age=31536000;SameSite=Lax";
          } catch (e4) {}
          renderContinueRail();
        })
        .catch(function () {});
    } catch (e) {}
  }
'''
    # insert before window.ChillflixContinue export
    if "window.ChillflixContinue" not in ct:
        raise SystemExit("ChillflixContinue export missing")
    ct = ct.replace(
        "  window.ChillflixContinue = { render: renderContinueRail, list: readContinue };",
        pull + "\n  window.ChillflixContinue = { render: renderContinueRail, list: readContinue, pull: pullServerContinue };",
        1,
    )
    # call on boot beside renderContinueRail
    ct = ct.replace(
        "    renderContinueRail();\n",
        "    renderContinueRail();\n    pullServerContinue();\n",
        1,
    )
    cp.write_text(ct)
    print("continue-party server pull added")
else:
    print("continue-party already has server pull")

# ---------- routes.php: bump watch page player.js cache buster ----------
routes = root / "app/routes.php"
rt = routes.read_text()
rt2 = rt.replace(
    "asset('css/player.css') . '?v=20260801-ui67'",
    f"asset('css/player.css') . '?v={ver}'",
)
rt2 = rt2.replace(
    "asset('js/player.js') . '?v=20260801-ui67'",
    f"asset('js/player.js') . '?v={ver}'",
)
# also any other ui67 player pins
rt2 = re.sub(
    r"(asset\('js/player\.js'\)\s*\.\s*'\?v=)[^']+'",
    rf"\g<1>{ver}'",
    rt2,
)
rt2 = re.sub(
    r"(asset\('css/player\.css'\)\s*\.\s*'\?v=)[^']+'",
    rf"\g<1>{ver}'",
    rt2,
)
if rt2 == rt and ver not in rt:
    raise SystemExit("routes.php player pin replace failed")
routes.write_text(rt2)
print("routes.php player pins ->", ver)

# bump layout asset versions
for rel in ["app/Views/layouts/main.php", "app/Views/layouts/player.php"]:
    p = root / rel
    t = p.read_text()
    t2 = re.sub(r"(\?v=)20260[0-9]{3}-ui[0-9]+", r"\g<1>" + ver, t)
    t2 = re.sub(r"(js/player\.js\?v=)[^\"&]+", r"\g<1>" + ver, t2)
    t2 = re.sub(r"(js/continue-party\.js\?v=)[^\"&]+", r"\g<1>" + ver, t2)
    t2 = re.sub(r"(js/app\.js\?v=)[^\"&]+", r"\g<1>" + ver, t2)
    t2 = re.sub(r"(css/continue-party\.css\?v=)[^\"&]+", r"\g<1>" + ver, t2)
    p.write_text(t2)
    print("bumped", rel)

# sanity
print("OK boot", "ns-boot-sync-ui102" in aj.read_text())
print("OK no dead boot", "ns-boot-sync-ui100" not in aj.read_text() or "ui102" in aj.read_text())
print("OK pull", "cw-server-pull-ui102" in cp.read_text())
print("OK routes", ver in routes.read_text())
print("DONE", ver)
