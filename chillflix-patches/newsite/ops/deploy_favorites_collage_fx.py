#!/usr/bin/env python3
"""Bring back the real Favorites collage animation site-wide (the dope one)."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui75"

AMBIENT_HTML = """<div class="cf-ambient" aria-hidden="true">
    <div class="cf-fx-stage">
        <div class="cf-fx-collage" id="cf-fx-collage"></div>
        <div class="cf-fx-shade"></div>
    </div>
</div>
"""

CSS = r"""
/* ——— Favorites collage FX site-wide (ui75) ——— */
html, body {
  background: #0b0d12 !important;
  background-image: none !important;
}

.cf-ambient {
  position: fixed !important;
  inset: 0 !important;
  z-index: 0 !important;
  pointer-events: none !important;
  overflow: hidden !important;
}

/* Exact Favorites collage treatment */
.cf-fx-stage {
  position: absolute;
  inset: 0 0 auto;
  height: min(54vh, 28rem);
}

.cf-fx-collage {
  position: absolute;
  inset: 0;
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 0.35rem;
  opacity: 0;
  transform: scale(1.04);
  filter: saturate(1.08);
  mask-image: linear-gradient(180deg, #000 35%, transparent 92%);
  -webkit-mask-image: linear-gradient(180deg, #000 35%, transparent 92%);
  transition: opacity 0.55s ease;
}

.cf-fx-collage.is-on {
  opacity: 0.5;
}

.cf-fx-collage-tile {
  position: relative;
  overflow: hidden;
  min-height: 100%;
  background: #171a22;
  animation: favCollageDrift 14s ease-in-out infinite alternate;
}

.cf-fx-collage-tile:nth-child(odd) { animation-duration: 16s; }
.cf-fx-collage-tile:nth-child(3n) { animation-duration: 18s; animation-delay: -4s; }
.cf-fx-collage-tile:nth-child(4n) { animation-duration: 15s; animation-delay: -7s; }
.cf-fx-collage-tile:nth-child(5n) { animation-duration: 17s; animation-delay: -2s; }

.cf-fx-collage-tile img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transform: scale(1.08);
}

/* reuse the original favorites keyframes name for the same feel */
@keyframes favCollageDrift {
  from { transform: translateY(0); }
  to { transform: translateY(-3%); }
}

.cf-fx-shade {
  position: absolute;
  inset: 0;
  background:
    linear-gradient(90deg, rgba(9, 11, 16, 0.72) 8%, rgba(9, 11, 16, 0.22) 55%, rgba(9, 11, 16, 0.68) 100%),
    linear-gradient(180deg, rgba(9, 11, 16, 0.1) 0%, rgba(9, 11, 16, 0.88) 88%, #0b0d12 100%);
}

body > .wrapper {
  position: relative;
  z-index: 1;
  background: transparent !important;
}

/* Home: keep collage under/around hero, a bit stronger */
body.home .cf-fx-stage {
  height: min(62vh, 34rem);
}
body.home .cf-fx-collage.is-on {
  opacity: 0.42;
}

/* Favorites: same dope collage intensity */
body:has(.favorites-page) .cf-fx-collage.is-on,
.favorites-page ~ .cf-fx-collage {
  opacity: 0.48;
}
body:has(.favorites-page) .cf-fx-stage {
  height: min(48vh, 24rem);
  top: 3.6rem;
}

.favorites-page {
  background: transparent !important;
}

/* Hide old weak mosaic/orbs leftovers if present */
.cf-ambient-mosaic,
.cf-ambient-orb,
.cf-ambient-wash {
  display: none !important;
}

/* Quiet on watch */
body.page-watch .cf-fx-stage,
body.page-player .cf-fx-stage,
body:has(main.watch-p) .cf-fx-stage,
body:has(#np-shell) .cf-fx-stage {
  opacity: 0.18;
  height: min(36vh, 16rem);
}

@media (max-width: 767.98px) {
  .cf-fx-collage {
    grid-template-columns: repeat(4, 1fr);
  }
  .cf-fx-stage {
    height: min(42vh, 20rem);
  }
  body.home .cf-fx-stage {
    height: min(48vh, 22rem);
  }
  .cf-fx-collage.is-on {
    opacity: 0.4;
  }
}

@media (prefers-reduced-motion: reduce) {
  .cf-fx-collage-tile {
    animation: none !important;
  }
  .cf-fx-collage.is-on {
    opacity: 0.28;
  }
}
"""

JS = r"""
  // ——— Favorites-style collage FX (site-wide) ———
  function cfFxPosterPool() {
    var srcs = [];
    var seen = {};
    function push(src) {
      src = String(src || '').trim().replace(/^['"]|['"]$/g, '');
      if (!src || seen[src] || src.indexOf('data:') === 0) return;
      // normalize common TMDB sizes toward a collage-friendly width
      src = src
        .replace('/w1280/', '/w500/')
        .replace('/original/', '/w500/')
        .replace('/w780/', '/w500/')
        .replace('/w600_and_h900_bestv2/', '/w342/');
      seen[src] = 1;
      srcs.push(src);
    }

    // 1) Saved favorites (the real Favorites vibe)
    try {
      var raw = localStorage.getItem('user_bookmarks') || sessionStorage.getItem('user_bookmarks') || '';
      if (raw) {
        var map = JSON.parse(raw) || {};
        var imgBase = (window.APP && APP.imgBase) || 'https://image.tmdb.org/t/p';
        Object.keys(map).forEach(function (k) {
          var it = map[k] || {};
          if (it.poster) push(it.poster);
          else if (it.poster_path) push(imgBase + '/w342' + it.poster_path);
        });
      }
    } catch (e) {}

    // 2) Continue watching art
    try {
      if (window.ChillflixContinue && typeof ChillflixContinue.list === 'function') {
        (ChillflixContinue.list() || []).forEach(function (row) {
          if (row && row.poster) push(row.poster);
          if (row && row.backdrop) push(row.backdrop);
        });
      }
    } catch (e2) {}

    // 3) Artwork already on the page
    document.querySelectorAll(
      '.movie-item img[data-src], .movie-item img[src], ' +
      '.item-poster img[data-src], .item-poster img[src], ' +
      '.fav-card-poster img[src], .fav-spot-poster img[src], ' +
      '.episode-card-media img[src], .episode-card-media img[data-src], ' +
      '#featured .swiper-slide'
    ).forEach(function (el) {
      if (el.tagName === 'IMG') {
        push(el.getAttribute('data-src') || el.getAttribute('src') || '');
        return;
      }
      var st = el.getAttribute('style') || '';
      var m = st.match(/url\((['"]?)(.*?)\1\)/);
      if (m) push(m[2]);
      var bg = el.getAttribute('data-bgset') || '';
      if (bg) push(bg.split(/\s|,/)[0]);
    });

    return srcs;
  }

  function buildFavoritesCollageFx() {
    var root = document.getElementById('cf-fx-collage');
    if (!root) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      // still show static collage, just no drift
    }
    var srcs = cfFxPosterPool();
    if (srcs.length < 2) {
      root.classList.remove('is-on');
      root.innerHTML = '';
      return;
    }
    var cols = 6;
    if (window.matchMedia('(max-width: 767.98px)').matches) cols = 4;
    var html = '';
    for (var i = 0; i < cols; i++) {
      var src = srcs[i % srcs.length];
      html +=
        '<div class="cf-fx-collage-tile">' +
          '<img src="' + src + '" alt="" loading="lazy" decoding="async">' +
        '</div>';
    }
    root.innerHTML = html;
    root.classList.add('is-on');
  }

  window.cfBuildFavoritesCollageFx = buildFavoritesCollageFx;
  $(function () {
    buildFavoritesCollageFx();
    setTimeout(buildFavoritesCollageFx, 500);
    setTimeout(buildFavoritesCollageFx, 1600);
  });
  window.addEventListener('cf:softnav', function () {
    setTimeout(buildFavoritesCollageFx, 80);
    setTimeout(buildFavoritesCollageFx, 700);
  });
  window.addEventListener('storage', function (e) {
    if (!e.key || e.key === 'user_bookmarks' || e.key === 'cf_continue_v1') {
      setTimeout(buildFavoritesCollageFx, 60);
    }
  });
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def patch_layout() -> None:
    path = ROOT / "app/Views/layouts/main.php"
    text = path.read_text()
    if 'id="cf-fx-collage"' in text:
        print("fx collage markup present")
        text = bump(text)
        path.write_text(text)
        return
    if 'class="cf-ambient"' in text:
        text = re.sub(
            r'<div class="cf-ambient" aria-hidden="true">[\s\S]*?</div>\n(?=<div class="wrapper">)',
            AMBIENT_HTML,
            text,
            count=1,
        )
        path.write_text(bump(text))
        print("ambient replaced with fx collage")
        return
    raise SystemExit("cf-ambient block not found")


def patch_css() -> None:
    path = ROOT / "public/assets/css/app.css"
    css = path.read_text()
    for marker in (
        "/* ——— Site ambient atmosphere (ui73) ——— */",
        "/* ——— Site ambient visible (ui74) ——— */",
        "/* ——— Favorites collage FX site-wide (ui75) ——— */",
    ):
        if marker in css:
            css = re.sub(
                re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)",
                "",
                css,
                count=1,
            )
    # Make sure original favorites collage keyframes still exist (they do in v2 block)
    path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("collage fx css applied")


def patch_js() -> None:
    path = ROOT / "public/assets/js/app.js"
    js = path.read_text()

    # Remove old mosaic builder if present
    for marker in (
        "  // ——— Site ambient mosaic (posters, like Favorites vibe) ———",
        "  // ——— Favorites-style collage FX (site-wide) ———",
    ):
        if marker in js:
            start = js.find(marker)
            # end at next window.addEventListener('popstate' or next // ——— or $(function applyBrowse
            end_candidates = [
                js.find("\n  window.addEventListener('popstate'", start + 5),
                js.find("\n  // ———", start + 5),
                js.find("\n  $(function () {\n    applyBrowsePrefs", start + 5),
            ]
            ends = [e for e in end_candidates if e > start]
            if not ends:
                raise SystemExit("cannot find end of ambient js block")
            end = min(ends)
            js = js[:start] + js[end:]

    anchor = "  window.addEventListener('popstate', function () {"
    if anchor not in js:
        raise SystemExit("popstate anchor missing")
    if "buildFavoritesCollageFx" not in js:
        js = js.replace(anchor, JS.rstrip() + "\n\n" + anchor, 1)
        print("collage fx js inserted")
    else:
        print("collage fx js already present")
    path.write_text(js)


def main() -> None:
    patch_layout()
    patch_css()
    patch_js()
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
