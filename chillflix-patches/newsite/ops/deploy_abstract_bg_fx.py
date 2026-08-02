#!/usr/bin/env python3
"""Abstract moving background (no posters/anime) — Favorites vibe for home too."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui76"

AMBIENT_HTML = """<div class="cf-ambient" aria-hidden="true">
    <div class="cf-bgfx">
        <span class="cf-bgfx-blob cf-bgfx-blob--1"></span>
        <span class="cf-bgfx-blob cf-bgfx-blob--2"></span>
        <span class="cf-bgfx-blob cf-bgfx-blob--3"></span>
        <span class="cf-bgfx-beam"></span>
        <span class="cf-bgfx-sheen"></span>
    </div>
</div>
"""

CSS = r"""
/* ——— Abstract moving background (ui76) — no posters ——— */
html {
  background: #0b0d12 !important;
}

body {
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

/* Kill old poster collage / mosaic completely */
.cf-fx-stage,
.cf-fx-collage,
.cf-fx-shade,
.cf-ambient-mosaic,
.cf-ambient-orb,
.cf-ambient-wash,
.favorites-collage,
.favorites-stage-shade {
  display: none !important;
}

.cf-bgfx {
  position: absolute;
  inset: 0;
  overflow: hidden;
  background:
    radial-gradient(120% 70% at 50% -10%, rgba(220, 53, 69, 0.2), transparent 55%),
    linear-gradient(180deg, #12141b 0%, #0b0d12 48%, #090b10 100%);
}

.cf-bgfx-blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(48px);
  will-change: transform, opacity;
  transform: translate3d(0, 0, 0);
}

.cf-bgfx-blob--1 {
  width: min(78vw, 36rem);
  height: min(78vw, 36rem);
  top: -22%;
  left: -18%;
  background: radial-gradient(circle at 40% 40%, rgba(255, 82, 55, 0.55), rgba(220, 53, 69, 0.18) 50%, transparent 70%);
  animation: cfBgfx1 11s ease-in-out infinite alternate;
}

.cf-bgfx-blob--2 {
  width: min(70vw, 32rem);
  height: min(70vw, 32rem);
  top: 6%;
  right: -20%;
  background: radial-gradient(circle at 45% 40%, rgba(219, 105, 55, 0.42), rgba(255, 90, 60, 0.12) 52%, transparent 72%);
  animation: cfBgfx2 14s ease-in-out infinite alternate;
}

.cf-bgfx-blob--3 {
  width: min(90vw, 42rem);
  height: min(55vw, 28rem);
  left: 15%;
  bottom: -28%;
  background: radial-gradient(circle at 50% 40%, rgba(220, 53, 69, 0.28), rgba(219, 105, 55, 0.1) 55%, transparent 75%);
  animation: cfBgfx3 16s ease-in-out infinite alternate;
}

/* Soft moving light beam — the “alive” feel from Favorites atmosphere */
.cf-bgfx-beam {
  position: absolute;
  top: -20%;
  left: -30%;
  width: 55%;
  height: 140%;
  background: linear-gradient(
    105deg,
    transparent 30%,
    rgba(255, 140, 100, 0.09) 48%,
    rgba(255, 80, 60, 0.14) 52%,
    transparent 68%
  );
  transform: rotate(12deg) translate3d(0, 0, 0);
  animation: cfBgfxBeam 9s ease-in-out infinite alternate;
  filter: blur(8px);
}

.cf-bgfx-sheen {
  position: absolute;
  inset: 0;
  background:
    radial-gradient(80% 50% at 50% 0%, rgba(255, 255, 255, 0.04), transparent 55%),
    linear-gradient(180deg, rgba(11, 13, 18, 0.15) 0%, rgba(11, 13, 18, 0.55) 55%, rgba(9, 11, 16, 0.88) 100%);
  animation: cfBgfxSheen 8s ease-in-out infinite alternate;
}

@keyframes cfBgfx1 {
  0%   { transform: translate3d(0, 0, 0) scale(1); opacity: 0.85; }
  100% { transform: translate3d(18%, 14%, 0) scale(1.12); opacity: 1; }
}
@keyframes cfBgfx2 {
  0%   { transform: translate3d(0, 0, 0) scale(1.05); opacity: 0.7; }
  100% { transform: translate3d(-16%, 18%, 0) scale(0.95); opacity: 0.95; }
}
@keyframes cfBgfx3 {
  0%   { transform: translate3d(0, 0, 0) scale(1); opacity: 0.55; }
  100% { transform: translate3d(12%, -10%, 0) scale(1.18); opacity: 0.8; }
}
@keyframes cfBgfxBeam {
  0%   { transform: rotate(12deg) translate3d(0, 0, 0); opacity: 0.55; }
  100% { transform: rotate(12deg) translate3d(70%, 4%, 0); opacity: 1; }
}
@keyframes cfBgfxSheen {
  0%   { opacity: 0.75; }
  100% { opacity: 1; }
}

body > .wrapper {
  position: relative;
  z-index: 1;
  background: transparent !important;
}

.favorites-page {
  background: transparent !important;
}

/* Homepage: same FX, slightly stronger so it’s obvious under the hero/content */
body.home .cf-bgfx-blob--1,
body.home .cf-bgfx-blob--2 {
  filter: blur(42px);
}
body.home .cf-bgfx-beam {
  opacity: 1;
}

/* Watch pages: keep calm */
body.page-watch .cf-bgfx,
body.page-player .cf-bgfx,
body:has(main.watch-p) .cf-bgfx,
body:has(#np-shell) .cf-bgfx {
  opacity: 0.25;
}
body.page-watch .cf-bgfx-beam,
body.page-player .cf-bgfx-beam,
body:has(main.watch-p) .cf-bgfx-beam {
  display: none;
}

@media (max-width: 767.98px) {
  .cf-bgfx-blob {
    filter: blur(40px);
  }
  .cf-bgfx-blob--3 {
    opacity: 0.45;
  }
}

@media (prefers-reduced-motion: reduce) {
  .cf-bgfx-blob,
  .cf-bgfx-beam,
  .cf-bgfx-sheen {
    animation: none !important;
  }
}
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def patch_layout() -> None:
    path = ROOT / "app/Views/layouts/main.php"
    text = path.read_text()
    if 'class="cf-bgfx"' in text:
        print("bgfx markup already present")
    elif 'class="cf-ambient"' in text:
        text = re.sub(
            r'<div class="cf-ambient" aria-hidden="true">[\s\S]*?</div>\n(?=<div class="wrapper">)',
            AMBIENT_HTML,
            text,
            count=1,
        )
        print("replaced ambient with abstract bgfx")
    else:
        raise SystemExit("ambient block missing")
    path.write_text(bump(text))
    print("asset", ASSET_V)


def patch_css() -> None:
    path = ROOT / "public/assets/css/app.css"
    css = path.read_text()
    for marker in (
        "/* ——— Site ambient atmosphere (ui73) ——— */",
        "/* ——— Site ambient visible (ui74) ——— */",
        "/* ——— Favorites collage FX site-wide (ui75) ——— */",
        "/* ——— Abstract moving background (ui76) — no posters ——— */",
    ):
        if marker in css:
            css = re.sub(
                re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)",
                "",
                css,
                count=1,
            )
    path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("abstract bg css applied")


def patch_js() -> None:
    """Remove poster-collage / mosaic builders — not wanted."""
    path = ROOT / "public/assets/js/app.js"
    js = path.read_text()
    changed = False
    for marker in (
        "  // ——— Site ambient mosaic (posters, like Favorites vibe) ———",
        "  // ——— Favorites-style collage FX (site-wide) ———",
    ):
        while marker in js:
            start = js.find(marker)
            end_candidates = [
                js.find("\n  window.addEventListener('popstate'", start + 5),
                js.find("\n  // ———", start + 5),
                js.find("\n  $(function () {\n    applyBrowsePrefs", start + 5),
            ]
            ends = [e for e in end_candidates if e > start]
            if not ends:
                break
            js = js[:start] + js[min(ends) :]
            changed = True
            print("removed js block", marker[6:40])

    # Also strip any leftover calls if functions remain orphaned
    if "buildFavoritesCollageFx" in js or "buildAmbientMosaic" in js:
        # remove function definitions more aggressively
        js = re.sub(
            r"\n  function (?:buildFavoritesCollageFx|buildAmbientMosaic|cfFxPosterPool)\([\s\S]*?\n  \}\n",
            "\n",
            js,
        )
        js = re.sub(
            r"\n  window\.cfBuild(?:FavoritesCollageFx|AmbientMosaic) = [^;]+;\n",
            "\n",
            js,
        )
        # remove ready/softnav hooks that only called those
        js = re.sub(
            r"\n  \$\(function \(\) \{\n    build(?:FavoritesCollageFx|AmbientMosaic)\(\);[\s\S]*?\n  \}\);\n",
            "\n",
            js,
        )
        js = re.sub(
            r"\n  window\.addEventListener\('cf:softnav', function \(\) \{\n    setTimeout\(build(?:FavoritesCollageFx|AmbientMosaic)[\s\S]*?\n  \}\);\n",
            "\n",
            js,
        )
        js = re.sub(
            r"\n  window\.addEventListener\('storage', function \(e\) \{\n    if \(!e\.key \|\| e\.key === 'user_bookmarks'[\s\S]*?\n  \}\);\n",
            "\n",
            js,
        )
        changed = True
        print("scrubbed leftover collage/mosaic js")

    if changed:
        path.write_text(js)
    else:
        print("no collage js left to remove")


def main() -> None:
    patch_layout()
    patch_css()
    patch_js()


if __name__ == "__main__":
    main()
