#!/usr/bin/env python3
"""Polish Favorites / Watchlist page UI."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui68"

PAGE = """<main class="page-pad-top favorites-page">
    <div class="favorites-atmosphere" aria-hidden="true"></div>
    <div class="container favorites-wrap">
        <header class="favorites-hero">
            <div class="favorites-hero-copy">
                <p class="favorites-kicker"><i class="uil uil-heart" aria-hidden="true"></i> Watchlist</p>
                <h1 class="favorites-title">Favorites</h1>
                <p class="favorites-sub">Titles you’ve saved for later — tap to watch, or remove with the heart.</p>
            </div>
            <div class="favorites-hero-aside">
                <div class="favorites-count" id="favorites-count" aria-live="polite">
                    <strong id="favorites-count-num">0</strong>
                    <span>saved</span>
                </div>
                <button type="button" class="favorites-clear" id="clearAllFavorites">
                    <i class="uil uil-trash-alt" aria-hidden="true"></i>
                    <span>Clear all</span>
                </button>
            </div>
        </header>

        <div class="favorites-toolbar">
            <div class="favorites-tabs" data-tabs data-id="fav-type" role="tablist" aria-label="Filter favorites">
                <button type="button" class="favorites-tab tab active" data-name="all" role="tab" aria-selected="true">All</button>
                <button type="button" class="favorites-tab tab" data-name="movie" role="tab" aria-selected="false">Movies</button>
                <button type="button" class="favorites-tab tab" data-name="tv" role="tab" aria-selected="false">TV Shows</button>
            </div>
        </div>

        <div id="favorites-grid" class="favorites-grid" aria-live="polite"></div>

        <div id="favorites-empty" class="favorites-empty" hidden>
            <div class="favorites-empty-orb" aria-hidden="true"></div>
            <i class="uil uil-heart-break" aria-hidden="true"></i>
            <h2>Your watchlist is empty</h2>
            <p>Heart a movie or show while browsing, and it’ll show up here.</p>
            <div class="favorites-empty-actions">
                <a class="favorites-empty-btn" href="<?= e(url('/movies')) ?>">Browse movies</a>
                <a class="favorites-empty-btn is-ghost" href="<?= e(url('/tv-series')) ?>">Browse TV</a>
            </div>
        </div>
    </div>
</main>
"""

CSS = """
/* ——— Favorites polish (ui68) ——— */
.favorites-page {
  position: relative;
  min-height: calc(100dvh - 4.5rem);
  padding-bottom: calc(5.5rem + env(safe-area-inset-bottom, 0px));
  overflow: clip;
}

.favorites-atmosphere {
  pointer-events: none;
  position: absolute;
  inset: 0 0 auto;
  height: min(52vh, 28rem);
  background:
    radial-gradient(70% 80% at 12% 0%, rgba(220, 53, 69, 0.28), transparent 58%),
    radial-gradient(55% 70% at 88% 8%, rgba(219, 105, 55, 0.2), transparent 55%),
    linear-gradient(180deg, rgba(18, 20, 28, 0.55) 0%, rgba(10, 12, 16, 0) 100%);
  z-index: 0;
}

.favorites-wrap {
  position: relative;
  z-index: 1;
  padding-top: 0.85rem;
  padding-bottom: 1.5rem;
}

.favorites-hero {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.1rem;
}

.favorites-kicker {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0 0 0.35rem;
  color: #ffb089;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.favorites-kicker i {
  font-size: 0.95rem;
}

.favorites-title {
  margin: 0;
  color: #fff;
  font-size: clamp(1.85rem, 4.5vw, 2.6rem);
  font-weight: 800;
  letter-spacing: -0.03em;
  line-height: 1.05;
  background: linear-gradient(120deg, #fff 20%, #ffd0bc 70%, #ff8f6b 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}

.favorites-sub {
  margin: 0.45rem 0 0;
  max-width: 28rem;
  color: rgba(255, 255, 255, 0.55);
  font-size: 0.9rem;
  line-height: 1.4;
}

.favorites-hero-aside {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.55rem;
  flex-shrink: 0;
}

.favorites-count {
  display: inline-flex;
  align-items: baseline;
  gap: 0.35rem;
  padding: 0.45rem 0.8rem;
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(255, 255, 255, 0.04);
  color: rgba(255, 255, 255, 0.7);
  font-size: 0.78rem;
  font-weight: 600;
}

.favorites-count strong {
  color: #fff;
  font-size: 1.15rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

.favorites-clear {
  appearance: none;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(255, 255, 255, 0.04);
  color: rgba(255, 255, 255, 0.72);
  border-radius: 999px;
  padding: 0.45rem 0.85rem;
  font: inherit;
  font-size: 0.78rem;
  font-weight: 650;
  cursor: pointer;
  transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
}

.favorites-clear:hover {
  color: #fff;
  border-color: rgba(220, 53, 69, 0.45);
  background: rgba(220, 53, 69, 0.16);
}

.favorites-toolbar {
  margin-bottom: 1.05rem;
}

.favorites-tabs {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  padding: 0.28rem;
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(0, 0, 0, 0.28);
}

.favorites-tab {
  appearance: none;
  border: 0;
  background: transparent;
  color: rgba(255, 255, 255, 0.62);
  border-radius: 999px;
  padding: 0.48rem 0.95rem;
  font: inherit;
  font-size: 0.82rem;
  font-weight: 650;
  cursor: pointer;
  transition: background 0.18s ease, color 0.18s ease, transform 0.18s ease;
}

.favorites-tab:hover {
  color: #fff;
}

.favorites-tab.active {
  color: #fff;
  background: linear-gradient(135deg, rgba(220, 53, 69, 0.95), rgba(219, 105, 55, 0.92));
  box-shadow: 0 6px 16px rgba(220, 53, 69, 0.28);
}

.favorites-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.85rem 0.7rem;
}

@media (min-width: 576px) {
  .favorites-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem 0.85rem;
  }
}

@media (min-width: 768px) {
  .favorites-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (min-width: 1100px) {
  .favorites-grid {
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 1.15rem 1rem;
  }
}

@media (min-width: 1400px) {
  .favorites-grid {
    grid-template-columns: repeat(6, minmax(0, 1fr));
  }
}

.fav-card {
  position: relative;
  display: block;
  border: 0;
  background: transparent;
  color: inherit;
  text-decoration: none;
  animation: favCardIn 0.38s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.fav-card:nth-child(1) { animation-delay: 0.02s; }
.fav-card:nth-child(2) { animation-delay: 0.05s; }
.fav-card:nth-child(3) { animation-delay: 0.08s; }
.fav-card:nth-child(4) { animation-delay: 0.11s; }
.fav-card:nth-child(5) { animation-delay: 0.14s; }
.fav-card:nth-child(6) { animation-delay: 0.17s; }
.fav-card:nth-child(n+7) { animation-delay: 0.2s; }

@keyframes favCardIn {
  from { opacity: 0; transform: translateY(12px) scale(0.98); }
  to { opacity: 1; transform: none; }
}

.fav-card-poster {
  position: relative;
  display: block;
  aspect-ratio: 2 / 3;
  border-radius: 1rem;
  overflow: hidden;
  background: #171a22;
  border: 1px solid rgba(255, 255, 255, 0.08);
  box-shadow: 0 10px 28px rgba(0, 0, 0, 0.28);
  transition: transform 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease;
}

.fav-card:hover .fav-card-poster,
.fav-card:focus-within .fav-card-poster {
  transform: translateY(-3px);
  border-color: rgba(255, 176, 137, 0.35);
  box-shadow: 0 16px 34px rgba(0, 0, 0, 0.38);
}

.fav-card-poster img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 0.35s ease;
}

.fav-card:hover .fav-card-poster img {
  transform: scale(1.04);
}

.fav-card-poster::after {
  content: "";
  position: absolute;
  inset: auto 0 0;
  height: 48%;
  background: linear-gradient(180deg, transparent, rgba(8, 10, 14, 0.92));
  pointer-events: none;
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
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  backdrop-filter: blur(8px);
}

.fav-card-remove {
  position: absolute;
  top: 0.45rem;
  right: 0.45rem;
  z-index: 3;
  width: 2.05rem;
  height: 2.05rem;
  border: 0;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(12, 14, 18, 0.62);
  color: #ff6b7a;
  backdrop-filter: blur(8px);
  cursor: pointer;
  transition: transform 0.18s ease, background 0.18s ease, color 0.18s ease;
}

.fav-card-remove:hover {
  transform: scale(1.08);
  background: rgba(220, 53, 69, 0.92);
  color: #fff;
}

.fav-card-remove i {
  font-size: 1.05rem;
}

.fav-card-meta {
  position: absolute;
  left: 0.65rem;
  right: 0.65rem;
  bottom: 0.65rem;
  z-index: 2;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.fav-card-meta strong {
  color: #fff;
  font-size: 0.88rem;
  font-weight: 740;
  line-height: 1.25;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.fav-card-meta em {
  font-style: normal;
  color: rgba(255, 255, 255, 0.62);
  font-size: 0.72rem;
  font-weight: 600;
}

.favorites-empty {
  position: relative;
  text-align: center;
  padding: 3.2rem 1.2rem 2.5rem;
  margin-top: 0.5rem;
  border-radius: 1.35rem;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background: rgba(255, 255, 255, 0.025);
  overflow: hidden;
}

.favorites-empty[hidden] {
  display: none !important;
}

.favorites-empty-orb {
  position: absolute;
  width: 14rem;
  height: 14rem;
  left: 50%;
  top: -3rem;
  transform: translateX(-50%);
  border-radius: 50%;
  background: radial-gradient(circle, rgba(220, 53, 69, 0.22), transparent 70%);
  filter: blur(4px);
}

.favorites-empty > i {
  position: relative;
  display: inline-flex;
  font-size: 2.6rem;
  color: #ff8f6b;
  margin-bottom: 0.65rem;
  animation: favHeartFloat 2.8s ease-in-out infinite;
}

@keyframes favHeartFloat {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-6px); }
}

.favorites-empty h2 {
  position: relative;
  margin: 0 0 0.4rem;
  color: #fff;
  font-size: 1.35rem;
  font-weight: 780;
}

.favorites-empty p {
  position: relative;
  margin: 0 auto 1.15rem;
  max-width: 22rem;
  color: rgba(255, 255, 255, 0.55);
  font-size: 0.9rem;
  line-height: 1.45;
}

.favorites-empty-actions {
  position: relative;
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.55rem;
}

.favorites-empty-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2.55rem;
  padding: 0.55rem 1.1rem;
  border-radius: 999px;
  text-decoration: none;
  font-size: 0.88rem;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(135deg, #dc3545, #db6937);
  box-shadow: 0 8px 20px rgba(220, 53, 69, 0.28);
}

.favorites-empty-btn.is-ghost {
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.12);
  box-shadow: none;
  color: rgba(255, 255, 255, 0.88);
}

@media (max-width: 767.98px) {
  .favorites-hero {
    flex-direction: column;
    align-items: stretch;
  }

  .favorites-hero-aside {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }

  .favorites-sub {
    font-size: 0.84rem;
  }

  .favorites-title {
    font-size: 1.85rem;
  }

  .fav-card-meta strong {
    font-size: 0.8rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .fav-card,
  .fav-card-poster,
  .fav-card-poster img,
  .favorites-empty > i {
    animation: none !important;
    transition: none !important;
  }
}
"""

RENDER_JS = r"""
  // Favorites page render
  function renderFavoritesPage() {
    var $grid = $('#favorites-grid');
    if (!$grid.length) return;
    var typeFilter = $('.favorites-tabs[data-id="fav-type"] .favorites-tab.active, [data-tabs][data-id="fav-type"] .tab.active').data('name') || 'all';
    var map = favStore();
    var allItems = Object.keys(map).map(function (k) { return map[k]; });
    allItems.sort(function (a, b) {
      return new Date(b.saved_at || 0) - new Date(a.saved_at || 0);
    });
    var total = allItems.length;
    $('#favorites-count-num').text(String(total));
    var items = allItems;
    if (typeFilter !== 'all') {
      items = items.filter(function (i) { return (i.type || i.mediaType) === typeFilter; });
    }
    if (!items.length) {
      $grid.empty();
      $('#favorites-empty').removeAttr('hidden');
      return;
    }
    $('#favorites-empty').attr('hidden', true);
    var imgBase = (window.APP && APP.imgBase) || 'https://image.tmdb.org/t/p';
    var html = items.map(function (it) {
      var type = it.type || it.mediaType || 'movie';
      var title = it.title || it.name || 'Untitled';
      var year = it.year || '';
      var poster = it.poster || '';
      if (!poster && it.poster_path) {
        poster = imgBase + '/w600_and_h900_bestv2' + it.poster_path;
      }
      if (!poster) {
        poster = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='450'%3E%3Crect width='100%25' height='100%25' fill='%23171a22'/%3E%3C/svg%3E";
      }
      var slug = String(title).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'item';
      var href = (window.APP && APP.baseUrl ? APP.baseUrl : '') + '/' + (type === 'tv' ? 'tv' : 'movie') + '/' + slug + '/' + it.id;
      var safeTitle = $('<div>').text(title).html();
      var typeLabel = type === 'tv' ? 'TV' : 'Movie';
      var metaLine = typeLabel + (year ? ' · ' + year : '');
      return '' +
        '<article class="fav-card" data-id="' + String(it.id) + '">' +
          '<a class="fav-card-poster" href="' + href + '" aria-label="' + safeTitle + '">' +
            '<span class="fav-card-badge">' + typeLabel + '</span>' +
            '<img src="' + poster + '" alt="" loading="lazy" decoding="async" width="300" height="450">' +
            '<span class="fav-card-meta">' +
              '<strong>' + safeTitle + '</strong>' +
              '<em>' + metaLine + '</em>' +
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
    if (confirm('Clear all favorites?')) {
      clearFavStorage();
      updateFavCounter();
      renderFavoritesPage();
    }
  });

  $(document).on('click', '.remove-fav', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var map = favStore();
    delete map[String($(this).data('id'))];
    saveFavs(map);
    updateFavCounter();
    renderFavoritesPage();
  });
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def main() -> None:
    page = ROOT / "app/Views/pages/favorites.php"
    page.write_text(PAGE)
    print("favorites.php rewritten")

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    marker = "/* ——— Favorites polish (ui68) ——— */"
    if marker in css:
        css = re.sub(
            r"/\* ——— Favorites polish \(ui68\) ——— \*/[\s\S]*?(?=\n/\* ———|\Z)",
            CSS.strip() + "\n\n",
            css,
            count=1,
        )
        print("favorites css refreshed")
    else:
        css = css.rstrip() + "\n" + CSS + "\n"
        print("favorites css added")
    css_path.write_text(css)

    js_path = ROOT / "public/assets/js/app.js"
    js = js_path.read_text()
    start = js.find("  // Favorites page render")
    if start < 0:
        raise SystemExit("favorites render block not found")
    # End at tipCache / next major section
    end_markers = [
        "\n  var tipCache = {};",
        "\n  function esc(s) {",
        "\n  // Soft navigation",
    ]
    end = -1
    for m in end_markers:
        i = js.find(m, start)
        if i > start:
            end = i
            break
    if end < 0:
        raise SystemExit("end of favorites handlers not found")
    js = js[:start] + RENDER_JS.rstrip() + "\n" + js[end:]
    # Avoid duplicate clear/remove handlers left elsewhere? replaced whole block.
    js_path.write_text(js)
    print("favorites js replaced")

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
