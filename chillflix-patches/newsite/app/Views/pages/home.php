<main>
    <div class="swiper swiper-container" id="featured">
        <div class="swiper-wrapper">
            <?php foreach ($featured as $slide):
                $t = title_of($slide);
                $y = year_of(date_of($slide));
                $href = media_url('movie', (int) $slide['id'], $t);
                $bg = img_url($slide['backdrop_path'] ?? $slide['poster_path'] ?? null, 'original');
                $rating = isset($slide['vote_average']) ? round((float) $slide['vote_average'], 1) : null;
                $desc = truncate((string) ($slide['overview'] ?? ''), 180);
            ?>
            <div class="swiper-slide lazyload" data-bgset="<?= e($bg) ?>" style="background-color:#14171f;">
                <div class="wrapper justify-content-center">
                    <div class="info">
                        <div class="name"><?= e($t) ?></div>
                        <div class="meta">
                            <span class="rating status-icon">Movie</span>
                            <?php if ($rating): ?><span><i class="uil uil-star"></i> <?= e((string) $rating) ?></span><?php endif; ?>
                            <?php if ($y): ?><span><i class="uil uil-calender"></i> <?= e($y) ?></span><?php endif; ?>
                        </div>
                        <div class="desc"><?= e($desc) ?></div>
                        <div class="action">
                            <div>
                                <a href="<?= e($href) ?>" class="btn btn-primary btn-lg rounded-pill px-4">
                                    Watch Now <i class="uil uil-play"></i>
                                </a>
                            </div>
                            <div>
                                <button type="button" class="btn px-4 rounded-pill btn-lg user-bookmark-toggle"
                                    data-id="<?= (int) $slide['id'] ?>" data-media-type="movie"
                                    data-title="<?= e($t) ?>" data-poster="<?= e(img_url($slide['poster_path'] ?? null, 'w185')) ?>"
                                    data-year="<?= e($y) ?>">
                                    <i class="uil uil-plus-circle"></i> Favorite
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="swiper-button-next" tabindex="0" role="button" aria-label="Next slide"></div>
        <div class="swiper-button-prev" tabindex="0" role="button" aria-label="Previous slide"></div>
        <div class="swiper-pagination"></div>
    </div>

    <div class="container">
        <div class="aside-wrapper">
            <aside class="main">
                <div class="section section-top10">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently top10-heading">TOP 10 Today</h2>
                            <div class="tabs" data-tabs data-id="top10" data-persist="true">
                                <span class="tab active" data-name="movies">Movies</span>
                                <span class="tab" data-name="tv">TV Shows</span>
                            </div>
                        </div>
                    </div>
                    <div class="tab-content top10-rail-wrap" data-name="movies">
                        <button type="button" class="top10-nav top10-nav-prev" aria-label="Scroll left"><i class="uil uil-angle-left"></i></button>
                        <div class="scaff movies items top10-items">
                            <?php
                            $rank = 0;
                            foreach ($top10Movies as $item) {
                                $rank++;
                                $type = 'movie';
                                require __DIR__ . '/../partials/movie-card.php';
                            }
                            unset($rank);
                            ?>
                        </div>
                        <button type="button" class="top10-nav top10-nav-next" aria-label="Scroll right"><i class="uil uil-angle-right"></i></button>
                    </div>
                    <div class="tab-content top10-rail-wrap" data-name="tv" style="display:none">
                        <button type="button" class="top10-nav top10-nav-prev" aria-label="Scroll left"><i class="uil uil-angle-left"></i></button>
                        <div class="scaff movies items top10-items">
                            <?php
                            $rank = 0;
                            foreach ($top10Tv as $item) {
                                $rank++;
                                $type = 'tv';
                                require __DIR__ . '/../partials/movie-card.php';
                            }
                            unset($rank);
                            ?>
                        </div>
                        <button type="button" class="top10-nav top10-nav-next" aria-label="Scroll right"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>

                <div class="section">
                    <div class="head">
                        <div class="start">
                            <h1 class="title gardiently">Recommended</h1>
                            <div class="tabs" data-tabs data-id="recommended" data-persist="true">
                                <span class="tab active" data-name="movies">Movies</span>
                                <span class="tab" data-name="tv">TV Shows</span>
                            </div>
                        </div>
                    </div>
                    <div class="tab-content" data-name="movies">
                        <div class="scaff movies items">
                            <?php foreach ($recommendedMovies as $item) { $type = 'movie'; $rank = null; require __DIR__ . '/../partials/movie-card.php'; } ?>
                        </div>
                    </div>
                    <div class="tab-content" data-name="tv" style="display:none">
                        <div class="scaff movies items">
                            <?php foreach ($recommendedTv as $item) { $type = 'tv'; $rank = null; require __DIR__ . '/../partials/movie-card.php'; } ?>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Latest Movies</h2>
                            <a class="more" href="<?= e(url('/movies')) ?>"><span>View All</span> <i class="uil uil-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="scaff movies items">
                        <?php foreach ($latestMovies as $item) { $type = 'movie'; $rank = null; require __DIR__ . '/../partials/movie-card.php'; } ?>
                    </div>
                </div>

                <div class="section">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Latest TV Shows</h2>
                            <a class="more" href="<?= e(url('/tv-series')) ?>"><span>View All</span> <i class="uil uil-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="scaff movies items">
                        <?php foreach ($latestTv as $item) { $type = 'tv'; $rank = null; require __DIR__ . '/../partials/movie-card.php'; } ?>
                    </div>
                </div>
            </aside>

            <aside class="sidebar">
                <div class="section">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Most Commented</h2>
                            <div class="tabs" data-tabs>
                                <span class="tab active" data-name="day">Day</span>
                                <span class="tab" data-name="week">Week</span>
                                <span class="tab" data-name="month">Month</span>
                            </div>
                        </div>
                    </div>
                    <div class="body">
                        <div class="tab-content scaff movie-sidebar items" data-name="day" style="display:block">
                            <?php
                            $shown = 0;
                            foreach ($mostCommented as $item) {
                                if (($item['media_type'] ?? '') === 'person') continue;
                                $type = (($item['media_type'] ?? 'movie') === 'tv') ? 'tv' : 'movie';
                                require __DIR__ . '/../partials/sidebar-item.php';
                                if (++$shown >= 10) break;
                            }
                            if ($shown === 0): ?>
                                <div class="text-center text-muted p-3">No commented content for this period</div>
                            <?php endif; ?>
                        </div>
                        <div class="tab-content scaff movie-sidebar items" data-name="week" style="display:none">
                            <?php
                            $shown = 0;
                            foreach ($recentlyUpdated as $item) {
                                if (($item['media_type'] ?? '') === 'person') continue;
                                $type = (($item['media_type'] ?? 'movie') === 'tv') ? 'tv' : 'movie';
                                require __DIR__ . '/../partials/sidebar-item.php';
                                if (++$shown >= 10) break;
                            }
                            ?>
                        </div>
                        <div class="tab-content scaff movie-sidebar items" data-name="month" style="display:none">
                            <?php
                            $shown = 0;
                            foreach (array_slice($latestMovies, 0, 10) as $item) {
                                $type = 'movie';
                                require __DIR__ . '/../partials/sidebar-item.php';
                                $shown++;
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Recently Updated</h2>
                        </div>
                        <div class="end">
                            <div class="btn-group pagination-control sidebar-slider-control" role="group">
                                <button type="button" id="recently-updated-prev" class="btn btn-sm btn-primary" aria-label="Scroll up" disabled>
                                    <i class="uil uil-angle-up"></i>
                                </button>
                                <button type="button" id="recently-updated-next" class="btn btn-sm btn-primary" aria-label="Scroll down">
                                    <i class="uil uil-angle-down"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="body recently-updated-scrollable">
                        <div class="scaff movie-sidebar items" id="recently-updated-list" data-template="sidebar">
                            <?php
                            foreach ($recentlyUpdated as $item) {
                                if (($item['media_type'] ?? '') === 'person') continue;
                                $type = (($item['media_type'] ?? 'movie') === 'tv') ? 'tv' : 'movie';
                                require __DIR__ . '/../partials/sidebar-item.php';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>
