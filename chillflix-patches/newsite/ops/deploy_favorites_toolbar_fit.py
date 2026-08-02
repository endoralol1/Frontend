#!/usr/bin/env python3
"""Fold Favorites header into the same card/pill toolbar as the filters."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui71"

PAGE = r"""<main class="page-pad-top favorites-page">
    <div class="container favorites-wrap">
        <h1 class="favorites-sr-only">Favorites</h1>

        <div class="favorites-panel">
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
                <button type="button" class="favorites-clear" id="clearAllFavorites" title="Clear all favorites">
                    <i class="uil uil-trash-alt" aria-hidden="true"></i>
                    <span>Clear</span>
                </button>
            </div>
        </div>

        <section class="favorites-featured" id="favorites-featured" hidden></section>

        <div id="favorites-grid" class="favorites-grid" aria-live="polite"></div>

        <!-- keep for JS count sync -->
        <span id="fav-stat-total" hidden>0</span>
        <span id="fav-stat-movies" hidden>0</span>
        <span id="fav-stat-tv" hidden>0</span>
        <span id="favorites-stats" hidden></span>

        <div id="favorites-empty" class="favorites-empty" hidden>
            <div class="favorites-empty-visual" aria-hidden="true">
                <span></span><span></span><span></span>
            </div>
            <h2>Nothing saved yet</h2>
            <p>Tap the heart on any title while browsing — your watchlist lives here.</p>
            <div class="favorites-empty-actions">
                <a class="favorites-empty-btn" href="<?= e(url('/movies')) ?>"><i class="uil uil-play" aria-hidden="true"></i> Find a movie</a>
                <a class="favorites-empty-btn is-ghost" href="<?= e(url('/tv-series')) ?>">Explore TV</a>
            </div>
        </div>
    </div>
</main>
"""

CSS = r"""
/* ——— Favorites toolbar fit (ui71) ——— */
.favorites-page {
  background: #0b0d12 !important;
}

.favorites-collage,
.favorites-stage-shade,
.favorites-hero,
.favorites-bar {
  display: none !important;
}

.favorites-wrap {
  padding-top: 0.75rem !important;
}

.favorites-sr-only {
  position: absolute !important;
  width: 1px !important;
  height: 1px !important;
  padding: 0 !important;
  margin: -1px !important;
  overflow: hidden !important;
  clip: rect(0, 0, 0, 0) !important;
  white-space: nowrap !important;
  border: 0 !important;
}

.favorites-panel {
  margin-bottom: 0.85rem;
  padding: 0.55rem;
  border-radius: 1.2rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(255, 255, 255, 0.03);
}

.favorites-toolbar {
  display: flex !important;
  align-items: center;
  justify-content: space-between;
  gap: 0.55rem;
  margin-bottom: 0 !important;
}

.favorites-tabs {
  display: flex !important;
  flex: 1 1 auto;
  flex-wrap: nowrap;
  gap: 0.3rem !important;
  padding: 0 !important;
  border: 0 !important;
  background: transparent !important;
  backdrop-filter: none !important;
  overflow-x: auto;
  scrollbar-width: none;
  -ms-overflow-style: none;
}

.favorites-tabs::-webkit-scrollbar {
  display: none;
  width: 0;
  height: 0;
}

.favorites-tab {
  flex: 0 0 auto;
  padding: 0.48rem 0.8rem !important;
  font-size: 0.8rem !important;
}

.favorites-clear {
  flex: 0 0 auto;
  margin: 0;
  min-height: 2.25rem;
  padding: 0.45rem 0.8rem !important;
  border-radius: 999px !important;
  border: 1px solid rgba(255, 255, 255, 0.1) !important;
  background: rgba(255, 255, 255, 0.04) !important;
  color: rgba(255, 255, 255, 0.72) !important;
  font-family: Outfit, Poppins, sans-serif !important;
  font-size: 0.78rem !important;
  font-weight: 650 !important;
  box-shadow: none !important;
}

.favorites-clear:hover {
  color: #fff !important;
  border-color: rgba(255, 77, 61, 0.45) !important;
  background: rgba(255, 77, 61, 0.16) !important;
  transform: none !important;
}

.favorites-featured {
  margin-top: 0 !important;
}

.favorites-empty {
  margin-top: 0.25rem !important;
}

@media (max-width: 380px) {
  .favorites-clear span {
    display: none;
  }
  .favorites-clear {
    width: 2.25rem;
    padding: 0 !important;
    justify-content: center;
  }
}
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def main() -> None:
    (ROOT / "app/Views/pages/favorites.php").write_text(PAGE)
    print("favorites.php toolbar-fit written")

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    # Replace prior slim block if present; always append/refresh ui71 block
    for marker in (
        "/* ——— Favorites hero slim (ui70) ——— */",
        "/* ——— Favorites toolbar fit (ui71) ——— */",
    ):
        if marker in css:
            css = re.sub(
                re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)",
                "",
                css,
                count=1,
            )
    css_path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("toolbar-fit css applied")

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
