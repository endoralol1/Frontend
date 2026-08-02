<main class="page-pad-top favorites-page">
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
