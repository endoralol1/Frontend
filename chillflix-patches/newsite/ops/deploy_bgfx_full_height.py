#!/usr/bin/env python3
"""Extend abstract BG motion to bottom of viewport too (not top-only)."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui77"

AMBIENT_HTML = """<div class="cf-ambient" aria-hidden="true">
    <div class="cf-bgfx">
        <span class="cf-bgfx-blob cf-bgfx-blob--1"></span>
        <span class="cf-bgfx-blob cf-bgfx-blob--2"></span>
        <span class="cf-bgfx-blob cf-bgfx-blob--3"></span>
        <span class="cf-bgfx-blob cf-bgfx-blob--4"></span>
        <span class="cf-bgfx-beam cf-bgfx-beam--top"></span>
        <span class="cf-bgfx-beam cf-bgfx-beam--bot"></span>
        <span class="cf-bgfx-sheen"></span>
    </div>
</div>
"""

CSS = r"""
/* ——— Abstract BG full height (ui77) ——— */
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
    radial-gradient(110% 55% at 50% -8%, rgba(220, 53, 69, 0.18), transparent 55%),
    radial-gradient(100% 50% at 50% 110%, rgba(219, 105, 55, 0.16), transparent 55%),
    linear-gradient(180deg, #12141b 0%, #0b0d12 50%, #090b10 100%);
}

.cf-bgfx-blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(48px);
  will-change: transform, opacity;
  transform: translate3d(0, 0, 0);
}

/* Top-left */
.cf-bgfx-blob--1 {
  width: min(78vw, 36rem);
  height: min(78vw, 36rem);
  top: -22%;
  left: -18%;
  background: radial-gradient(circle at 40% 40%, rgba(255, 82, 55, 0.55), rgba(220, 53, 69, 0.18) 50%, transparent 70%);
  animation: cfBgfx1 11s ease-in-out infinite alternate;
}

/* Top-right */
.cf-bgfx-blob--2 {
  width: min(70vw, 32rem);
  height: min(70vw, 32rem);
  top: 4%;
  right: -20%;
  background: radial-gradient(circle at 45% 40%, rgba(219, 105, 55, 0.42), rgba(255, 90, 60, 0.12) 52%, transparent 72%);
  animation: cfBgfx2 14s ease-in-out infinite alternate;
}

/* Bottom-left */
.cf-bgfx-blob--3 {
  width: min(85vw, 38rem);
  height: min(70vw, 32rem);
  left: -18%;
  bottom: -18%;
  background: radial-gradient(circle at 45% 45%, rgba(255, 90, 60, 0.42), rgba(220, 53, 69, 0.16) 52%, transparent 72%);
  animation: cfBgfx3 13s ease-in-out infinite alternate;
}

/* Bottom-right */
.cf-bgfx-blob--4 {
  width: min(80vw, 36rem);
  height: min(65vw, 30rem);
  right: -16%;
  bottom: -20%;
  background: radial-gradient(circle at 50% 40%, rgba(219, 105, 55, 0.4), rgba(255, 70, 70, 0.14) 55%, transparent 74%);
  animation: cfBgfx4 15s ease-in-out infinite alternate;
}

.cf-bgfx-beam {
  position: absolute;
  width: 55%;
  height: 120%;
  filter: blur(8px);
  pointer-events: none;
}

.cf-bgfx-beam--top {
  top: -25%;
  left: -30%;
  background: linear-gradient(
    105deg,
    transparent 30%,
    rgba(255, 140, 100, 0.09) 48%,
    rgba(255, 80, 60, 0.14) 52%,
    transparent 68%
  );
  transform: rotate(12deg) translate3d(0, 0, 0);
  animation: cfBgfxBeamTop 9s ease-in-out infinite alternate;
}

.cf-bgfx-beam--bot {
  bottom: -30%;
  right: -35%;
  background: linear-gradient(
    -105deg,
    transparent 30%,
    rgba(219, 105, 55, 0.1) 48%,
    rgba(255, 90, 70, 0.15) 52%,
    transparent 68%
  );
  transform: rotate(12deg) translate3d(0, 0, 0);
  animation: cfBgfxBeamBot 11s ease-in-out infinite alternate;
}

/* Keep a light veil — do NOT crush the bottom glow */
.cf-bgfx-sheen {
  position: absolute;
  inset: 0;
  background:
    radial-gradient(80% 45% at 50% 0%, rgba(255, 255, 255, 0.035), transparent 55%),
    radial-gradient(90% 45% at 50% 100%, rgba(255, 140, 100, 0.05), transparent 55%),
    linear-gradient(180deg, rgba(11, 13, 18, 0.12) 0%, rgba(11, 13, 18, 0.28) 50%, rgba(11, 13, 18, 0.4) 100%);
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
  0%   { transform: translate3d(0, 0, 0) scale(1); opacity: 0.75; }
  100% { transform: translate3d(16%, -12%, 0) scale(1.14); opacity: 1; }
}
@keyframes cfBgfx4 {
  0%   { transform: translate3d(0, 0, 0) scale(1.05); opacity: 0.7; }
  100% { transform: translate3d(-14%, -10%, 0) scale(0.96); opacity: 0.95; }
}
@keyframes cfBgfxBeamTop {
  0%   { transform: rotate(12deg) translate3d(0, 0, 0); opacity: 0.55; }
  100% { transform: rotate(12deg) translate3d(70%, 4%, 0); opacity: 1; }
}
@keyframes cfBgfxBeamBot {
  0%   { transform: rotate(12deg) translate3d(0, 0, 0); opacity: 0.5; }
  100% { transform: rotate(12deg) translate3d(-65%, -6%, 0); opacity: 1; }
}
@keyframes cfBgfxSheen {
  0%   { opacity: 0.8; }
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

body.home .cf-bgfx-blob--1,
body.home .cf-bgfx-blob--2,
body.home .cf-bgfx-blob--3,
body.home .cf-bgfx-blob--4 {
  filter: blur(42px);
}

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
  .cf-bgfx-blob { filter: blur(40px); }
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


def main() -> None:
    layout = ROOT / "app/Views/layouts/main.php"
    text = layout.read_text()
    if 'cf-bgfx-blob--4' in text:
        print("bottom blobs markup already present")
    elif 'class="cf-ambient"' in text:
        text = re.sub(
            r'<div class="cf-ambient" aria-hidden="true">[\s\S]*?</div>\n(?=<div class="wrapper">)',
            AMBIENT_HTML,
            text,
            count=1,
        )
        print("ambient markup updated for full-height")
    else:
        raise SystemExit("ambient missing")
    layout.write_text(bump(text))

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    for marker in (
        "/* ——— Abstract moving background (ui76) — no posters ——— */",
        "/* ——— Abstract BG full height (ui77) ——— */",
    ):
        if marker in css:
            css = re.sub(
                re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)",
                "",
                css,
                count=1,
            )
    css_path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("full-height bg css applied")
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
