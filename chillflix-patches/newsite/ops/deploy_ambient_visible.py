#!/usr/bin/env python3
"""Make site ambient actually visible + homepage poster mosaic like Favorites."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui74"

AMBIENT_HTML = """<div class="cf-ambient" aria-hidden="true">
    <div class="cf-ambient-mosaic" id="cf-ambient-mosaic"></div>
    <span class="cf-ambient-orb cf-ambient-orb--a"></span>
    <span class="cf-ambient-orb cf-ambient-orb--b"></span>
    <span class="cf-ambient-orb cf-ambient-orb--c"></span>
    <span class="cf-ambient-wash"></span>
</div>
"""

CSS = r"""
/* ——— Site ambient visible (ui74) ——— */
html {
  background: #0a0c12 !important;
}

body {
  background: #0a0c12 !important;
  background-image: none !important;
}

.cf-ambient {
  position: fixed !important;
  inset: 0 !important;
  z-index: 0 !important;
  pointer-events: none !important;
  overflow: hidden !important;
}

.cf-ambient-mosaic {
  position: absolute;
  inset: -4% -2% auto;
  height: min(58vh, 32rem);
  display: grid;
  grid-template-columns: repeat(8, 1fr);
  gap: 0.35rem;
  opacity: 0;
  mask-image: linear-gradient(180deg, #000 20%, transparent 92%);
  -webkit-mask-image: linear-gradient(180deg, #000 20%, transparent 92%);
  transform: scale(1.06);
  transition: opacity 0.6s ease;
}

.cf-ambient-mosaic.is-on {
  opacity: 0.38;
}

.cf-ambient-mosaic-tile {
  position: relative;
  overflow: hidden;
  min-height: 100%;
  background: #151821;
  animation: cfMosaicDrift 16s ease-in-out infinite alternate;
}

.cf-ambient-mosaic-tile:nth-child(odd) { animation-duration: 18s; }
.cf-ambient-mosaic-tile:nth-child(3n) { animation-duration: 20s; animation-delay: -5s; }
.cf-ambient-mosaic-tile:nth-child(4n) { animation-duration: 14s; animation-delay: -2s; }

.cf-ambient-mosaic-tile img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transform: scale(1.1);
  filter: saturate(1.1);
}

@keyframes cfMosaicDrift {
  from { transform: translate3d(0, 0, 0); }
  to { transform: translate3d(0, -4%, 0); }
}

.cf-ambient-orb {
  position: absolute;
  border-radius: 50%;
  filter: blur(40px);
  mix-blend-mode: screen;
  will-change: transform, opacity;
  transform: translate3d(0, 0, 0);
}

.cf-ambient-orb--a {
  width: min(70vw, 34rem);
  height: min(70vw, 34rem);
  top: -18%;
  left: -14%;
  opacity: 0.85;
  background: radial-gradient(circle at 40% 40%, rgba(255, 90, 60, 0.95), rgba(220, 53, 69, 0.35) 45%, transparent 70%);
  animation: cfAmbA 10s ease-in-out infinite alternate;
}

.cf-ambient-orb--b {
  width: min(62vw, 30rem);
  height: min(62vw, 30rem);
  top: 4%;
  right: -16%;
  opacity: 0.7;
  background: radial-gradient(circle at 45% 40%, rgba(255, 140, 70, 0.8), rgba(220, 53, 69, 0.28) 50%, transparent 72%);
  animation: cfAmbB 12s ease-in-out infinite alternate;
}

.cf-ambient-orb--c {
  width: min(80vw, 40rem);
  height: min(50vw, 26rem);
  left: 18%;
  bottom: -22%;
  opacity: 0.55;
  background: radial-gradient(circle at 50% 40%, rgba(219, 105, 55, 0.7), rgba(255, 70, 70, 0.2) 55%, transparent 74%);
  animation: cfAmbC 14s ease-in-out infinite alternate;
}

.cf-ambient-wash {
  position: absolute;
  inset: 0;
  background:
    linear-gradient(180deg, rgba(10, 12, 18, 0.25) 0%, rgba(10, 12, 18, 0.55) 42%, rgba(10, 12, 18, 0.82) 100%),
    radial-gradient(100% 60% at 50% -5%, rgba(255, 70, 50, 0.18), transparent 55%);
}

@keyframes cfAmbA {
  0%   { transform: translate3d(0, 0, 0) scale(1); }
  100% { transform: translate3d(14%, 16%, 0) scale(1.12); }
}

@keyframes cfAmbB {
  0%   { transform: translate3d(0, 0, 0) scale(1.05); }
  100% { transform: translate3d(-16%, 14%, 0) scale(1); }
}

@keyframes cfAmbC {
  0%   { transform: translate3d(0, 0, 0) scale(1); }
  100% { transform: translate3d(10%, -12%, 0) scale(1.15); }
}

body > .wrapper {
  position: relative;
  z-index: 1;
  background: transparent !important;
}

/* Let content sit on the atmosphere */
body.home main > .container,
main > .container {
  background: transparent !important;
}

body:has(main.watch-p) .cf-ambient,
body:has(#np-shell) .cf-ambient,
body.page-watch .cf-ambient,
body.page-player .cf-ambient {
  opacity: 0.22;
}

body:has(main.watch-p) .cf-ambient-mosaic,
body.page-watch .cf-ambient-mosaic,
body.page-player .cf-ambient-mosaic {
  display: none !important;
}

.favorites-page {
  background: transparent !important;
}

@media (max-width: 767.98px) {
  .cf-ambient-mosaic {
    grid-template-columns: repeat(5, 1fr);
    height: min(46vh, 22rem);
    opacity: 0;
  }
  .cf-ambient-mosaic.is-on { opacity: 0.32; }
  .cf-ambient-orb--c { opacity: 0.4; }
}

@media (prefers-reduced-motion: reduce) {
  .cf-ambient-orb,
  .cf-ambient-mosaic-tile {
    animation: none !important;
  }
  .cf-ambient-mosaic.is-on { opacity: 0.22; }
}
"""

JS = r"""
  // ——— Site ambient mosaic (posters, like Favorites vibe) ———
  function buildAmbientMosaic() {
    var root = document.getElementById('cf-ambient-mosaic');
    if (!root) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      root.classList.remove('is-on');
      root.innerHTML = '';
      return;
    }
    var srcs = [];
    var seen = {};
    function add(src) {
      if (!src || seen[src]) return;
      if (src.indexOf('data:') === 0) return;
      seen[src] = 1;
      srcs.push(src);
    }
    document.querySelectorAll(
      '#featured .swiper-slide[style*="background-image"], ' +
      '.movie-item img[src], .movie-item img[data-src], ' +
      '.fav-card-poster img[src], .episode-card-media img[src], ' +
      '.item-poster img[src], .item-poster img[data-src]'
    ).forEach(function (el) {
      if (el.tagName === 'IMG') {
        add(el.getAttribute('src') || el.getAttribute('data-src') || '');
      } else {
        var st = el.getAttribute('style') || '';
        var m = st.match(/url\((['"]?)(.*?)\1\)/);
        if (m) add(m[2]);
      }
    });
    // Prefer poster-ish URLs
    srcs = srcs.filter(function (s) {
      return /\/t\/p\/|poster|w600|w500|w342|w780|w1280/i.test(s) || s.indexOf('http') === 0;
    });
    if (srcs.length < 3) {
      root.classList.remove('is-on');
      root.innerHTML = '';
      return;
    }
    var tiles = 8;
    if (window.matchMedia('(max-width: 767.98px)').matches) tiles = 5;
    var html = '';
    for (var i = 0; i < tiles; i++) {
      var src = srcs[i % srcs.length];
      html += '<div class="cf-ambient-mosaic-tile"><img src="' + src + '" alt="" loading="lazy" decoding="async"></div>';
    }
    root.innerHTML = html;
    root.classList.add('is-on');
  }

  window.cfBuildAmbientMosaic = buildAmbientMosaic;
  $(function () {
    buildAmbientMosaic();
    setTimeout(buildAmbientMosaic, 700);
    setTimeout(buildAmbientMosaic, 1800);
  });
  window.addEventListener('cf:softnav', function () {
    setTimeout(buildAmbientMosaic, 120);
    setTimeout(buildAmbientMosaic, 800);
  });
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def patch_layout() -> None:
    path = ROOT / "app/Views/layouts/main.php"
    text = path.read_text()
    if 'id="cf-ambient-mosaic"' in text:
        print("mosaic markup already present")
    elif 'class="cf-ambient"' in text:
        text = re.sub(
            r'<div class="cf-ambient" aria-hidden="true">[\s\S]*?</div>\n',
            AMBIENT_HTML,
            text,
            count=1,
        )
        path.write_text(text)
        print("ambient markup upgraded")
    else:
        old = '<body class="<?= e($bodyClass) ?>">\n<div class="wrapper">'
        if old not in text:
            raise SystemExit("body marker missing")
        path.write_text(text.replace(old, '<body class="<?= e($bodyClass) ?>">\n' + AMBIENT_HTML + '<div class="wrapper">', 1))
        print("ambient markup inserted")


def patch_css() -> None:
    path = ROOT / "public/assets/css/app.css"
    css = path.read_text()
    for marker in (
        "/* ——— Site ambient atmosphere (ui73) ——— */",
        "/* ——— Site ambient visible (ui74) ——— */",
    ):
        if marker in css:
            css = re.sub(
                re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)",
                "",
                css,
                count=1,
            )
    path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("visible ambient css applied")


def patch_js() -> None:
    path = ROOT / "public/assets/js/app.js"
    js = path.read_text()
    if "buildAmbientMosaic" in js:
        # refresh block
        start = js.find("  // ——— Site ambient mosaic")
        if start < 0:
            start = js.find("  function buildAmbientMosaic")
        if start >= 0:
            # remove until next major marker or end of IIFE handlers near $(function
            end = js.find("\n  window.addEventListener('popstate'", start)
            if end < 0:
                end = js.find("\n  $(function () {\n    applyBrowsePrefs", start)
            if end < 0:
                raise SystemExit("cannot bound ambient js")
            js = js[:start] + JS.rstrip() + "\n" + js[end:]
            path.write_text(js)
            print("ambient js refreshed")
            return
    # Insert before popstate or final $(function
    anchor = "  window.addEventListener('popstate', function () {"
    if anchor not in js:
        raise SystemExit("popstate anchor missing")
    path.write_text(js.replace(anchor, JS.rstrip() + "\n\n" + anchor, 1))
    print("ambient js inserted")


def main() -> None:
    patch_layout()
    patch_css()
    patch_js()
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
