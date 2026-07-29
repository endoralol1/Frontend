<?php
$path = current_path();
$navHome = is_active('/home') || $path === '/' || $path === '';
$navMovies = is_active('/movies') || (bool) preg_match('#^/movie(/|$)#', $path);
$navTv = is_active('/tv-series') || (bool) preg_match('#^/tv(/|$)#', $path);
?>
<nav class="bottom-nav" aria-label="Primary">
    <div class="bottom-nav-inner">
        <a href="<?= e(url('/home')) ?>" class="bottom-nav-item<?= $navHome ? ' active' : '' ?>"<?= $navHome ? ' aria-current="page"' : '' ?>>
            <span class="bottom-nav-icon"><i class="uil uil-estate" aria-hidden="true"></i></span>
            <span class="bottom-nav-label">Home</span>
        </a>
        <a href="<?= e(url('/movies')) ?>" class="bottom-nav-item<?= $navMovies ? ' active' : '' ?>"<?= $navMovies ? ' aria-current="page"' : '' ?>>
            <span class="bottom-nav-icon"><i class="uil uil-clapper-board" aria-hidden="true"></i></span>
            <span class="bottom-nav-label">Movies</span>
        </a>
        <a href="<?= e(url('/tv-series')) ?>" class="bottom-nav-item<?= $navTv ? ' active' : '' ?>"<?= $navTv ? ' aria-current="page"' : '' ?>>
            <span class="bottom-nav-icon"><i class="uil uil-tv-retro" aria-hidden="true"></i></span>
            <span class="bottom-nav-label">TV</span>
        </a>
        <button type="button" class="bottom-nav-item bottom-nav-browse" aria-haspopup="dialog" aria-controls="browse-sheet" aria-expanded="false">
            <span class="bottom-nav-icon"><i class="uil uil-apps" aria-hidden="true"></i></span>
            <span class="bottom-nav-label">Browse</span>
        </button>
    </div>
</nav>

<div class="browse-sheet" id="browse-sheet" hidden>
    <button type="button" class="browse-sheet-backdrop" aria-label="Close browse"></button>
    <div class="browse-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="browse-sheet-title">
        <div class="browse-sheet-handle" aria-hidden="true"></div>
        <div class="browse-sheet-head">
            <h2 id="browse-sheet-title">Browse</h2>
            <button type="button" class="browse-sheet-close" aria-label="Close"><i class="uil uil-times"></i></button>
        </div>

        <div class="browse-sheet-body">
            <section class="browse-section">
                <h3 class="browse-section-title">Content</h3>
                <div class="browse-grid browse-grid-3">
                    <a class="browse-tile" href="<?= e(url('/movies')) ?>">
                        <span class="browse-tile-icon tone-red"><i class="uil uil-clapper-board"></i></span>
                        <span class="browse-tile-label">Movies</span>
                    </a>
                    <a class="browse-tile" href="<?= e(url('/tv-series')) ?>">
                        <span class="browse-tile-icon tone-red"><i class="uil uil-tv-retro"></i></span>
                        <span class="browse-tile-label">TV Shows</span>
                    </a>
                    <a class="browse-tile" href="<?= e(url('/filters')) ?>">
                        <span class="browse-tile-icon tone-orange"><i class="uil uil-star"></i></span>
                        <span class="browse-tile-label">Anime</span>
                        <span class="browse-tile-badge">Mock</span>
                    </a>
                </div>
            </section>

            <section class="browse-section">
                <h3 class="browse-section-title">Features</h3>
                <div class="browse-grid browse-grid-3">
                    <button type="button" class="browse-tile" data-browse-mock="channels">
                        <span class="browse-tile-icon tone-blue"><i class="uil uil-rss-alt"></i></span>
                        <span class="browse-tile-label">Channels</span>
                        <span class="browse-tile-badge">Soon</span>
                    </button>
                    <button type="button" class="browse-tile" data-browse-mock="4k">
                        <span class="browse-tile-icon tone-purple"><i class="uil uil-monitor"></i></span>
                        <span class="browse-tile-label">4K</span>
                        <span class="browse-tile-badge">Soon</span>
                    </button>
                    <button type="button" class="browse-tile" data-browse-mock="watch-party">
                        <span class="browse-tile-icon tone-yellow"><i class="uil uil-users-alt"></i></span>
                        <span class="browse-tile-label">Watch Party</span>
                        <span class="browse-tile-badge">Soon</span>
                    </button>
                </div>
            </section>

            <section class="browse-section">
                <h3 class="browse-section-title">Personal</h3>
                <div class="browse-grid browse-grid-2">
                    <a class="browse-card" href="<?= e(url('/favorites')) ?>">
                        <span class="browse-card-icon"><i class="uil uil-heart"></i></span>
                        <span class="browse-card-copy">
                            <strong>Watchlist</strong>
                            <em>Your saved titles</em>
                        </span>
                    </a>
                    <button type="button" class="browse-card" data-browse-mock="history">
                        <span class="browse-card-icon"><i class="uil uil-history"></i></span>
                        <span class="browse-card-copy">
                            <strong>History</strong>
                            <em>Mock for now</em>
                        </span>
                    </button>
                </div>
            </section>

            <section class="browse-section">
                <h3 class="browse-section-title">Quick links</h3>
                <div class="browse-list">
                    <a class="browse-list-item" href="<?= e(url('/top-imdb')) ?>">
                        <i class="uil uil-trophy"></i>
                        <span>Top IMDB</span>
                        <i class="uil uil-angle-right"></i>
                    </a>
                    <a class="browse-list-item" href="<?= e(url('/filters')) ?>">
                        <i class="uil uil-filter"></i>
                        <span>Filters</span>
                        <i class="uil uil-angle-right"></i>
                    </a>
                    <a class="browse-list-item" href="<?= e(url('/request')) ?>">
                        <i class="uil uil-plus-circle"></i>
                        <span>Request</span>
                        <i class="uil uil-angle-right"></i>
                    </a>
                    <button type="button" class="browse-list-item" data-browse-mock="genres">
                        <i class="uil uil-layers"></i>
                        <span>Genres hub</span>
                        <em class="browse-mock-pill">Mock</em>
                        <i class="uil uil-angle-right"></i>
                    </button>
                </div>
            </section>

            <div class="browse-ads-row" role="group" aria-label="Ads status mock">
                <i class="uil uil-sliders-v-alt"></i>
                <span>Ads status</span>
                <button type="button" class="browse-ads-toggle is-on" data-browse-ads aria-pressed="true">ON</button>
            </div>
        </div>
    </div>
</div>
