<main class="page-pad-top favorites-page">
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
