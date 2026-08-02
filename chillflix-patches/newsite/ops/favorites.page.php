<main class="page-pad-top favorites-page">
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
