<main>
    <div class="swiper swiper-container" id="featured">
        <div class="hero-toplift" aria-hidden="true"></div>
        <div class="swiper-wrapper">
            <?php foreach ($featured as $i => $slide):
                $t = title_of($slide);
                $y = year_of(date_of($slide));
                $href = media_url('movie', (int) $slide['id'], $t);
                /* w1280 looks sharp full-bleed; original (2K–4K) was a major decode cost */
                $bg = img_url($slide['backdrop_path'] ?? $slide['poster_path'] ?? null, 'w1280');
                $rating = isset($slide['vote_average']) ? round((float) $slide['vote_average'], 1) : null;
                $desc = truncate((string) ($slide['overview'] ?? ''), 160);
                $logoPath = $slide['logo_path'] ?? null;
                $logo = $logoPath ? img_url($logoPath, 'w500') : null;
                $runtimeMins = (int) ($slide['runtime'] ?? 0);
                $runtimeLabel = '';
                if ($runtimeMins > 0) {
                    $rh = intdiv($runtimeMins, 60);
                    $rm = $runtimeMins % 60;
                    $runtimeLabel = $rh > 0 ? ($rh . 'h ' . str_pad((string) $rm, 2, '0', STR_PAD_LEFT) . 'm') : ($rm . 'm');
                }
                $rank = (int) ($slide['trending_rank'] ?? ((int) $i + 1));
                $isHot = !empty($slide['is_hot']);
                $isNew = !empty($slide['is_newly_launched']);
                $heroStyle = 'background-color:#0a0c12;';
                if ((int) $i === 0) {
                    $heroStyle .= 'background-image:url(' . e($bg) . ');';
                }
            ?>
            <div class="swiper-slide<?= (int) $i === 0 ? ' lazyloaded' : ' lazyload' ?>"<?php if ((int) $i !== 0): ?> data-bgset="<?= e($bg) ?>"<?php endif; ?> style="<?= $heroStyle ?>">
                <div class="wrapper">
                    <div class="info">
                        <?php if ($logo): ?>
                        <div class="hero-logo">
                            <img src="<?= e($logo) ?>" alt="<?= e($t) ?>" width="420" height="130" decoding="<?= (int) $i === 0 ? 'sync' : 'async' ?>"<?= (int) $i === 0 ? ' fetchpriority="high"' : '' ?>>
                        </div>
                        <?php else: ?>
                        <div class="name"><?= e($t) ?></div>
                        <?php endif; ?>
                        <div class="meta">
                            <?php if ($rating): ?><span class="hero-chip"><i class="uil uil-star"></i> <?= e((string) $rating) ?></span><?php endif; ?>
                            <?php if ($y): ?><span class="hero-chip"><i class="uil uil-calender"></i> <?= e($y) ?></span><?php endif; ?>
                            <?php if ($runtimeLabel): ?><span class="hero-chip"><i class="uil uil-clock"></i> <?= e($runtimeLabel) ?></span><?php endif; ?>
                        </div>
                        <?php if (($rank > 0 && $rank <= 10) || $isHot || $isNew): ?>
                        <div class="hero-badges">
                            <?php if ($rank > 0 && $rank <= 10): ?>
                            <span class="hero-badge hero-badge-today"><span class="hero-badge-rank">#<?= (int) $rank ?></span><span>Today</span></span>
                            <?php endif; ?>
                            <?php if ($isHot): ?>
                            <span class="hero-badge hero-badge-hot"><i class="uil uil-fire"></i><span>HOT</span></span>
                            <?php endif; ?>
                            <?php if ($isNew): ?>
                            <span class="hero-badge hero-badge-new"><span class="hero-badge-new-label">NEW</span><span>Newly launched</span></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="desc"><?= e($desc) ?></div>
                        <div class="action">
                            <div>
                                <a href="<?= e($href) ?>" class="btn btn-primary btn-lg hero-watch-btn">
                                    <i class="uil uil-play"></i><span>Watch Now</span>
                                </a>
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

                <?php if (!empty($latestEpisodes)): ?>
                <div class="section section-rail section-episodes">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Latest Episodes</h2>
                            <a class="more" href="<?= e(url('/tv-series')) ?>"><span>View All</span> <i class="uil uil-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="media-rail-wrap">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="Scroll left"><i class="uil uil-angle-left"></i></button>
                        <div class="episode-rail-items media-rail-items">
                            <?php foreach ($latestEpisodes as $epItem) {
                                require __DIR__ . '/../partials/episode-card.php';
                            } ?>
                        </div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="Scroll right"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>
                <?php endif; ?>

                <div class="section section-rail section-episodes section-recommended">
                    <div class="head">
                        <div class="start">
                            <h1 class="title gardiently">Recommended</h1>
                            <div class="tabs" data-tabs data-id="recommended" data-persist="true">
                                <span class="tab active" data-name="movies">Movies</span>
                                <span class="tab" data-name="tv">TV Shows</span>
                            </div>
                        </div>
                    </div>
                    <div class="tab-content media-rail-wrap" data-name="movies">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="Scroll left"><i class="uil uil-angle-left"></i></button>
                        <div class="episode-rail-items media-rail-items">
                            <?php foreach ($recommendedMovies as $item) { $type = 'movie'; require __DIR__ . '/../partials/landscape-card.php'; } ?>
                        </div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="Scroll right"><i class="uil uil-angle-right"></i></button>
                    </div>
                    <div class="tab-content media-rail-wrap" data-name="tv" style="display:none">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="Scroll left"><i class="uil uil-angle-left"></i></button>
                        <div class="episode-rail-items media-rail-items">
                            <?php foreach ($recommendedTv as $item) { $type = 'tv'; require __DIR__ . '/../partials/landscape-card.php'; } ?>
                        </div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="Scroll right"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>

                <div class="section section-rail">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Latest Movies</h2>
                            <a class="more" href="<?= e(url('/movies')) ?>"><span>View All</span> <i class="uil uil-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="media-rail-wrap">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="Scroll left"><i class="uil uil-angle-left"></i></button>
                        <div class="scaff movies items media-rail-items">
                            <?php foreach ($latestMovies as $item) { $type = 'movie'; $rank = null; require __DIR__ . '/../partials/movie-card.php'; } ?>
                        </div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="Scroll right"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>

                <div class="section section-rail">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Latest TV Shows</h2>
                            <a class="more" href="<?= e(url('/tv-series')) ?>"><span>View All</span> <i class="uil uil-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="media-rail-wrap">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="Scroll left"><i class="uil uil-angle-left"></i></button>
                        <div class="scaff movies items media-rail-items">
                            <?php foreach ($latestTv as $item) { $type = 'tv'; $rank = null; require __DIR__ . '/../partials/movie-card.php'; } ?>
                        </div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="Scroll right"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>
            </aside>

            <aside class="sidebar home-sidebar">
                <div class="section">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Most Commented</h2>
                            <div class="tabs" data-tabs data-id="most-commented" data-persist="true">
                                <span class="tab active" data-name="day">Day</span>
                                <span class="tab" data-name="week">Week</span>
                                <span class="tab" data-name="month">Month</span>
                            </div>
                        </div>
                    </div>
                    <div class="body">
                        <?php
                        $commentPeriods = [
                            'day' => $mostCommentedDay ?? $mostCommented ?? [],
                            'week' => $mostCommentedWeek ?? $recentlyUpdated ?? [],
                            'month' => $mostCommentedMonth ?? [],
                        ];
                        $periodFirst = true;
                        foreach ($commentPeriods as $periodName => $periodItems):
                        ?>
                        <div class="tab-content scaff movie-sidebar items" data-name="<?= e($periodName) ?>" style="display:<?= $periodFirst ? 'flex' : 'none' ?>">
                            <?php
                            $sideRank = 0;
                            foreach ($periodItems as $item) {
                                if (($item['media_type'] ?? '') === 'person') continue;
                                $type = (($item['media_type'] ?? 'movie') === 'tv') ? 'tv' : 'movie';
                                $sideRank++;
                                require __DIR__ . '/../partials/sidebar-item.php';
                                if ($sideRank >= 5) break;
                            }
                            if ($sideRank === 0): ?>
                                <div class="text-center text-muted p-3">No commented content for this period</div>
                            <?php endif;
                            unset($sideRank);
                            ?>
                        </div>
                        <?php
                        $periodFirst = false;
                        endforeach;
                        unset($commentPeriods, $periodFirst, $periodName, $periodItems);
                        ?>
                    </div>
                </div>

                <div class="section">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Recently Added</h2>
                        </div>
                    </div>
                    <div class="body">
                        <div class="scaff movie-sidebar items">
                            <?php
                            $sideRank = 0;
                            $recentSource = !empty($latestMovies) ? $latestMovies : ($recentlyUpdated ?? []);
                            foreach ($recentSource as $item) {
                                if (($item['media_type'] ?? '') === 'person') continue;
                                $type = isset($item['title']) ? 'movie' : ((($item['media_type'] ?? 'movie') === 'tv') ? 'tv' : 'movie');
                                if (isset($item['media_type']) && in_array($item['media_type'], ['movie', 'tv'], true)) {
                                    $type = $item['media_type'];
                                }
                                $sideRank++;
                                require __DIR__ . '/../partials/sidebar-item.php';
                                if ($sideRank >= 5) break;
                            }
                            unset($sideRank);
                            ?>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>
