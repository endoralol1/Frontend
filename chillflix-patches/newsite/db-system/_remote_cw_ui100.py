#!/usr/bin/env python3
"""Remote patch: continue-watching DB sync, multi-item, resume, per-episode."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
ver = "20260802-ui100"

# ---------- player.js ----------
pj = root / "public/assets/js/player.js"
pt = pj.read_text()

CW_BLOCK = r'''
  const CW_PUSH_MIN_MS = 12000;
  const cwPushAt = Object.create(null);
  let cwAuthed = null; // null unknown, true/false after first API response

  function cwApiBase() {
    return (window.APP && APP.baseUrl) || "";
  }

  function cwPushContinue(item, force) {
    try {
      if (!item || !item.key) return;
      if (cwAuthed === false) return;
      const now = Date.now();
      const last = cwPushAt[item.key] || 0;
      if (!force && now - last < CW_PUSH_MIN_MS) return;
      cwPushAt[item.key] = now;
      const payload = {
        key: item.key,
        type: item.type === "tv" ? "tv" : "movie",
        id: item.id,
        season: item.season,
        episode: item.episode,
        title: item.title || "",
        poster: item.poster || "",
        backdrop: item.backdrop || "",
        year: item.year || "",
        t: item.t || 0,
        d: item.d || 0,
      };
      if (typeof window.nsPushContinue === "function") {
        window.nsPushContinue(payload);
        return;
      }
      fetch(cwApiBase() + "/api/user/continue", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(payload),
        keepalive: !!force,
      })
        .then((r) => {
          if (r.status === 401) cwAuthed = false;
          else if (r.ok) cwAuthed = true;
        })
        .catch(() => {});
    } catch (_) {}
  }

  function cwRemoveContinue(key) {
    try {
      if (!key || cwAuthed === false) return;
      if (typeof window.nsRemoveContinue === "function") {
        window.nsRemoveContinue(key);
        return;
      }
      fetch(cwApiBase() + "/api/user/continue/remove", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ key: key }),
        keepalive: true,
      })
        .then((r) => {
          if (r.status === 401) cwAuthed = false;
          else if (r.ok) cwAuthed = true;
        })
        .catch(() => {});
    } catch (_) {}
  }

  function cwSave(cfg, watched, duration, opts) {
    try {
      if (localStorage.getItem("cf_pref_continue") === "0") return false;
    } catch (_) {}
    const id = Number(cfg.id) || 0;
    if (!id) return false;
    const seed = !!(opts && opts.seed);
    const forcePush = !!(opts && opts.forcePush);
    let t = Number(watched) || 0;
    let d = Number(duration) || 0;
    if (!Number.isFinite(d) || d === Infinity) d = 0;
    // Seed on Watch Now / first play; otherwise require ~1s so rail fills quickly
    if (seed) t = Math.max(t, 1);
    else if (t < 1) return false;
    const key = cwProgressKey(cfg);
    if (!seed && d > 0 && (d - t < 90 || t / d > 0.96)) {
      // finished — drop from continue list
      const map = cwReadAll();
      delete map[key];
      cwWriteAll(map);
      cwRemoveContinue(key);
      return true;
    }
    const map = cwReadAll();
    const prev = map[key] || {};
    // Per-episode keys (tv:{id}:sXeY) keep each episode independent.
    // Use current position (not max) so resume matches where the user left off.
    const entry = {
      id,
      type: cfg.type === "tv" ? "tv" : "movie",
      title: cfg.title || prev.title || "Untitled",
      poster: cfg.poster || prev.poster || "",
      backdrop: cfg.backdrop || prev.backdrop || "",
      year: cfg.year || prev.year || "",
      season: cfg.type === "tv" ? Number(cfg.season) || 1 : null,
      episode: cfg.type === "tv" ? Number(cfg.episode) || 1 : null,
      t: seed ? Math.max(t, Number(prev.t) || 0) : t,
      d: d || Number(prev.d) || 0,
      updated: Date.now(),
      url: cfg.watchUrl || cfg.backUrl || prev.url || location.pathname + location.search,
    };
    map[key] = entry;
    // prune oldest
    const keys = Object.keys(map).sort((a, b) => (map[b].updated || 0) - (map[a].updated || 0));
    keys.slice(CW_MAX).forEach((k) => delete map[k]);
    cwWriteAll(map);
    cwPushContinue(Object.assign({ key }, entry), forcePush || seed);
    return true;
  }
'''

# Drop dead nsMaybePushContinue helper if present, then replace cwSave
pt = re.sub(
    r"\n\s*function nsMaybePushContinue\(item\) \{[\s\S]*?\n\s*\}\n",
    "\n",
    pt,
    count=1,
)

pt2, n = re.subn(
    r"\n\s*function cwSave\(cfg, watched, duration, opts\) \{[\s\S]*?\n\s*\}\n\s*function cwSeed",
    "\n" + CW_BLOCK + "\n  function cwSeed",
    pt,
    count=1,
)
if n != 1:
    # debug helpers
    i = pt.find("function cwSave")
    j = pt.find("function cwSeed")
    raise SystemExit(
        f"player.js cwSave replace failed n={n} cwSave@{i} cwSeed@{j} snippet={pt[i:i+120]!r}"
    )
pt = pt2

# Force server push on forced local saves (pause/seek/pagehide/seed path via opts)
old_maybe = '''      const maybeSaveProgress = (force, seed) => {
        const now = Date.now();
        const t = v.currentTime || 0;
        // First eligible save should not wait on the throttle
        const firstOk = (seed || t >= 1) && state.lastCwSave === 0;
        if (!force && !seed && !firstOk && now - state.lastCwSave < 4000) return;
        const ok = cwSave(cfg, Math.max(t, seed ? 1 : 0), v.duration, seed ? { seed: true } : null);
        if (ok) state.lastCwSave = now;
      };'''

new_maybe = '''      const maybeSaveProgress = (force, seed) => {
        const now = Date.now();
        const t = v.currentTime || 0;
        // First eligible save should not wait on the throttle
        const firstOk = (seed || t >= 1) && state.lastCwSave === 0;
        if (!force && !seed && !firstOk && now - state.lastCwSave < 4000) return;
        const opts = {};
        if (seed) opts.seed = true;
        if (force || seed) opts.forcePush = true;
        const ok = cwSave(cfg, Math.max(t, seed ? 1 : 0), v.duration, opts);
        if (ok) state.lastCwSave = now;
      };'''

if old_maybe not in pt:
    raise SystemExit("maybeSaveProgress block not found")
pt = pt.replace(old_maybe, new_maybe)

pt = pt.replace(
    "if (v) cwSave(cfg, v.currentTime, v.duration);",
    "if (v) cwSave(cfg, v.currentTime, v.duration, { forcePush: true });",
)

if "cwPushContinue(Object.assign({ key }" not in pt:
    raise SystemExit("player.js missing push call")
pj.write_text(pt)
print("player.js patched")

# ---------- continue-party.js ----------
cp = root / "public/assets/js/continue-party.js"
ct = cp.read_text()
new_href = '''  function watchHref(row) {
    // Rebuild from type/id/season/episode so each CW card resumes THAT episode,
    // not a stale stored URL from another episode choice.
    if (!row || !row.id) return base() || "/";
    var slug = String(row.title || "title")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/(^-|-$)/g, "");
    var url =
      base() +
      "/" +
      (row.type === "tv" ? "tv" : "movie") +
      "/" +
      (slug || "title") +
      "/" +
      row.id;
    if (row.type === "tv") {
      url += "?s=" + (row.season || 1) + "&e=" + (row.episode || 1);
    }
    var sep = url.indexOf("?") >= 0 ? "&" : "?";
    var t = Math.floor(Number(row.t) || 0);
    if (t >= 5) url += sep + "t=" + t + "&play=1";
    else url += sep + "play=1";
    return url;
  }'''

ct2, n = re.subn(
    r"  function watchHref\(row\) \{[\s\S]*?\n  \}\n\n  function pct",
    new_href + "\n\n  function pct",
    ct,
    count=1,
)
if n != 1:
    raise SystemExit(f"continue-party watchHref failed n={n}")
cp.write_text(ct2)
print("continue-party.js patched")

# ---------- app.js ----------
aj = root / "public/assets/js/app.js"
at = aj.read_text()

at = at.replace(
    "      // Quota / blocked: free continue-watching cache and retry once\n      try { localStorage.removeItem('cf_continue_v1'); } catch (e) {}\n",
    "      // Quota / blocked: retry once without wiping continue-watching\n",
)

NEW_SYNC = r'''
  function nsNormUpdated(v) {
    var n = Number(v) || 0;
    if (n > 0 && n < 1e12) n *= 1000; // seconds → ms
    return n;
  }

  function nsPushContinueItem(item) {
    if (!item) return Promise.resolve();
    var payload = {
      key: item.key || item._key || undefined,
      type: item.type === 'tv' ? 'tv' : 'movie',
      id: item.id,
      season: item.season,
      episode: item.episode,
      title: item.title || '',
      poster: item.poster || '',
      backdrop: item.backdrop || '',
      year: item.year || '',
      t: item.t || 0,
      d: item.d || 0
    };
    return authApi('/api/user/continue', { method: 'POST', body: JSON.stringify(payload) })
      .catch(function () {});
  }

  function nsRemoveContinue(key) {
    if (!key) return Promise.resolve();
    return authApi('/api/user/continue/remove', {
      method: 'POST', body: JSON.stringify({ key: key })
    }).catch(function () {});
  }

  function nsMergeContinueMaps(localMap, serverItems) {
    var local = localMap && typeof localMap === 'object' ? localMap : {};
    var server = {};
    (serverItems || []).forEach(function (item) {
      if (!item || !item.key) return;
      server[item.key] = {
        id: item.id,
        type: item.type === 'tv' ? 'tv' : 'movie',
        title: item.title || '',
        poster: item.poster || '',
        backdrop: item.backdrop || '',
        year: item.year || '',
        season: item.season,
        episode: item.episode,
        t: item.t || 0,
        d: item.d || 0,
        updated: nsNormUpdated(item.updated)
      };
    });
    var keys = {};
    Object.keys(local).forEach(function (k) { keys[k] = 1; });
    Object.keys(server).forEach(function (k) { keys[k] = 1; });
    var merged = {};
    var toPush = [];
    Object.keys(keys).forEach(function (k) {
      var L = local[k];
      var S = server[k];
      if (L && !S) { merged[k] = L; toPush.push(Object.assign({ key: k }, L)); return; }
      if (S && !L) { merged[k] = S; return; }
      var lt = Number(L.t) || 0, st = Number(S.t) || 0;
      var lu = nsNormUpdated(L.updated), su = nsNormUpdated(S.updated);
      // Prefer more progress; tie-break by newer update. Keeps per-episode rows intact.
      var pickLocal = (lt > st + 2) || (Math.abs(lt - st) <= 2 && lu >= su);
      merged[k] = pickLocal ? L : Object.assign({}, S, { url: L.url || S.url });
      if (pickLocal && lu > su) toPush.push(Object.assign({ key: k }, L));
    });
    var ordered = Object.keys(merged).sort(function (a, b) {
      return (nsNormUpdated(merged[b].updated) || 0) - (nsNormUpdated(merged[a].updated) || 0);
    });
    ordered.slice(36).forEach(function (k) { delete merged[k]; });
    return { merged: merged, toPush: toPush };
  }

  function nsSyncLibrary() {
    return authApi('/api/user/library', { method: 'GET' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.user) return null;
        try {
          if (typeof favStore === 'function' && typeof saveFavs === 'function') {
            var map = {};
            (data.favorites || []).forEach(function (f) {
              var type = f.type === 'tv' ? 'tv' : 'movie';
              var id = String(f.id || '');
              if (!id) return;
              map[id] = {
                id: id,
                type: type,
                mediaType: type,
                title: f.title || '',
                name: f.title || '',
                poster: f.poster || '',
                poster_path: '',
                year: f.year || '',
                saved_at: new Date((f.updated || Date.now()) * (String(f.updated).length < 13 ? 1000 : 1)).toISOString()
              };
            });
            try { saveFavs(map); } catch (eSave) {
              try { localStorage.setItem('user_bookmarks', JSON.stringify(map)); } catch (e) {}
            }
            try { if (typeof renderFavoritesPage === 'function') renderFavoritesPage(); } catch (e2) {}
          }

          var localCw = {};
          try { localCw = JSON.parse(localStorage.getItem('cf_continue_v1') || '{}') || {}; } catch (eL) { localCw = {}; }
          var merge = nsMergeContinueMaps(localCw, data.continueWatching || []);
          try { localStorage.setItem('cf_continue_v1', JSON.stringify(merge.merged)); } catch (e3) {}
          // Upload only local-newer / local-only rows (max 12 per sync)
          (merge.toPush || []).slice(0, 12).forEach(function (item) {
            nsPushContinueItem(item);
          });
          try {
            if (window.ChillflixContinue && typeof window.ChillflixContinue.render === 'function') {
              window.ChillflixContinue.render();
            }
          } catch (e4) {}

          var u = data.user;
          try { localStorage.setItem('cf_watch_autoplay', u.autoplayEnabled ? '1' : '0'); } catch (e5) {}
          try { localStorage.setItem('cf_np_autonext', u.autoNextEnabled ? '1' : '0'); } catch (e6) {}
          try { localStorage.setItem('cf_pref_watchlist', u.watchlistEnabled ? '1' : '0'); } catch (e7) {}
          try { localStorage.setItem('cf_pref_continue', u.continueEnabled ? '1' : '0'); } catch (e8) {}
          try { if (u.language) localStorage.setItem('cf_lang', u.language); } catch (e9) {}
          try { if (typeof applyBrowsePrefs === 'function') applyBrowsePrefs(); } catch (e10) {}
          try { if (typeof syncBrowseSettingsUi === 'function') syncBrowseSettingsUi(); } catch (e11) {}
        } catch (err) { console.warn('ns sync failed', err); }
        return data;
      });
  }

  function nsPushPrefs(partial) {
    return authApi('/api/user/prefs', { method: 'POST', body: JSON.stringify(partial || {}) })
      .catch(function () {});
  }

  function nsPushFavorite(item, removing) {
    if (removing) {
      return authApi('/api/user/favorites/remove', {
        method: 'POST', body: JSON.stringify({ type: item.type, id: item.id })
      }).catch(function () {});
    }
    return authApi('/api/user/favorites', {
      method: 'POST', body: JSON.stringify(item)
    }).catch(function () {});
  }

  window.nsSyncLibrary = nsSyncLibrary;
  window.nsPushPrefs = nsPushPrefs;
  window.nsPushFavorite = nsPushFavorite;
  window.nsPushContinue = nsPushContinueItem;
  window.nsRemoveContinue = nsRemoveContinue;
'''

at2, n = re.subn(
    r"\n  function nsSyncLibrary\(\) \{[\s\S]*?window\.nsPushContinue = function \(item\) \{[\s\S]*?\n  \};\n",
    "\n" + NEW_SYNC + "\n",
    at,
    count=1,
)
if n != 1:
    raise SystemExit(f"app.js sync block replace failed n={n}")
at = at2

old_apply = """  function applyBrowseAuthUser(user) {
    browseAuthUser = user || null;
    if (user) {
      $('#browse-auth-guest').attr('hidden', true);
      $('#browse-auth-user').removeAttr('hidden');
      var name = user.name || user.email || 'Member';
      $('#browse-auth-name').text(name);
      $('#browse-auth-email').text(user.email || '');
      $('#browse-auth-avatar').text(String(name).charAt(0).toUpperCase());
    } else {
      $('#browse-auth-user').attr('hidden', true);
      $('#browse-auth-guest').removeAttr('hidden');
    }
    try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e) {}
  }"""

new_apply = """  function applyBrowseAuthUser(user) {
    browseAuthUser = user || null;
    if (user) {
      $('#browse-auth-guest').attr('hidden', true);
      $('#browse-auth-user').removeAttr('hidden');
      var name = user.name || user.email || 'Member';
      $('#browse-auth-name').text(name);
      $('#browse-auth-email').text(user.email || '');
      $('#browse-auth-avatar').text(String(name).charAt(0).toUpperCase());
      try { nsSyncLibrary(); } catch (eSync) {}
    } else {
      $('#browse-auth-user').attr('hidden', true);
      $('#browse-auth-guest').removeAttr('hidden');
    }
    try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e) {}
  }"""

if old_apply not in at:
    raise SystemExit("applyBrowseAuthUser block not found")
at = at.replace(old_apply, new_apply)

if "/* ns-boot-sync-ui100 */" not in at:
    boot = """
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
    idx = at.find("/* newsite-admin-menu")
    if idx < 0:
        idx = at.find("/* newsite-admin-link")
    if idx > 0:
        at = at[:idx] + boot + "\n" + at[idx:]
    else:
        at = at + "\n" + boot + "\n"

aj.write_text(at)
print("app.js patched")

# bump assets
for rel in [
    "app/Views/layouts/main.php",
    "app/Views/layouts/player.php",
]:
    p = root / rel
    if not p.exists():
        print("missing", rel)
        continue
    t = p.read_text()
    t2 = re.sub(r"(\?v=)20260[0-9]{3}-ui[0-9]+", r"\g<1>" + ver, t)
    t2 = re.sub(r"(js/player\.js\?v=)[^\"&]+", r"\g<1>" + ver, t2)
    t2 = re.sub(r"(js/continue-party\.js\?v=)[^\"&]+", r"\g<1>" + ver, t2)
    t2 = re.sub(r"(js/app\.js\?v=)[^\"&]+", r"\g<1>" + ver, t2)
    p.write_text(t2)
    print("bumped", rel)

print("OK player push", "cwPushContinue(Object.assign({ key }" in pj.read_text())
print("OK merge", "nsMergeContinueMaps" in aj.read_text())
print("OK boot", "ns-boot-sync-ui100" in aj.read_text())
print("OK href", "Rebuild from type/id/season/episode" in cp.read_text())
print("OK no wipe", "without wiping continue-watching" in aj.read_text())
print("DONE", ver)
