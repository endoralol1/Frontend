#!/usr/bin/env python3
"""Add 'Your Favorites' section title with the site's accent + right line."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui72"

PAGE = r"""<main class="page-pad-top favorites-page">
    <div class="container favorites-wrap">
        <div class="section favorites-section">
            <div class="head">
                <div class="start">
                    <h1 class="title gardiently">Your Favorites</h1>
                </div>
            </div>

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
    </div>
</main>
"""

CSS = r"""
/* ——— Favorites section title (ui72) ——— */
.favorites-page .favorites-section > .head {
  margin-bottom: 0.85rem;
}

.favorites-page .favorites-section > .head .start .title {
  overflow: visible;
  text-overflow: unset;
}

/* Same orange accent bar as other rails, plus the right-side line */
.favorites-page .favorites-section > .head .start .title::after {
  content: "";
  flex: 1 1 auto;
  height: 1px;
  min-width: 2.5rem;
  margin-left: 0.15rem;
  background: linear-gradient(
    90deg,
    rgba(255, 255, 255, 0.18) 0%,
    rgba(255, 255, 255, 0.06) 55%,
    transparent 100%
  );
}

.favorites-sr-only {
  display: none !important;
}
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def main() -> None:
    (ROOT / "app/Views/pages/favorites.php").write_text(PAGE)
    print("favorites.php title added")

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    marker = "/* ——— Favorites section title (ui72) ——— */"
    if marker in css:
        css = re.sub(
            re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)",
            "",
            css,
            count=1,
        )
    css_path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("title css added")

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
