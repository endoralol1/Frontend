#!/usr/bin/env python3
"""Favorites v2 — cinematic watchlist page."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui69"

PAGE = r"""<main class="page-pad-top favorites-page">
    <div class="favorites-stage">
        <div class="favorites-collage" id="favorites-collage" aria-hidden="true"></div>
        <div class="favorites-stage-shade" aria-hidden="true"></div>

        <div class="container favorites-wrap">
            <header class="favorites-hero">
                <div class="favorites-hero-copy">
                    <p class="favorites-brand">CHILLFLIX</p>
                    <h1 class="favorites-title">Watchlist</h1>
                    <p class="favorites-sub">Your saved movies and shows — ready when you are.</p>
                    <div class="favorites-stats" id="favorites-stats" aria-live="polite">
                        <span class="favorites-stat"><strong id="fav-stat-total">0</strong> saved</span>
                        <span class="favorites-stat-dot" aria-hidden="true"></span>
                        <span class="favorites-stat"><strong id="fav-stat-movies">0</strong> movies</span>
                        <span class="favorites-stat-dot" aria-hidden="true"></span>
                        <span class="favorites-stat"><strong id="fav-stat-tv">0</strong> TV</span>
                    </div>
                </div>
                <div class="favorites-hero-aside">
                    <button type="button" class="favorites-clear" id="clearAllFavorites">
                        <i class="uil uil-trash-alt" aria-hidden="true"></i>
                        <span>Clear all</span>
                    </button>
                </div>
            </header>

            <div class="favorites-toolbar">
                <div class="favorites-tabs" data-tabs data-id="fav-type" role="tablist" aria-label="Filter favorites">
                    <button type="button" class="favorites-tab tab active" data-name="all" role="tab" aria-selected="true">
                        All <em id="fav-tab-all">0</em>
                    </button>
                    <button type="button" class="favorites-tab tab" data-name="movie" role="tab" aria-selected="false">
                        Movies <em id="fav-tab-movie">0</em>
                    </button>
                    <button type="button" class="favorites-tab tab" data-name="tv" role="tab" aria-selected="false">
                        TV Shows <em id="fav-tab-tv">0</em>
                    </button>
                </div>
            </div>

            <section class="favorites-featured" id="favorites-featured" hidden></section>

            <div id="favorites-grid" class="favorites-grid" aria-live="polite"></div>

            <div id="favorites-empty" class="favorites-empty" hidden>
                <div class="favorites-empty-visual" aria-hidden="true">
                    <span></span><span></span><span></span>
                </div>
                <p class="favorites-brand favorites-brand--sm">CHILLFLIX</p>
                <h2>Nothing saved yet</h2>
                <p>Tap the heart on any title while browsing — your watchlist lives here.</p>
                <div class="favorites-empty-actions">
                    <a class="favorites-empty-btn" href="<?= e(url('/movies')) ?>"><i class="uil uil-play" aria-hidden="true"></i> Find a movie</a>
                    <a class="favorites-empty-btn is-ghost" href="<?= e(url('/tv-series')) ?>">Explore TV</a>
                </div>
            </div>
        </div>
    </div>
</main>
"""

CSS = r"""
/* ——— Favorites v2 (ui69) ——— */
.favorites-page {
  --fav-ink: #f4f1ec;
  --fav-muted: rgba(244, 241, 236, 0.62);
  --fav-line: rgba(255, 255, 255, 0.1);
  --fav-hot: #ff4d3d;
  --fav-ember: #db6937;
  position: relative;
  min-height: calc(100dvh - 4.25rem);
  padding-bottom: calc(5.75rem + env(safe-area-inset-bottom, 0px));
  color: var(--fav-ink);
  overflow: clip;
  background:
    radial-gradient(120% 70% at 50% -10%, rgba(220, 53, 69, 0.18), transparent 55%),
    linear-gradient(180deg, #12141b 0%, #0b0d12 48%, #090b10 100%);
}

.favorites-stage {
  position: relative;
}

.favorites-collage {
  position: absolute;
  inset: 0 0 auto;
  height: min(54vh, 26rem);
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 0.35rem;
  opacity: 0.42;
  mask-image: linear-gradient(180deg, #000 35%, transparent 92%);
  -webkit-mask-image: linear-gradient(180deg, #000 35%, transparent 92%);
  pointer-events: none;
  transform: scale(1.04);
  filter: saturate(1.05);
}

.favorites-collage.is-empty {
  opacity: 0.18;
  background:
    repeating-linear-gradient(
      -18deg,
      rgba(255, 255, 255, 0.03) 0 12px,
      transparent 12px 24px
    );
}

.favorites-collage-tile {
  position: relative;
  overflow: hidden;
  min-height: 100%;
  background: #171a22;
  animation: favCollageDrift 14s ease-in-out infinite alternate;
}

.favorites-collage-tile:nth-child(odd) { animation-duration: 16s; }
.favorites-collage-tile:nth-child(3n) { animation-duration: 18s; animation-delay: -4s; }

.favorites-collage-tile img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transform: scale(1.08);
}

@keyframes favCollageDrift {
  from { transform: translateY(0); }
  to { transform: translateY(-3%); }
}

.favorites-stage-shade {
  position: absolute;
  inset: 0 0 auto;
  height: min(54vh, 26rem);
  pointer-events: none;
  background:
    linear-gradient(90deg, rgba(9, 11, 16, 0.82) 8%, rgba(9, 11, 16, 0.28) 55%, rgba(9, 11, 16, 0.75) 100%),
    linear-gradient(180deg, rgba(9, 11, 16, 0.15) 0%, rgba(9, 11, 16, 0.92) 88%, #090b10 100%);
}

.favorites-wrap {
  position: relative;
  z-index: 1;
  padding-top: 1.15rem;
  padding-bottom: 1.75rem;
}

.favorites-hero {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.15rem;
  min-height: 9.5rem;
}

.favorites-brand {
  margin: 0 0 0.2rem;
  font-family: "Bebas Neue", "Arial Narrow", Impact, sans-serif;
  font-size: clamp(1.55rem, 4vw, 2.1rem);
  letter-spacing: 0.14em;
  line-height: 0.95;
  color: var(--fav-hot);
  text-shadow: 0 0 28px rgba(255, 77, 61, 0.28);
}

.favorites-brand--sm {
  font-size: 1.15rem;
  letter-spacing: 0.16em;
  margin-bottom: 0.45rem;
}

.favorites-title {
  margin: 0;
  font-family: "Bebas Neue", "Arial Narrow", Impact, sans-serif;
  font-size: clamp(3.4rem, 11vw, 5.6rem);
  letter-spacing: 0.04em;
  line-height: 0.88;
  color: #fff;
  text-transform: uppercase;
}

.favorites-sub {
  margin: 0.55rem 0 0;
  max-width: 26rem;
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.95rem;
  font-weight: 500;
  line-height: 1.45;
  color: var(--fav-muted);
}

.favorites-stats {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.45rem 0.65rem;
  margin-top: 0.95rem;
  font-family: Outfit, Poppins, sans-serif;
}

.favorites-stat {
  color: rgba(255, 255, 255, 0.72);
  font-size: 0.82rem;
  font-weight: 600;
}

.favorites-stat strong {
  color: #fff;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  margin-right: 0.2rem;
}

.favorites-stat-dot {
  width: 0.28rem;
  height: 0.28rem;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.28);
}

.favorites-clear {
  appearance: none;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border: 1px solid var(--fav-line);
  background: rgba(255, 255, 255, 0.04);
  color: rgba(255, 255, 255, 0.78);
  border-radius: 999px;
  padding: 0.55rem 0.95rem;
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.8rem;
  font-weight: 650;
  cursor: pointer;
  backdrop-filter: blur(10px);
  transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease, transform 0.18s ease;
}

.favorites-clear:hover {
  color: #fff;
  border-color: rgba(255, 77, 61, 0.5);
  background: rgba(255, 77, 61, 0.16);
  transform: translateY(-1px);
}

.favorites-toolbar {
  margin-bottom: 1.15rem;
}

.favorites-tabs {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  padding: 0.3rem;
  border-radius: 999px;
  border: 1px solid var(--fav-line);
  background: rgba(0, 0, 0, 0.35);
  backdrop-filter: blur(12px);
}

.favorites-tab {
  appearance: none;
  border: 0;
  background: transparent;
  color: rgba(255, 255, 255, 0.62);
  border-radius: 999px;
  padding: 0.5rem 0.9rem;
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.82rem;
  font-weight: 650;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  transition: background 0.18s ease, color 0.18s ease, transform 0.18s ease;
}

.favorites-tab em {
  font-style: normal;
  min-width: 1.25rem;
  padding: 0.08rem 0.38rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  color: inherit;
  font-size: 0.72rem;
  font-weight: 750;
  text-align: center;
}

.favorites-tab:hover { color: #fff; }

.favorites-tab.active {
  color: #fff;
  background: linear-gradient(135deg, #ff3b2f, var(--fav-ember));
  box-shadow: 0 8px 20px rgba(255, 59, 47, 0.28);
}

.favorites-tab.active em {
  background: rgba(0, 0, 0, 0.22);
}

/* Featured spotlight */
.favorites-featured {
  position: relative;
  margin-bottom: 1.25rem;
}

.favorites-featured[hidden] { display: none !important; }

.fav-spot {
  position: relative;
  display: grid;
  grid-template-columns: minmax(7.5rem, 10.5rem) 1fr;
  gap: 0.95rem;
  align-items: stretch;
  min-height: 11.5rem;
  padding: 0.85rem;
  border-radius: 1.35rem;
  overflow: hidden;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(255, 255, 255, 0.03);
  text-decoration: none;
  color: inherit;
  isolation: isolate;
  animation: favSpotIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes favSpotIn {
  from { opacity: 0; transform: translateY(14px) scale(0.985); }
  to { opacity: 1; transform: none; }
}

.fav-spot-bg {
  position: absolute;
  inset: 0;
  z-index: -1;
  background-size: cover;
  background-position: center top;
  filter: blur(18px) saturate(1.15);
  transform: scale(1.18);
  opacity: 0.45;
}

.fav-spot-bg::after {
  content: "";
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, rgba(9, 11, 16, 0.2), rgba(9, 11, 16, 0.82) 55%, rgba(9, 11, 16, 0.92));
}

.fav-spot-poster {
  position: relative;
  border-radius: 0.95rem;
  overflow: hidden;
  aspect-ratio: 2 / 3;
  box-shadow: 0 14px 30px rgba(0, 0, 0, 0.4);
  border: 1px solid rgba(255, 255, 255, 0.12);
}

.fav-spot-poster img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.fav-spot-body {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 0.45rem;
  min-width: 0;
  padding: 0.15rem 0.2rem 0.15rem 0;
}

.fav-spot-kicker {
  margin: 0;
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.7rem;
  font-weight: 750;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: #ffb089;
}

.fav-spot-title {
  margin: 0;
  font-family: Outfit, Poppins, sans-serif;
  font-size: clamp(1.25rem, 3.5vw, 1.85rem);
  font-weight: 800;
  letter-spacing: -0.02em;
  line-height: 1.15;
  color: #fff;
}

.fav-spot-meta {
  margin: 0;
  color: rgba(255, 255, 255, 0.62);
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.84rem;
  font-weight: 600;
}

.fav-spot-cta {
  margin-top: 0.45rem;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  width: fit-content;
  padding: 0.55rem 1rem;
  border-radius: 999px;
  background: linear-gradient(135deg, #ff3b2f, var(--fav-ember));
  color: #fff;
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.84rem;
  font-weight: 750;
  box-shadow: 0 10px 22px rgba(255, 59, 47, 0.28);
  transition: transform 0.18s ease;
}

.fav-spot:hover .fav-spot-cta { transform: translateX(3px); }

.fav-spot-remove {
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
  z-index: 3;
  width: 2.2rem;
  height: 2.2rem;
  border: 0;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(8, 10, 14, 0.55);
  color: #ff7a86;
  backdrop-filter: blur(8px);
  cursor: pointer;
  transition: transform 0.18s ease, background 0.18s ease, color 0.18s ease;
}

.fav-spot-remove:hover {
  transform: scale(1.08);
  background: rgba(255, 59, 47, 0.95);
  color: #fff;
}

/* Grid cards */
.favorites-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.9rem 0.7rem;
}

@media (min-width: 576px) {
  .favorites-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.05rem 0.85rem; }
}
@media (min-width: 768px) {
  .favorites-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
@media (min-width: 1100px) {
  .favorites-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 1.2rem 1rem; }
}
@media (min-width: 1400px) {
  .favorites-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
}

.fav-card {
  position: relative;
  animation: favCardIn 0.42s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.fav-card.is-leaving {
  animation: favCardOut 0.28s ease forwards;
  pointer-events: none;
}

@keyframes favCardIn {
  from { opacity: 0; transform: translateY(16px) scale(0.96); }
  to { opacity: 1; transform: none; }
}
@keyframes favCardOut {
  to { opacity: 0; transform: scale(0.9); filter: blur(2px); }
}

.fav-card-poster {
  position: relative;
  display: block;
  aspect-ratio: 2 / 3;
  border-radius: 1.05rem;
  overflow: hidden;
  background: #151821;
  border: 1px solid rgba(255, 255, 255, 0.08);
  box-shadow: 0 12px 28px rgba(0, 0, 0, 0.3);
  text-decoration: none;
  color: inherit;
  transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
}

.fav-card:hover .fav-card-poster,
.fav-card:focus-within .fav-card-poster {
  transform: translateY(-4px) scale(1.015);
  border-color: rgba(255, 176, 137, 0.4);
  box-shadow: 0 18px 36px rgba(0, 0, 0, 0.42);
}

.fav-card-poster img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 0.4s ease;
}

.fav-card:hover .fav-card-poster img { transform: scale(1.06); }

.fav-card-poster::after {
  content: "";
  position: absolute;
  inset: auto 0 0;
  height: 55%;
  background: linear-gradient(180deg, transparent, rgba(6, 8, 12, 0.94));
  pointer-events: none;
}

.fav-card-play {
  position: absolute;
  left: 50%;
  top: 44%;
  z-index: 2;
  width: 2.7rem;
  height: 2.7rem;
  margin: -1.35rem 0 0 -1.35rem;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #ff3b2f, var(--fav-ember));
  color: #fff;
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.35);
  opacity: 0;
  transform: scale(0.7);
  transition: opacity 0.2s ease, transform 0.2s ease;
  pointer-events: none;
}

.fav-card-play i { font-size: 1.15rem; margin-left: 0.1rem; }

.fav-card:hover .fav-card-play,
.fav-card:focus-within .fav-card-play {
  opacity: 1;
  transform: scale(1);
}

.fav-card-badge {
  position: absolute;
  top: 0.55rem;
  left: 0.55rem;
  z-index: 2;
  padding: 0.22rem 0.5rem;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.55);
  border: 1px solid rgba(255, 255, 255, 0.12);
  color: #fff;
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.64rem;
  font-weight: 750;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  backdrop-filter: blur(8px);
}

.fav-card-remove {
  position: absolute;
  top: 0.45rem;
  right: 0.45rem;
  z-index: 3;
  width: 2.1rem;
  height: 2.1rem;
  border: 0;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(8, 10, 14, 0.58);
  color: #ff7a86;
  backdrop-filter: blur(8px);
  cursor: pointer;
  transition: transform 0.18s ease, background 0.18s ease, color 0.18s ease;
}

.fav-card-remove:hover {
  transform: scale(1.08);
  background: rgba(255, 59, 47, 0.95);
  color: #fff;
}

.fav-card-meta {
  position: absolute;
  left: 0.65rem;
  right: 0.65rem;
  bottom: 0.65rem;
  z-index: 2;
  display: flex;
  flex-direction: column;
  gap: 0.12rem;
}

.fav-card-meta strong {
  font-family: Outfit, Poppins, sans-serif;
  color: #fff;
  font-size: 0.9rem;
  font-weight: 750;
  line-height: 1.25;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.fav-card-meta em {
  font-style: normal;
  color: rgba(255, 255, 255, 0.62);
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.72rem;
  font-weight: 600;
}

/* Empty */
.favorites-empty {
  position: relative;
  text-align: center;
  padding: 3.4rem 1.2rem 2.8rem;
  margin-top: 0.35rem;
  border-radius: 1.5rem;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background:
    radial-gradient(80% 80% at 50% 0%, rgba(255, 77, 61, 0.14), transparent 60%),
    rgba(255, 255, 255, 0.025);
  overflow: hidden;
}

.favorites-empty[hidden] { display: none !important; }

.favorites-empty-visual {
  position: relative;
  width: 7.5rem;
  height: 5.5rem;
  margin: 0 auto 1rem;
}

.favorites-empty-visual span {
  position: absolute;
  top: 0.4rem;
  width: 3.1rem;
  height: 4.5rem;
  border-radius: 0.7rem;
  border: 1px solid rgba(255, 255, 255, 0.14);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.02));
  box-shadow: 0 10px 22px rgba(0, 0, 0, 0.25);
}

.favorites-empty-visual span:nth-child(1) {
  left: 0.2rem;
  transform: rotate(-14deg);
  animation: favEmptyTilt 3.2s ease-in-out infinite;
}
.favorites-empty-visual span:nth-child(2) {
  left: 2.2rem;
  top: 0;
  z-index: 2;
  background: linear-gradient(160deg, rgba(255, 77, 61, 0.35), rgba(219, 105, 55, 0.18));
  border-color: rgba(255, 140, 110, 0.35);
  animation: favEmptyBob 2.6s ease-in-out infinite;
}
.favorites-empty-visual span:nth-child(3) {
  right: 0.2rem;
  transform: rotate(14deg);
  animation: favEmptyTilt 3.2s ease-in-out infinite reverse;
}

@keyframes favEmptyBob {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-7px); }
}
@keyframes favEmptyTilt {
  0%, 100% { transform: rotate(-14deg) translateY(0); }
  50% { transform: rotate(-10deg) translateY(-4px); }
}

.favorites-empty h2 {
  margin: 0 0 0.4rem;
  font-family: Outfit, Poppins, sans-serif;
  font-size: 1.45rem;
  font-weight: 800;
  color: #fff;
}

.favorites-empty p {
  margin: 0 auto 1.2rem;
  max-width: 22rem;
  color: var(--fav-muted);
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.92rem;
  line-height: 1.45;
}

.favorites-empty-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.55rem;
}

.favorites-empty-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  min-height: 2.65rem;
  padding: 0.55rem 1.15rem;
  border-radius: 999px;
  text-decoration: none;
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.9rem;
  font-weight: 750;
  color: #fff;
  background: linear-gradient(135deg, #ff3b2f, var(--fav-ember));
  box-shadow: 0 10px 22px rgba(255, 59, 47, 0.28);
}

.favorites-empty-btn.is-ghost {
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.12);
  box-shadow: none;
  color: rgba(255, 255, 255, 0.9);
}

@media (max-width: 767.98px) {
  .favorites-hero {
    flex-direction: column;
    align-items: stretch;
    min-height: 0;
  }
  .favorites-hero-aside {
    align-self: flex-start;
  }
  .favorites-collage {
    grid-template-columns: repeat(4, 1fr);
    height: min(42vh, 18rem);
  }
  .favorites-stage-shade { height: min(42vh, 18rem); }
  .fav-spot {
    grid-template-columns: 6.6rem 1fr;
    min-height: 0;
    padding: 0.7rem;
  }
  .fav-spot-title { font-size: 1.15rem; }
  .fav-card-meta strong { font-size: 0.82rem; }
  .fav-card-play { opacity: 0.95; transform: scale(0.92); width: 2.35rem; height: 2.35rem; margin: -1.175rem 0 0 -1.175rem; }
}

@media (prefers-reduced-motion: reduce) {
  .favorites-collage-tile,
  .fav-card,
  .fav-spot,
  .favorites-empty-visual span,
  .fav-card-poster,
  .fav-card-poster img {
    animation: none !important;
    transition: none !important;
  }
}
"""

RENDER_JS = r"""
  // Favorites page render
  function favPosterUrl(it) {
    var imgBase = (window.APP && APP.imgBase) || 'https://image.tmdb.org/t/p';
    if (it.poster) return it.poster;
    if (it.poster_path) return imgBase + '/w600_and_h900_bestv2' + it.poster_path;
    return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='450'%3E%3Crect width='100%25' height='100%25' fill='%23151821'/%3E%3C/svg%3E";
  }

  function favHref(it, title, type) {
    var slug = String(title).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'item';
    return (window.APP && APP.baseUrl ? APP.baseUrl : '') + '/' + (type === 'tv' ? 'tv' : 'movie') + '/' + slug + '/' + it.id;
  }

  function renderFavoritesCollage(items) {
    var $c = $('#favorites-collage');
    if (!$c.length) return;
    if (!items.length) {
      $c.addClass('is-empty').empty();
      return;
    }
    $c.removeClass('is-empty');
    var tiles = [];
    var pool = items.slice(0, 12);
    for (var i = 0; i < 6; i++) {
      var it = pool[i % pool.length];
      tiles.push(
        '<div class="favorites-collage-tile"><img src="' +
          favPosterUrl(it) +
          '" alt="" loading="lazy" decoding="async"></div>'
      );
    }
    $c.html(tiles.join(''));
  }

  function renderFavoritesPage() {
    var $grid = $('#favorites-grid');
    if (!$grid.length) return;
    var typeFilter = $('.favorites-tabs[data-id="fav-type"] .favorites-tab.active').data('name') || 'all';
    var map = favStore();
    var allItems = Object.keys(map).map(function (k) { return map[k]; });
    allItems.sort(function (a, b) {
      return new Date(b.saved_at || 0) - new Date(a.saved_at || 0);
    });
    var movieCount = allItems.filter(function (i) { return (i.type || i.mediaType) !== 'tv'; }).length;
    var tvCount = allItems.length - movieCount;
    $('#fav-stat-total, #favorites-count-num').text(String(allItems.length));
    $('#fav-stat-movies').text(String(movieCount));
    $('#fav-stat-tv').text(String(tvCount));
    $('#fav-tab-all').text(String(allItems.length));
    $('#fav-tab-movie').text(String(movieCount));
    $('#fav-tab-tv').text(String(tvCount));
    renderFavoritesCollage(allItems);

    var items = allItems;
    if (typeFilter !== 'all') {
      items = items.filter(function (i) { return (i.type || i.mediaType) === typeFilter; });
    }

    var $spot = $('#favorites-featured');
    if (!items.length) {
      $grid.empty();
      $spot.attr('hidden', true).empty();
      $('#favorites-empty').removeAttr('hidden');
      return;
    }
    $('#favorites-empty').attr('hidden', true);

    var featured = items[0];
    var rest = items.slice(1);
    var fType = featured.type || featured.mediaType || 'movie';
    var fTitle = featured.title || featured.name || 'Untitled';
    var fYear = featured.year || '';
    var fPoster = favPosterUrl(featured);
    var fHref = favHref(featured, fTitle, fType);
    var fSafe = $('<div>').text(fTitle).html();
    var fLabel = fType === 'tv' ? 'TV Show' : 'Movie';
    $spot.html(
      '<a class="fav-spot" href="' + fHref + '" aria-label="Play ' + fSafe + '">' +
        '<span class="fav-spot-bg" style="background-image:url(\'' + fPoster.replace(/'/g, '%27') + '\')"></span>' +
        '<span class="fav-spot-poster"><img src="' + fPoster + '" alt="" width="300" height="450" loading="eager" decoding="async"></span>' +
        '<span class="fav-spot-body">' +
          '<p class="fav-spot-kicker">Recently saved</p>' +
          '<h2 class="fav-spot-title">' + fSafe + '</h2>' +
          '<p class="fav-spot-meta">' + fLabel + (fYear ? ' · ' + fYear : '') + '</p>' +
          '<span class="fav-spot-cta"><i class="uil uil-play" aria-hidden="true"></i> Watch now</span>' +
        '</span>' +
      '</a>' +
      '<button type="button" class="fav-spot-remove remove-fav" data-id="' + String(featured.id) + '" aria-label="Remove ' + fSafe + ' from favorites">' +
        '<i class="uil uil-heart" aria-hidden="true"></i>' +
      '</button>'
    ).removeAttr('hidden');

    var html = rest.map(function (it, idx) {
      var type = it.type || it.mediaType || 'movie';
      var title = it.title || it.name || 'Untitled';
      var year = it.year || '';
      var poster = favPosterUrl(it);
      var href = favHref(it, title, type);
      var safeTitle = $('<div>').text(title).html();
      var typeLabel = type === 'tv' ? 'TV' : 'Movie';
      var delay = Math.min(idx, 10) * 0.04;
      return '' +
        '<article class="fav-card" data-id="' + String(it.id) + '" style="animation-delay:' + delay + 's">' +
          '<a class="fav-card-poster" href="' + href + '" aria-label="' + safeTitle + '">' +
            '<span class="fav-card-badge">' + typeLabel + '</span>' +
            '<span class="fav-card-play" aria-hidden="true"><i class="uil uil-play"></i></span>' +
            '<img src="' + poster + '" alt="" loading="lazy" decoding="async" width="300" height="450">' +
            '<span class="fav-card-meta">' +
              '<strong>' + safeTitle + '</strong>' +
              '<em>' + typeLabel + (year ? ' · ' + year : '') + '</em>' +
            '</span>' +
          '</a>' +
          '<button type="button" class="fav-card-remove remove-fav" data-id="' + String(it.id) + '" aria-label="Remove ' + safeTitle + ' from favorites">' +
            '<i class="uil uil-heart" aria-hidden="true"></i>' +
          '</button>' +
        '</article>';
    }).join('');
    $grid.html(html);
  }

  $(document).on('click', '.favorites-tabs[data-id="fav-type"] .favorites-tab', function (e) {
    e.preventDefault();
    var $tab = $(this);
    $tab.addClass('active').attr('aria-selected', 'true')
      .siblings('.favorites-tab').removeClass('active').attr('aria-selected', 'false');
    renderFavoritesPage();
  });

  $(document).on('click', '[data-tabs][data-id="fav-type"] .tab', function () {
    setTimeout(renderFavoritesPage, 0);
  });

  $(document).on('click', '#clearAllFavorites', function () {
    if (confirm('Clear your entire watchlist?')) {
      clearFavStorage();
      updateFavCounter();
      renderFavoritesPage();
    }
  });

  $(document).on('click', '.remove-fav', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var id = String($(this).data('id'));
    var $card = $(this).closest('.fav-card, #favorites-featured');
    var finish = function () {
      var map = favStore();
      delete map[id];
      saveFavs(map);
      updateFavCounter();
      renderFavoritesPage();
    };
    if ($card.hasClass('fav-card')) {
      $card.addClass('is-leaving');
      setTimeout(finish, 240);
    } else {
      finish();
    }
  });
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def patch_fonts() -> None:
    path = ROOT / "app/Views/layouts/main.php"
    text = path.read_text()
    old = 'family=Poppins:wght@400;500;600;700&display=swap'
    new = 'family=Bebas+Neue&family=Outfit:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap'
    if "Bebas+Neue" in text:
        print("fonts already present")
    elif old in text:
        text = text.replace(old, new, 1)
        path.write_text(text)
        print("fonts added")
    else:
        print("WARN: poppins font link not found")


def main() -> None:
    patch_fonts()
    (ROOT / "app/Views/pages/favorites.php").write_text(PAGE)
    print("favorites.php v2 written")

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    # Remove prior favorites polish blocks
    css = re.sub(
        r"/\* ——— Favorites polish \(ui68\) ——— \*/[\s\S]*?(?=\n/\* ———|\Z)",
        "",
        css,
        count=1,
    )
    css = re.sub(
        r"/\* ——— Favorites v2 \(ui69\) ——— \*/[\s\S]*?(?=\n/\* ———|\Z)",
        "",
        css,
        count=1,
    )
    css_path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("favorites css v2 added")

    js_path = ROOT / "public/assets/js/app.js"
    js = js_path.read_text()
    start = js.find("  // Favorites page render")
    if start < 0:
        raise SystemExit("favorites render block not found")
    end = js.find("\n  var tipCache = {};", start)
    if end < 0:
        raise SystemExit("tipCache marker not found")
    js_path.write_text(js[:start] + RENDER_JS.rstrip() + "\n" + js[end:])
    print("favorites js v2 replaced")

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
