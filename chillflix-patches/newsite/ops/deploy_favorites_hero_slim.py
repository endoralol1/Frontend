#!/usr/bin/env python3
"""Slim Favorites header — remove the heavy WATCHLIST banner; keep the good parts."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui70"

PAGE = r"""<main class="page-pad-top favorites-page">
    <div class="container favorites-wrap">
        <header class="favorites-bar">
            <div class="favorites-bar-copy">
                <h1 class="favorites-bar-title">Favorites</h1>
                <p class="favorites-bar-meta" id="favorites-stats" aria-live="polite">
                    <span id="fav-stat-total">0</span> saved
                </p>
            </div>
            <button type="button" class="favorites-clear" id="clearAllFavorites">
                <i class="uil uil-trash-alt" aria-hidden="true"></i>
                <span>Clear all</span>
            </button>
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

# CSS overrides layered on v2 — hide/remove banner look, restyle compact bar
CSS_EXTRA = r"""
/* ——— Favorites hero slim (ui70) ——— */
.favorites-page {
  background: linear-gradient(180deg, #12141b 0%, #0b0d12 55%, #090b10 100%) !important;
}

.favorites-collage,
.favorites-stage-shade {
  display: none !important;
}

.favorites-stage {
  position: static !important;
}

.favorites-wrap {
  padding-top: 0.65rem !important;
}

.favorites-hero {
  display: none !important;
}

.favorites-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.85rem;
}

.favorites-bar-copy {
  min-width: 0;
}

.favorites-bar-title {
  margin: 0;
  font-family: Outfit, Poppins, sans-serif;
  font-size: 1.35rem;
  font-weight: 800;
  letter-spacing: -0.02em;
  color: #fff;
  line-height: 1.15;
}

.favorites-bar-meta {
  margin: 0.2rem 0 0;
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.8rem;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.5);
}

.favorites-bar-meta span {
  color: rgba(255, 255, 255, 0.82);
  font-weight: 750;
  font-variant-numeric: tabular-nums;
}

.favorites-clear {
  flex-shrink: 0;
}

.favorites-toolbar {
  margin-bottom: 0.95rem !important;
}

.favorites-empty .favorites-brand {
  display: none !important;
}
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def patch_js() -> None:
    path = ROOT / "public/assets/js/app.js"
    text = path.read_text()

    # Neutralize collage renderer (element may be gone)
    if "function renderFavoritesCollage" in text:
        old = """  function renderFavoritesCollage(items) {
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
  }"""
        new = """  function renderFavoritesCollage(items) {
    /* collage hero removed in ui70 */
  }"""
        if old in text:
            text = text.replace(old, new, 1)
            print("collage renderer stubbed")
        else:
            # already stubbed or different
            if "collage hero removed" in text:
                print("collage already stubbed")
            else:
                print("WARN: collage function shape changed")

    # Simplify stats update — keep total; movies/tv ids may be gone
    # Existing code already sets fav-stat-total and optional movie/tv — fine if missing

    path.write_text(text)


def main() -> None:
    (ROOT / "app/Views/pages/favorites.php").write_text(PAGE)
    print("favorites.php slim header written")

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    marker = "/* ——— Favorites hero slim (ui70) ——— */"
    if marker in css:
        css = re.sub(
            r"/\* ——— Favorites hero slim \(ui70\) ——— \*/[\s\S]*?(?=\n/\* ———|\Z)",
            CSS_EXTRA.strip() + "\n\n",
            css,
            count=1,
        )
        print("slim css refreshed")
    else:
        css = css.rstrip() + "\n\n" + CSS_EXTRA.strip() + "\n"
        print("slim css added")
    css_path.write_text(css)

    patch_js()

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
