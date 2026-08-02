<main class="page-pad-top favorites-page">
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
