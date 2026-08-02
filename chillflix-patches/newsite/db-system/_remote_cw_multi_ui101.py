#!/usr/bin/env python3
"""ui101: Keep multiple Continue Watching items (hydrate from cookie before save)."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
ver = "20260802-ui101"

# ---------- shared JS helpers (player + continue-party + watch inline) ----------
# player.js: hydrate cwReadAll from cookie mirror so a new watch cannot wipe prior titles
pj = root / "public/assets/js/player.js"
pt = pj.read_text()

old_read = '''  function cwReadAll() {
    try {
      const raw = localStorage.getItem(CW_KEY);
      const data = raw ? JSON.parse(raw) : {};
      return data && typeof data === "object" ? data : {};
    } catch (_) {
      return {};
    }
  }'''

new_read = '''  function cwRowKey(row) {
    if (!row || !row.id) return "";
    if (row.type === "tv") {
      return "tv:" + row.id + ":s" + (row.season || 1) + "e" + (row.episode || 1);
    }
    return "movie:" + row.id;
  }

  function cwNormalizeMap(input) {
    const out = Object.create(null);
    if (!input) return out;
    if (Array.isArray(input)) {
      input.forEach((row) => {
        if (!row || !row.id) return;
        const k = cwRowKey(row);
        if (!k) return;
        const prev = out[k];
        if (!prev || (Number(row.updated) || 0) >= (Number(prev.updated) || 0)) out[k] = row;
      });
      return out;
    }
    if (typeof input !== "object") return out;
    // Already a keyed map — or a single flat row mistakenly stored as the root.
    if (input.id && (input.type === "tv" || input.type === "movie" || input.t != null)) {
      const k = cwRowKey(input);
      if (k) out[k] = input;
      return out;
    }
    Object.keys(input).forEach((k) => {
      const row = input[k];
      if (!row || typeof row !== "object" || !row.id) return;
      const nk = row._key || k || cwRowKey(row);
      if (!nk || nk === "id" || nk === "title" || nk === "type") {
        const rk = cwRowKey(row);
        if (rk) out[rk] = row;
        return;
      }
      out[nk] = row;
    });
    return out;
  }

  function cwReadCookieMirror() {
    try {
      const m = document.cookie.match(/(?:^|;\\s*)cf_continue_v1=([^;]+)/);
      if (!m) return [];
      const arr = JSON.parse(decodeURIComponent(m[1]));
      return Array.isArray(arr) ? arr.filter(Boolean) : [];
    } catch (_) {
      return [];
    }
  }

  function cwReadAll() {
    let map = Object.create(null);
    try {
      const raw = localStorage.getItem(CW_KEY);
      map = cwNormalizeMap(raw ? JSON.parse(raw) : {});
    } catch (_) {
      map = Object.create(null);
    }
    // CRITICAL: if LS is empty/flaky, merge cookie mirror so saving a new title
    // does not wipe every previously watched item from the cookie-only store.
    const cookieItems = cwReadCookieMirror();
    if (cookieItems.length) {
      const fromCookie = cwNormalizeMap(cookieItems);
      Object.keys(fromCookie).forEach((k) => {
        const C = fromCookie[k];
        const L = map[k];
        if (!L || (Number(C.updated) || 0) > (Number(L.updated) || 0)) map[k] = C;
      });
    }
    return map;
  }'''

if old_read not in pt:
    raise SystemExit("player.js cwReadAll block not found")
pt = pt.replace(old_read, new_read, 1)

# Also persist normalized map back to LS whenever we hydrate from cookie
old_write = '''  function cwWriteAll(map) {
    try {
      localStorage.setItem(CW_KEY, JSON.stringify(map));
    } catch (_) {}
    // cookie mirror for home recovery
    try {
      const keys = Object.keys(map || {}).sort(
        (a, b) => (map[b].updated || 0) - (map[a].updated || 0)
      );
      const compact = keys.slice(0, 8).map((k) => map[k]).filter(Boolean);
      document.cookie =
        "cf_continue_v1=" +
        encodeURIComponent(JSON.stringify(compact)) +
        ";path=/;max-age=31536000;SameSite=Lax";
    } catch (_) {}
  }'''

new_write = '''  function cwWriteAll(map) {
    map = cwNormalizeMap(map);
    try {
      localStorage.setItem(CW_KEY, JSON.stringify(map));
    } catch (_) {}
    // cookie mirror for home recovery (keep newest 12; omit huge art URLs)
    try {
      const keys = Object.keys(map || {}).sort(
        (a, b) => (map[b].updated || 0) - (map[a].updated || 0)
      );
      const compact = keys.slice(0, 12).map((k) => {
        const row = map[k] || {};
        return {
          id: row.id,
          type: row.type,
          title: row.title,
          poster: row.poster || "",
          backdrop: row.backdrop || "",
          year: row.year || "",
          season: row.season,
          episode: row.episode,
          t: row.t || 0,
          d: row.d || 0,
          updated: row.updated || Date.now(),
        };
      }).filter((r) => r && r.id);
      document.cookie =
        "cf_continue_v1=" +
        encodeURIComponent(JSON.stringify(compact)) +
        ";path=/;max-age=31536000;SameSite=Lax";
    } catch (_) {}
  }'''

if old_write not in pt:
    raise SystemExit("player.js cwWriteAll block not found")
pt = pt.replace(old_write, new_write, 1)
pj.write_text(pt)
print("player.js patched")

# ---------- continue-party.js ----------
cp = root / "public/assets/js/continue-party.js"
ct = cp.read_text()

old_rc = '''  function readContinue() {
    try {
      var raw = localStorage.getItem(CW_KEY);
      var map = raw ? JSON.parse(raw) : {};
      if (!map || typeof map !== "object") map = {};
      var items = Object.keys(map)
        .map(function (k) {
          var row = map[k];
          if (!row) return null;
          row._key = k;
          return row;
        })
        .filter(Boolean);
      // Recover from cookie mirror if LS empty (private mode quirks / wiped LS)
      if (!items.length) {
        var cookieItems = readCookieMirror();
        if (cookieItems.length) {
          cookieItems.forEach(function (row) {
            if (!row || !row.id) return;
            var k =
              (row.type === "tv" ? "tv" : "movie") +
              ":" +
              row.id +
              (row.type === "tv"
                ? ":s" + (row.season || 1) + "e" + (row.episode || 1)
                : "");
            map[k] = row;
            row._key = k;
            items.push(row);
          });
          try {
            localStorage.setItem(CW_KEY, JSON.stringify(map));
          } catch (e2) {}
        }
      }
      return items.sort(function (a, b) {
        return (b.updated || 0) - (a.updated || 0);
      });
    } catch (e) {
      return readCookieMirror();
    }
  }'''

new_rc = '''  function rowKey(row) {
    if (!row || !row.id) return "";
    if (row.type === "tv") {
      return "tv:" + row.id + ":s" + (row.season || 1) + "e" + (row.episode || 1);
    }
    return "movie:" + row.id;
  }

  function normalizeMap(input) {
    var out = {};
    if (!input) return out;
    if (Array.isArray(input)) {
      input.forEach(function (row) {
        if (!row || !row.id) return;
        var k = rowKey(row);
        if (!k) return;
        var prev = out[k];
        if (!prev || (Number(row.updated) || 0) >= (Number(prev.updated) || 0)) out[k] = row;
      });
      return out;
    }
    if (typeof input !== "object") return out;
    if (input.id && (input.type === "tv" || input.type === "movie" || input.t != null)) {
      var sk = rowKey(input);
      if (sk) out[sk] = input;
      return out;
    }
    Object.keys(input).forEach(function (k) {
      var row = input[k];
      if (!row || typeof row !== "object" || !row.id) return;
      var nk = row._key || rowKey(row) || k;
      out[nk] = row;
    });
    return out;
  }

  function readContinue() {
    try {
      var raw = localStorage.getItem(CW_KEY);
      var map = normalizeMap(raw ? JSON.parse(raw) : {});
      var cookieItems = readCookieMirror();
      if (cookieItems.length) {
        var fromCookie = normalizeMap(cookieItems);
        Object.keys(fromCookie).forEach(function (k) {
          var C = fromCookie[k];
          var L = map[k];
          if (!L || (Number(C.updated) || 0) > (Number(L.updated) || 0)) map[k] = C;
        });
      }
      try {
        if (Object.keys(map).length) localStorage.setItem(CW_KEY, JSON.stringify(map));
      } catch (e2) {}
      return Object.keys(map)
        .map(function (k) {
          var row = map[k];
          if (!row || !row.id) return null;
          row._key = k;
          return row;
        })
        .filter(Boolean)
        .sort(function (a, b) {
          return (b.updated || 0) - (a.updated || 0);
        });
    } catch (e) {
      return readCookieMirror();
    }
  }'''

if old_rc not in ct:
    raise SystemExit("continue-party readContinue not found")
ct = ct.replace(old_rc, new_rc, 1)

# Show count in section title when multiple
ct2, n = re.subn(
    r"\$rail\.removeAttr\(\"hidden\"\)\.removeClass\(\"d-none\"\);\n    \$rail\.css\(\"display\", \"block\"\);\n    \$track\.addClass\(\"cw-track\"\);",
    '''$rail.removeAttr("hidden").removeClass("d-none");
    $rail.css("display", "block");
    $track.addClass("cw-track");
    try {
      var $title = $rail.find(".head .title");
      if ($title.length) {
        $title.text(items.length > 1 ? ("Continue Watching · " + items.length) : "Continue Watching");
      }
    } catch (eTitle) {}''',
    ct,
    count=1,
)
if n != 1:
    raise SystemExit("continue-party title patch failed")
ct = ct2
cp.write_text(ct)
print("continue-party.js patched")

# ---------- CSS: peek next card on mobile + force horizontal scroll ----------
css = root / "public/assets/css/continue-party.css"
cs = css.read_text()
if "ui101-cw-multi" not in cs:
    cs += '''

/* ui101-cw-multi: keep rail scrollable and peek the next card */
#continue-watching .media-rail-items.cw-track {
  overflow-x: auto !important;
  overflow-y: hidden !important;
  -webkit-overflow-scrolling: touch !important;
  scroll-snap-type: x proximity;
  padding-right: 1.25rem !important;
}
#continue-watching .cw-ep.episode-card {
  scroll-snap-align: start;
}
@media (max-width: 991.98px) {
  #continue-watching .cw-ep.episode-card {
    flex: 0 0 min(18.5rem, 72vw);
    width: min(18.5rem, 72vw);
    min-width: min(18.5rem, 72vw);
    max-width: 72vw;
  }
}
'''
    css.write_text(cs)
    print("continue-party.css patched")
else:
    print("continue-party.css already patched")

# ---------- app.js: seed + sync normalize ----------
aj = root / "public/assets/js/app.js"
at = aj.read_text()

# Normalize in nsSyncLibrary local read
at2 = at.replace(
    """          var localCw = {};
          try { localCw = JSON.parse(localStorage.getItem('cf_continue_v1') || '{}') || {}; } catch (eL) { localCw = {}; }
          var merge = nsMergeContinueMaps(localCw, data.continueWatching || []);""",
    """          var localCw = {};
          try { localCw = JSON.parse(localStorage.getItem('cf_continue_v1') || '{}') || {}; } catch (eL) { localCw = {}; }
          // Normalize array / flat-row shapes left by cookie mirrors
          if (Array.isArray(localCw) || (localCw && localCw.id && !localCw.movie && !localCw.tv)) {
            var norm = {};
            var list = Array.isArray(localCw) ? localCw : [localCw];
            list.forEach(function (row) {
              if (!row || !row.id) return;
              var k = (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id +
                (row.type === 'tv' ? (':s' + (row.season || 1) + 'e' + (row.episode || 1)) : '');
              norm[k] = row;
            });
            localCw = norm;
          }
          // Also merge cookie mirror before sync so server merge cannot drop local-only history
          try {
            var cm = document.cookie.match(/(?:^|;\\s*)cf_continue_v1=([^;]+)/);
            if (cm) {
              var carr = JSON.parse(decodeURIComponent(cm[1]));
              if (Array.isArray(carr)) {
                carr.forEach(function (row) {
                  if (!row || !row.id) return;
                  var k = (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id +
                    (row.type === 'tv' ? (':s' + (row.season || 1) + 'e' + (row.episode || 1)) : '');
                  if (!localCw[k] || (Number(row.updated) || 0) > (Number(localCw[k].updated) || 0)) localCw[k] = row;
                });
              }
            }
          } catch (eCookie) {}
          var merge = nsMergeContinueMaps(localCw, data.continueWatching || []);""",
)
if at2 == at:
    raise SystemExit("app.js sync normalize patch failed")
at = at2

# Fix seedContinueFromPlayer to hydrate from cookie first
old_seed = '''  function seedContinueFromPlayer() {
    try {
      try { if (localStorage.getItem('cf_pref_continue') === '0') return; } catch (e0) {}
      var cfg = window.PLAYER;
      if (!cfg || !cfg.id) return;
      var key = (cfg.type === 'tv' ? 'tv' : 'movie') + ':' + cfg.id;
      if (cfg.type === 'tv') key += ':s' + (cfg.season || 1) + 'e' + (cfg.episode || 1);
      var map = {};
      try { map = JSON.parse(localStorage.getItem('cf_continue_v1') || '{}') || {}; } catch (e) { map = {}; }
      var prev = map[key] || {};
      map[key] = {
        id: Number(cfg.id) || 0,
        type: cfg.type === 'tv' ? 'tv' : 'movie',
        title: cfg.title || prev.title || 'Untitled',
        poster: cfg.poster || prev.poster || '',
        year: cfg.year || prev.year || '',
        season: cfg.type === 'tv' ? (Number(cfg.season) || 1) : null,
        episode: cfg.type === 'tv' ? (Number(cfg.episode) || 1) : null,
        t: Math.max(1, Number(prev.t) || 0),
        d: Number(prev.d) || 0,
        updated: Date.now(),
        url: cfg.watchUrl || cfg.backUrl || (location.pathname + location.search)
      };
      localStorage.setItem('cf_continue_v1', JSON.stringify(map));
    } catch (e) {}
  }'''

new_seed = '''  function seedContinueFromPlayer() {
    try {
      try { if (localStorage.getItem('cf_pref_continue') === '0') return; } catch (e0) {}
      var cfg = window.PLAYER;
      if (!cfg || !cfg.id) return;
      var key = (cfg.type === 'tv' ? 'tv' : 'movie') + ':' + cfg.id;
      if (cfg.type === 'tv') key += ':s' + (cfg.season || 1) + 'e' + (cfg.episode || 1);
      var map = {};
      try { map = JSON.parse(localStorage.getItem('cf_continue_v1') || '{}') || {}; } catch (e) { map = {}; }
      if (Array.isArray(map)) {
        var tmp = {};
        map.forEach(function (row) {
          if (!row || !row.id) return;
          var k = (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id +
            (row.type === 'tv' ? (':s' + (row.season || 1) + 'e' + (row.episode || 1)) : '');
          tmp[k] = row;
        });
        map = tmp;
      }
      try {
        var cm = document.cookie.match(/(?:^|;\\s*)cf_continue_v1=([^;]+)/);
        if (cm) {
          var carr = JSON.parse(decodeURIComponent(cm[1]));
          if (Array.isArray(carr)) {
            carr.forEach(function (row) {
              if (!row || !row.id) return;
              var k = (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id +
                (row.type === 'tv' ? (':s' + (row.season || 1) + 'e' + (row.episode || 1)) : '');
              if (!map[k]) map[k] = row;
            });
          }
        }
      } catch (eC) {}
      var prev = map[key] || {};
      map[key] = {
        id: Number(cfg.id) || 0,
        type: cfg.type === 'tv' ? 'tv' : 'movie',
        title: cfg.title || prev.title || 'Untitled',
        poster: cfg.poster || prev.poster || '',
        year: cfg.year || prev.year || '',
        season: cfg.type === 'tv' ? (Number(cfg.season) || 1) : null,
        episode: cfg.type === 'tv' ? (Number(cfg.episode) || 1) : null,
        t: Math.max(1, Number(prev.t) || 0),
        d: Number(prev.d) || 0,
        updated: Date.now(),
        url: cfg.watchUrl || cfg.backUrl || (location.pathname + location.search)
      };
      localStorage.setItem('cf_continue_v1', JSON.stringify(map));
      try {
        var keys = Object.keys(map).sort(function (a, b) {
          return (map[b].updated || 0) - (map[a].updated || 0);
        });
        var compact = keys.slice(0, 12).map(function (k) { return map[k]; });
        document.cookie = 'cf_continue_v1=' + encodeURIComponent(JSON.stringify(compact))
          + ';path=/;max-age=31536000;SameSite=Lax';
      } catch (eCookieSet) {}
      try {
        if (typeof window.nsPushContinue === 'function') {
          window.nsPushContinue(Object.assign({ key: key }, map[key]));
        }
      } catch (ePush) {}
    } catch (e) {}
  }'''

if old_seed not in at:
    raise SystemExit("seedContinueFromPlayer not found")
at = at.replace(old_seed, new_seed, 1)

# Soft-nav back to home should re-sync library for logged-in users
if "/* ns-softnav-cw-sync-ui101 */" not in at:
    at = at.replace(
        """  function afterSoftNav() {
    $('.movie-item.is-opening').removeClass('is-opening');
    updateFavCounter();
    initSwiper();
    initRecentlyUpdated();
    try {
      if (window.ChillflixContinue && typeof window.ChillflixContinue.render === 'function') {
        window.ChillflixContinue.render();
      }
      window.dispatchEvent(new Event('cf:softnav'));
    } catch (e) {}""",
        """  function afterSoftNav() {
    $('.movie-item.is-opening').removeClass('is-opening');
    updateFavCounter();
    initSwiper();
    initRecentlyUpdated();
    try {
      /* ns-softnav-cw-sync-ui101 */
      if (typeof nsSyncLibrary === 'function' && /\\/(home)(\\/|$|\\?)/.test(location.pathname || '')) {
        nsSyncLibrary();
      } else if (window.ChillflixContinue && typeof window.ChillflixContinue.render === 'function') {
        window.ChillflixContinue.render();
      }
      window.dispatchEvent(new Event('cf:softnav'));
    } catch (e) {}""",
    )

aj.write_text(at)
print("app.js patched")

# ---------- watch.php inline seed: hydrate cookie + push ----------
wp = root / "app/Views/pages/watch.php"
wt = wp.read_text()
old_inline = """            var map = {};
            try { map = JSON.parse(localStorage.getItem('cf_continue_v1') || '{}') || {}; } catch (e) { map = {}; }
            var prev = map[key] || {};
            map[key] = {
              id: Number(cfg.id) || 0,
              type: cfg.type === 'tv' ? 'tv' : 'movie',
              title: cfg.title || prev.title || 'Untitled',
              poster: cfg.poster || prev.poster || '',
              backdrop: cfg.backdrop || prev.backdrop || '',
              year: cfg.year || prev.year || '',
              season: cfg.type === 'tv' ? (Number(cfg.season) || 1) : null,
              episode: cfg.type === 'tv' ? (Number(cfg.episode) || 1) : null,
              t: Math.max(1, Number(prev.t) || 0),
              d: Number(prev.d) || 0,
              updated: Date.now(),
              url: cfg.watchUrl || cfg.backUrl || (location.pathname + location.search)
            };
            // keep newest 36
            var keys = Object.keys(map).sort(function (a, b) {
              return (map[b].updated || 0) - (map[a].updated || 0);
            });
            keys.slice(36).forEach(function (k) { delete map[k]; });
            localStorage.setItem('cf_continue_v1', JSON.stringify(map));
            // cookie mirror (compact) so home can recover if LS quirks
            try {
              var compact = keys.slice(0, 8).map(function (k) { return map[k]; });
              document.cookie = 'cf_continue_v1=' + encodeURIComponent(JSON.stringify(compact))
                + ';path=/;max-age=31536000;SameSite=Lax';
            } catch (e2) {}"""

new_inline = """            var map = {};
            try { map = JSON.parse(localStorage.getItem('cf_continue_v1') || '{}') || {}; } catch (e) { map = {}; }
            if (Array.isArray(map)) {
              var tmp = {};
              map.forEach(function (row) {
                if (!row || !row.id) return;
                var k = (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id +
                  (row.type === 'tv' ? (':s' + (row.season || 1) + 'e' + (row.episode || 1)) : '');
                tmp[k] = row;
              });
              map = tmp;
            }
            // Merge cookie mirror BEFORE writing so a new title cannot wipe history
            try {
              var cm = document.cookie.match(/(?:^|;\\s*)cf_continue_v1=([^;]+)/);
              if (cm) {
                var carr = JSON.parse(decodeURIComponent(cm[1]));
                if (Array.isArray(carr)) {
                  carr.forEach(function (row) {
                    if (!row || !row.id) return;
                    var k = (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id +
                      (row.type === 'tv' ? (':s' + (row.season || 1) + 'e' + (row.episode || 1)) : '');
                    if (!map[k]) map[k] = row;
                  });
                }
              }
            } catch (eC) {}
            var prev = map[key] || {};
            map[key] = {
              id: Number(cfg.id) || 0,
              type: cfg.type === 'tv' ? 'tv' : 'movie',
              title: cfg.title || prev.title || 'Untitled',
              poster: cfg.poster || prev.poster || '',
              backdrop: cfg.backdrop || prev.backdrop || '',
              year: cfg.year || prev.year || '',
              season: cfg.type === 'tv' ? (Number(cfg.season) || 1) : null,
              episode: cfg.type === 'tv' ? (Number(cfg.episode) || 1) : null,
              t: Math.max(1, Number(prev.t) || 0),
              d: Number(prev.d) || 0,
              updated: Date.now(),
              url: cfg.watchUrl || cfg.backUrl || (location.pathname + location.search)
            };
            // keep newest 36
            var keys = Object.keys(map).sort(function (a, b) {
              return (map[b].updated || 0) - (map[a].updated || 0);
            });
            keys.slice(36).forEach(function (k) { delete map[k]; });
            try { localStorage.setItem('cf_continue_v1', JSON.stringify(map)); } catch (eLs) {}
            // cookie mirror (compact) so home can recover if LS quirks
            try {
              var compact = keys.slice(0, 12).map(function (k) { return map[k]; });
              document.cookie = 'cf_continue_v1=' + encodeURIComponent(JSON.stringify(compact))
                + ';path=/;max-age=31536000;SameSite=Lax';
            } catch (e2) {}
            // Persist for logged-in users (player page may not load app.js)
            try {
              var base = (window.APP && APP.baseUrl) || '';
              fetch(base + '/api/user/continue', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(Object.assign({ key: key }, map[key])),
                keepalive: true
              }).catch(function () {});
            } catch (ePush) {}"""

if old_inline not in wt:
    raise SystemExit("watch.php inline seed block not found")
wp.write_text(wt.replace(old_inline, new_inline, 1))
print("watch.php patched")

# bump assets
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

print("OK", "cwNormalizeMap" in pj.read_text(), "normalizeMap" in cp.read_text(), "ui101-cw-multi" in css.read_text())
print("DONE", ver)
