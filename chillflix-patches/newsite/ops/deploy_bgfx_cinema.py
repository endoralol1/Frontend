#!/usr/bin/env python3
"""Replace weak blob wash with a cinematic full-screen aurora background."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui78"

AMBIENT_HTML = """<div class="cf-ambient" aria-hidden="true">
    <div class="cf-bgfx">
        <div class="cf-bgfx-aurora"></div>
        <div class="cf-bgfx-wave cf-bgfx-wave--a"></div>
        <div class="cf-bgfx-wave cf-bgfx-wave--b"></div>
        <div class="cf-bgfx-glow cf-bgfx-glow--tl"></div>
        <div class="cf-bgfx-glow cf-bgfx-glow--tr"></div>
        <div class="cf-bgfx-glow cf-bgfx-glow--bl"></div>
        <div class="cf-bgfx-glow cf-bgfx-glow--br"></div>
        <div class="cf-bgfx-glow cf-bgfx-glow--mid"></div>
        <div class="cf-bgfx-grain"></div>
        <div class="cf-bgfx-vignette"></div>
    </div>
</div>
"""

CSS = r"""
/* ——— Cinematic aurora background (ui78) ——— */
html, body {
  background: #080a0f !important;
  background-image: none !important;
}

.cf-ambient {
  position: fixed !important;
  inset: 0 !important;
  z-index: 0 !important;
  pointer-events: none !important;
  overflow: hidden !important;
}

/* Remove older FX layers */
.cf-fx-stage,
.cf-fx-collage,
.cf-fx-shade,
.cf-ambient-mosaic,
.cf-ambient-orb,
.cf-ambient-wash,
.cf-bgfx-blob,
.cf-bgfx-beam,
.cf-bgfx-sheen,
.favorites-collage,
.favorites-stage-shade {
  display: none !important;
}

.cf-bgfx {
  position: absolute;
  inset: 0;
  overflow: hidden;
  background: #080a0f;
}

/* Slow spinning color field — full screen, top + bottom */
.cf-bgfx-aurora {
  position: absolute;
  inset: -45%;
  background: conic-gradient(
    from 0deg at 50% 45%,
    rgba(255, 70, 50, 0.0) 0deg,
    rgba(255, 90, 60, 0.34) 55deg,
    rgba(220, 53, 69, 0.08) 110deg,
    rgba(219, 105, 55, 0.28) 170deg,
    rgba(255, 70, 50, 0.0) 220deg,
    rgba(255, 120, 80, 0.3) 280deg,
    rgba(220, 53, 69, 0.1) 320deg,
    rgba(255, 70, 50, 0.0) 360deg
  );
  filter: blur(70px) saturate(1.15);
  opacity: 0.7;
  animation: cfAuroraSpin 32s linear infinite;
  transform: translate3d(0, 0, 0);
  will-change: transform;
}

/* Horizontal light waves drifting vertically (reads as “alive” backdrop) */
.cf-bgfx-wave {
  position: absolute;
  left: -20%;
  width: 140%;
  height: 42%;
  filter: blur(36px);
  opacity: 0.55;
  will-change: transform, opacity;
}

.cf-bgfx-wave--a {
  top: 8%;
  background: radial-gradient(60% 80% at 30% 50%, rgba(255, 90, 60, 0.35), transparent 70%),
              radial-gradient(50% 70% at 75% 40%, rgba(219, 105, 55, 0.22), transparent 70%);
  animation: cfWaveA 12s ease-in-out infinite alternate;
}

.cf-bgfx-wave--b {
  bottom: 0;
  background: radial-gradient(55% 85% at 70% 60%, rgba(220, 53, 69, 0.32), transparent 70%),
              radial-gradient(50% 75% at 25% 50%, rgba(255, 120, 80, 0.2), transparent 70%);
  animation: cfWaveB 15s ease-in-out infinite alternate;
}

.cf-bgfx-glow {
  position: absolute;
  border-radius: 50%;
  filter: blur(40px);
  will-change: transform, opacity;
}

.cf-bgfx-glow--tl {
  width: min(70vw, 34rem);
  height: min(70vw, 34rem);
  top: -18%;
  left: -15%;
  background: radial-gradient(circle, rgba(255, 95, 65, 0.45) 0%, transparent 68%);
  animation: cfGlowTL 10s ease-in-out infinite alternate;
}

.cf-bgfx-glow--tr {
  width: min(62vw, 30rem);
  height: min(62vw, 30rem);
  top: -10%;
  right: -14%;
  background: radial-gradient(circle, rgba(219, 105, 55, 0.34) 0%, transparent 68%);
  animation: cfGlowTR 13s ease-in-out infinite alternate;
}

.cf-bgfx-glow--bl {
  width: min(72vw, 34rem);
  height: min(72vw, 34rem);
  bottom: -20%;
  left: -16%;
  background: radial-gradient(circle, rgba(255, 80, 60, 0.38) 0%, transparent 68%);
  animation: cfGlowBL 12s ease-in-out infinite alternate;
}

.cf-bgfx-glow--br {
  width: min(68vw, 32rem);
  height: min(68vw, 32rem);
  bottom: -18%;
  right: -14%;
  background: radial-gradient(circle, rgba(219, 105, 55, 0.36) 0%, transparent 68%);
  animation: cfGlowBR 14s ease-in-out infinite alternate;
}

.cf-bgfx-glow--mid {
  width: min(90vw, 46rem);
  height: min(50vw, 26rem);
  left: 50%;
  top: 42%;
  transform: translate(-50%, -50%);
  background: radial-gradient(circle, rgba(255, 110, 80, 0.14) 0%, transparent 70%);
  animation: cfGlowMid 9s ease-in-out infinite alternate;
  filter: blur(50px);
}

/* Film grain for depth (tiny, CSS-only) */
.cf-bgfx-grain {
  position: absolute;
  inset: 0;
  opacity: 0.045;
  mix-blend-mode: soft-light;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.9'/%3E%3C/svg%3E");
  background-size: 180px 180px;
  animation: cfGrain 0.45s steps(2) infinite;
}

.cf-bgfx-vignette {
  position: absolute;
  inset: 0;
  background:
    radial-gradient(120% 80% at 50% 40%, transparent 35%, rgba(8, 10, 15, 0.55) 100%),
    linear-gradient(180deg, rgba(8, 10, 15, 0.15) 0%, transparent 28%, transparent 72%, rgba(8, 10, 15, 0.35) 100%);
}

@keyframes cfAuroraSpin {
  from { transform: rotate(0deg) scale(1.05); }
  to { transform: rotate(360deg) scale(1.05); }
}

@keyframes cfWaveA {
  0%   { transform: translate3d(-4%, 0, 0) scale(1); opacity: 0.4; }
  50%  { transform: translate3d(3%, 8%, 0) scale(1.05); opacity: 0.7; }
  100% { transform: translate3d(6%, 14%, 0) scale(0.98); opacity: 0.5; }
}

@keyframes cfWaveB {
  0%   { transform: translate3d(4%, 0, 0) scale(1.02); opacity: 0.45; }
  50%  { transform: translate3d(-3%, -8%, 0) scale(1.08); opacity: 0.75; }
  100% { transform: translate3d(-7%, -12%, 0) scale(1); opacity: 0.55; }
}

@keyframes cfGlowTL {
  0%   { transform: translate3d(0, 0, 0) scale(1); opacity: 0.75; }
  100% { transform: translate3d(16%, 18%, 0) scale(1.15); opacity: 1; }
}
@keyframes cfGlowTR {
  0%   { transform: translate3d(0, 0, 0) scale(1.05); opacity: 0.65; }
  100% { transform: translate3d(-14%, 16%, 0) scale(0.95); opacity: 0.95; }
}
@keyframes cfGlowBL {
  0%   { transform: translate3d(0, 0, 0) scale(1); opacity: 0.7; }
  100% { transform: translate3d(18%, -14%, 0) scale(1.12); opacity: 1; }
}
@keyframes cfGlowBR {
  0%   { transform: translate3d(0, 0, 0) scale(1.04); opacity: 0.65; }
  100% { transform: translate3d(-16%, -12%, 0) scale(0.96); opacity: 0.95; }
}
@keyframes cfGlowMid {
  0%   { transform: translate(-50%, -50%) scale(0.95); opacity: 0.35; }
  100% { transform: translate(-50%, -50%) scale(1.12); opacity: 0.7; }
}
@keyframes cfGrain {
  0%   { transform: translate(0, 0); }
  100% { transform: translate(-2%, 1%); }
}

body > .wrapper {
  position: relative;
  z-index: 1;
  background: transparent !important;
}

.favorites-page {
  background: transparent !important;
}

/* Home: punch a bit more so it reads behind rails */
body.home .cf-bgfx-aurora {
  opacity: 0.8;
  filter: blur(64px) saturate(1.2);
}
body.home .cf-bgfx-wave {
  opacity: 0.65;
}

/* Watch: calm */
body.page-watch .cf-bgfx,
body.page-player .cf-bgfx,
body:has(main.watch-p) .cf-bgfx,
body:has(#np-shell) .cf-bgfx {
  opacity: 0.22;
}
body.page-watch .cf-bgfx-aurora,
body.page-player .cf-bgfx-aurora,
body:has(main.watch-p) .cf-bgfx-aurora {
  animation-duration: 60s;
}

@media (max-width: 767.98px) {
  .cf-bgfx-aurora {
    filter: blur(56px) saturate(1.1);
    opacity: 0.65;
  }
  .cf-bgfx-glow {
    filter: blur(34px);
  }
  .cf-bgfx-grain {
    opacity: 0.03;
  }
}

@media (prefers-reduced-motion: reduce) {
  .cf-bgfx-aurora,
  .cf-bgfx-wave,
  .cf-bgfx-glow,
  .cf-bgfx-grain {
    animation: none !important;
  }
}
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def main() -> None:
    layout = ROOT / "app/Views/layouts/main.php"
    text = layout.read_text()
    if 'class="cf-bgfx-aurora"' in text:
        print("cinema markup already present")
    elif 'class="cf-ambient"' in text:
        text = re.sub(
            r'<div class="cf-ambient" aria-hidden="true">[\s\S]*?</div>\n(?=<div class="wrapper">)',
            AMBIENT_HTML,
            text,
            count=1,
        )
        print("ambient markup upgraded to cinema aurora")
    else:
        raise SystemExit("ambient missing")
    layout.write_text(bump(text))

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    for marker in (
        "/* ——— Abstract moving background (ui76) — no posters ——— */",
        "/* ——— Abstract BG full height (ui77) ——— */",
        "/* ——— Cinematic aurora background (ui78) ——— */",
    ):
        if marker in css:
            css = re.sub(
                re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)",
                "",
                css,
                count=1,
            )
    css_path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("cinema aurora css applied")
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
