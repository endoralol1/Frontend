#!/usr/bin/env python3
"""Make favorites save even when localStorage is blocked/full."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui57"

FAV_FUNCS = r'''
  var FAV_KEY = 'user_bookmarks';
  var FAV_COOKIE = 'cf_favs_v1';
  var favMemStore = null;
  var favPersistMode = 'local';

  function favCookieGet() {
    try {
      var m = document.cookie.match(/(?:^|;\s*)cf_favs_v1=([^;]*)/);
      if (!m) return null;
      return decodeURIComponent(m[1]);
    } catch (e) {
      return null;
    }
  }

  function favCookieSet(raw) {
    try {
      // 1 year; keep under typical 4kb cookie budget by compacting before call
      document.cookie = FAV_COOKIE + '=' + encodeURIComponent(raw) +
        '; path=/; max-age=31536000; SameSite=Lax';
      return true;
    } catch (e) {
      return false;
    }
  }

  function favCompact(map) {
    var out = {};
    Object.keys(map || {}).forEach(function (id) {
      var it = map[id] || {};
      var poster = String(it.poster || '');
      var path = it.poster_path || null;
      if (!path && poster) {
        var m = poster.match(/\/t\/p\/[^/]+(\/.+)$/);
        if (m) path = m[1];
      }
      out[id] = {
        id: String(it.id || id),
        type: (it.type || it.mediaType) === 'tv' ? 'tv' : 'movie',
        mediaType: (it.type || it.mediaType) === 'tv' ? 'tv' : 'movie',
        title: it.title || it.name || '',
        name: it.name || it.title || '',
        poster: path ? '' : poster,
        poster_path: path,
        year: it.year || '',
        saved_at: it.saved_at || new Date().toISOString()
      };
    });
    return out;
  }

  function favExpand(map) {
    var imgBase = (window.APP && APP.imgBase) ? APP.imgBase : 'https://image.tmdb.org/t/p';
    Object.keys(map || {}).forEach(function (id) {
      var it = map[id];
      if (!it) return;
      if (!it.poster && it.poster_path) {
        it.poster = imgBase + '/w185' + it.poster_path;
      }
      if (!it.mediaType) it.mediaType = it.type || 'movie';
      if (!it.type) it.type = it.mediaType || 'movie';
      if (!it.name) it.name = it.title || '';
      if (!it.title) it.title = it.name || '';
    });
    return map || {};
  }

  function favReadRaw() {
    // Prefer last-known good source order
    try {
      var ls = localStorage.getItem(FAV_KEY);
      if (ls) { favPersistMode = 'local'; return ls; }
    } catch (e) {}
    try {
      var ss = sessionStorage.getItem(FAV_KEY);
      if (ss) { favPersistMode = 'session'; return ss; }
    } catch (e) {}
    var ck = favCookieGet();
    if (ck) { favPersistMode = 'cookie'; return ck; }
    if (favMemStore) { favPersistMode = 'memory'; return favMemStore; }
    return null;
  }

  function favWriteRaw(raw) {
    favMemStore = raw;
    // 1) localStorage
    try {
      localStorage.setItem(FAV_KEY, raw);
      favPersistMode = 'local';
      return true;
    } catch (e1) {
      // Quota / blocked: free continue-watching cache and retry once
      try { localStorage.removeItem('cf_continue_v1'); } catch (e) {}
      try {
        localStorage.setItem(FAV_KEY, raw);
        favPersistMode = 'local';
        return true;
      } catch (e2) {}
    }
    // 2) sessionStorage
    try {
      sessionStorage.setItem(FAV_KEY, raw);
      favPersistMode = 'session';
      return true;
    } catch (e3) {}
    // 3) cookie (compact already)
    if (favCookieSet(raw)) {
      favPersistMode = 'cookie';
      return true;
    }
    // 4) memory-only for this page session
    favPersistMode = 'memory';
    return true;
  }

  function favStore() {
    try {
      var raw = favReadRaw();
      if (!raw) return {};
      return favExpand(JSON.parse(raw) || {});
    } catch (e) {
      return {};
    }
  }

  function saveFavs(map) {
    var compact = favCompact(map);
    var raw = JSON.stringify(compact);
    // If payload is huge, drop poster fields and retry compact
    if (raw.length > 3500) {
      Object.keys(compact).forEach(function (id) {
        compact[id].poster = '';
      });
      raw = JSON.stringify(compact);
    }
    var ok = favWriteRaw(raw);
    if (!ok) {
      showFavToast('Could not save favorite');
      return false;
    }
    updateFavCounter();
    return true;
  }

  function favIdFromBtn($btn) {
    var raw = $btn.attr('data-id');
    if (raw == null || raw === '') raw = $btn.data('id');
    return String(raw == null ? '' : raw);
  }

  function syncFavButton($btn) {
    var id = favIdFromBtn($btn);
    if (!id) return;
    var on = !!favStore()[id];
    $btn.toggleClass('is-fav', on);
    $btn.attr('aria-pressed', on ? 'true' : 'false');
    $btn.attr('aria-label', on ? 'Remove from favorites' : 'Add to favorites');
    var $icon = $btn.find('i').first();
    if ($icon.length) {
      $icon.removeClass('uil-plus-circle uil-minus-circle uil-heart uil-heart-break');
      $icon.addClass(on ? 'uil-heart' : 'uil-plus-circle');
    }
    var $label = $btn.find('span.ml-1, .fav-label').first();
    if ($label.length) {
      $label.text(on ? 'Favorited' : 'Favorite');
    }
  }

  function showFavToast(msg) {
    var $note = $('#cf-fav-toast');
    if (!$note.length) {
      $note = $('<div id="cf-fav-toast" class="cf-fav-toast" role="status" aria-live="polite"></div>').appendTo('body');
    }
    $note.text(msg).addClass('is-visible');
    clearTimeout(window.__cfFavToastTimer);
    window.__cfFavToastTimer = setTimeout(function () {
      $note.removeClass('is-visible');
    }, 1600);
  }

  function updateFavCounter() {
    var n = Object.keys(favStore()).length;
    var $c = $('.favorites-counter');
    if (!n) {
      $c.attr('hidden', true).text('0');
    } else {
      $c.removeAttr('hidden').text(String(n));
    }
    $('.user-bookmark-toggle').each(function () {
      syncFavButton($(this));
    });
  }

  function clearFavStorage() {
    favMemStore = null;
    try { localStorage.removeItem(FAV_KEY); } catch (e) {}
    try { sessionStorage.removeItem(FAV_KEY); } catch (e) {}
    try {
      document.cookie = FAV_COOKIE + '=; path=/; max-age=0; SameSite=Lax';
    } catch (e) {}
  }

  function toggleFav($btn) {
    var id = favIdFromBtn($btn);
    if (!id) {
      showFavToast('Could not favorite this title');
      return;
    }
    var type = $btn.attr('data-media-type') || $btn.data('media-type') || $btn.data('mediaType') || 'movie';
    type = String(type) === 'tv' ? 'tv' : 'movie';
    var map = favStore();
    var removing = !!map[id];
    if (removing) {
      delete map[id];
    } else {
      var poster = $btn.attr('data-poster') || $btn.data('poster') || '';
      var posterPath = null;
      var pm = String(poster).match(/\/t\/p\/[^/]+(\/.+)$/);
      if (pm) posterPath = pm[1];
      map[id] = {
        id: id,
        type: type,
        mediaType: type,
        title: $btn.attr('data-title') || $btn.data('title') || '',
        name: $btn.attr('data-title') || $btn.data('title') || '',
        poster: posterPath ? '' : poster,
        poster_path: posterPath,
        year: $btn.attr('data-year') || $btn.data('year') || '',
        saved_at: new Date().toISOString()
      };
    }
    if (!saveFavs(map)) return;
    syncFavButton($btn);
    showFavToast(removing ? 'Removed from favorites' : 'Added to favorites');
  }

'''


def main() -> None:
    path = ROOT / "public/assets/js/app.js"
    text = path.read_text()
    pattern = re.compile(
        r"  var FAV_KEY = 'user_bookmarks';\n"
        r"[\s\S]*?"
        r"  function toggleFav\(\$btn\) \{[\s\S]*?\n  \}\n",
        re.M,
    )
    if not pattern.search(text):
        raise SystemExit("favorites block not found")
    # Use callable repl so JS backslashes (e.g. \s) are not treated as re escapes
    text = pattern.sub(lambda _m: FAV_FUNCS.lstrip("\n"), text, count=1)

    old_clear = """  $(document).on('click', '#clearAllFavorites', function () {
    if (confirm('Clear all favorites?')) {
      localStorage.removeItem(FAV_KEY);
      updateFavCounter();
      renderFavoritesPage();
    }
  });"""
    new_clear = """  $(document).on('click', '#clearAllFavorites', function () {
    if (confirm('Clear all favorites?')) {
      clearFavStorage();
      updateFavCounter();
      renderFavoritesPage();
    }
  });"""
    if old_clear in text:
        text = text.replace(old_clear, new_clear, 1)
        print("clear-all patched")
    elif "clearFavStorage()" in text:
        print("clear-all already patched")
    else:
        print("WARN clear-all not patched")

    path.write_text(text)
    print("favorites storage replaced")

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("asset", ASSET_V)
    print("done", ASSET_V)


if __name__ == "__main__":
    main()
