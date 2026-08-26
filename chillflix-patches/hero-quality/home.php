<main>
    <div class="swiper swiper-container" id="featured">
        <div class="hero-toplift" aria-hidden="true"></div>
        <div class="swiper-wrapper">
            <?php foreach ($featured as $i => $slide):
                $t = title_of($slide);
                $y = year_of(date_of($slide));
                $href = media_url('movie', (int) $slide['id'], $t);
                /* Base w1280 for LCP/mobile; data-bg-hi=w1920 upgraded on 2K/4K (see hero-quality.js) */
                $bg = img_url($slide['backdrop_path'] ?? $slide['poster_path'] ?? null, 'w1280');
                $bgHi = img_url($slide['backdrop_path'] ?? $slide['poster_path'] ?? null, 'w1920');
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
            <div class="swiper-slide<?= (int) $i === 0 ? ' lazyloaded' : ' lazyload' ?>" data-bg-hi="<?= e($bgHi) ?>"<?php if ((int) $i !== 0): ?> data-bgset="<?= e($bg) ?>"<?php endif; ?> style="<?= $heroStyle ?>">
                <?php if ((int) $i === 0 && $bg): ?>
                <!-- Real LCP candidate (same art/crop as CSS background). Decorative. -->
                <img
                    class="hero-lcp"
                    src="<?= e($bg) ?>"
                    alt=""
                    width="1920"
                    height="1080"
                    decoding="sync"
                    fetchpriority="high"
                    aria-hidden="true"
                >
                <?php endif; ?>
                <div class="wrapper">
                    <div class="info">
                        <?php if ($logo): ?>
                        <div class="hero-logo">
                            <img src="<?= e($logo) ?>" alt="<?= e($t) ?>" width="420" height="130" decoding="<?= (int) $i === 0 ? 'sync' : 'async' ?>">
                        </div>
                        <?php else: ?>
                        <div class="name"><?= e($t) ?></div>
                        <?php endif; ?>
                        <div class="meta">
                            <?php if ($rating): ?><span class="hero-chip"><i class="uil uil-star"></i> <?= e((string) $rating) ?></span><?php endif; ?>
                            <?php if ($y): ?><span class="hero-chip"><i class="uil uil-calender"></i> <?= e($y) ?></span><?php endif; ?>
                            <?php if ($runtimeLabel): ?><span class="hero-chip"><i class="uil uil-clock"></i> <?= e($runtimeLabel) ?></span><?php endif; ?>
                        </div>
                        <?php if ($isHot || $isNew): ?>
                        <div class="hero-badges">
                            <?php if ($isHot): ?>
                            <span class="hero-badge hero-badge-hot"><i class="uil uil-fire"></i><span><?= e(t('home.hot')) ?></span></span>
                            <?php endif; ?>
                            <?php if ($isNew): ?>
                            <span class="hero-badge hero-badge-new"><span class="hero-badge-new-label"><?= e(t('home.new')) ?></span><span data-i18n="home.newly_launched"><?= e(t('home.newly_launched')) ?></span></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="desc"><?= e($desc) ?></div>
                        <div class="action">
                            <div>
                                <a href="<?= e($href) ?>" class="btn btn-primary btn-lg hero-watch-btn">
                                    <i class="uil uil-play"></i><span><?= e(t('home.watch_now')) ?></span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="swiper-button-next" tabindex="0" role="button" aria-label="<?= e(t('home.next_slide')) ?>"></div>
        <div class="swiper-button-prev" tabindex="0" role="button" aria-label="<?= e(t('home.prev_slide')) ?>"></div>
        <div class="swiper-pagination"></div>
    </div>

    <div class="container">
        <div class="aside-wrapper">
            <aside class="main">
                <div class="section section-rail" id="continue-watching" hidden>
                    <div class="head">
                        <div class="start">
                            <h2 class="title" data-i18n="home.continue_watching"><?= e(t('home.continue_watching')) ?></h2>
                        </div>
                    </div>
                    <div class="media-rail-wrap">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="<?= e(t('home.scroll_left')) ?>"><i class="uil uil-angle-left"></i></button>
                        <div class="scaff movies items media-rail-items"></div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="<?= e(t('home.scroll_right')) ?>"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>


                <div class="section section-top10">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently top10-heading" data-i18n="home.top10_today"><?= e(t('home.top10_today')) ?></h2>
                            <div class="tabs" data-tabs data-id="top10" data-persist="true">
                                <span class="tab active" data-name="movies" data-i18n="home.movies"><?= e(t('home.movies')) ?></span>
                                <span class="tab" data-name="tv" data-i18n="home.tv_shows"><?= e(t('home.tv_shows')) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="tab-content top10-rail-wrap" data-name="movies">
                        <button type="button" class="top10-nav top10-nav-prev" aria-label="<?= e(t('home.scroll_left')) ?>"><i class="uil uil-angle-left"></i></button>
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
                        <button type="button" class="top10-nav top10-nav-next" aria-label="<?= e(t('home.scroll_right')) ?>"><i class="uil uil-angle-right"></i></button>
                    </div>
                    <div class="tab-content top10-rail-wrap" data-name="tv" style="display:none">
                        <button type="button" class="top10-nav top10-nav-prev" aria-label="<?= e(t('home.scroll_left')) ?>"><i class="uil uil-angle-left"></i></button>
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
                        <button type="button" class="top10-nav top10-nav-next" aria-label="<?= e(t('home.scroll_right')) ?>"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>

                <div class="section section-rail section-because" id="because-you-watched" hidden>
                    <div class="head">
                        <div class="start home-personalize-head">
                            <h2 class="title home-personalize-title">
                                <span class="home-personalize-prefix" data-i18n="home.because_you_watched"><?= e(t('home.because_you_watched')) ?></span>
                            </h2>
                            <div class="home-personalize-picker" data-picker="because">
                                <button type="button" class="home-personalize-trigger" id="because-title-trigger" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="home-personalize-trigger-label" id="because-title-label">…</span>
                                    <i class="uil uil-angle-down" aria-hidden="true"></i>
                                </button>
                                <div class="home-personalize-menu" id="because-title-menu" hidden role="listbox"></div>
                            </div>
                        </div>
                    </div>
                    <div class="media-rail-wrap">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="<?= e(t('home.scroll_left')) ?>"><i class="uil uil-angle-left"></i></button>
                        <div class="scaff movies items media-rail-items" id="because-you-watched-items"></div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="<?= e(t('home.scroll_right')) ?>"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>

                <div class="section section-rail section-only-on" id="only-on-network">
                    <div class="head">
                        <div class="start home-personalize-head home-personalize-head--network">
                            <h2 class="title home-personalize-title">
                                <span class="home-personalize-prefix" data-i18n="home.only_on"><?= e(t('home.only_on')) ?></span>
                            </h2>
                            <div class="home-personalize-picker home-personalize-picker--network" data-picker="network">
                                <button type="button" class="home-personalize-trigger home-personalize-trigger--network" id="network-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="network-menu" aria-label="<?= e(t('home.choose_network')) ?>">
                                    <span class="home-personalize-dot" id="network-dot" aria-hidden="true"></span>
                                    <span class="home-personalize-trigger-label" id="network-label">Netflix</span>
                                    <i class="uil uil-angle-down home-personalize-chevron" aria-hidden="true"></i>
                                </button>
                                <div class="home-personalize-menu home-personalize-menu--network" id="network-menu" hidden role="listbox" aria-labelledby="network-trigger"></div>
                            </div>
                        </div>
                    </div>
                    <div class="media-rail-wrap">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="<?= e(t('home.scroll_left')) ?>"><i class="uil uil-angle-left"></i></button>
                        <div class="scaff movies items media-rail-items" id="only-on-network-items"></div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="<?= e(t('home.scroll_right')) ?>"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>

                
                                                                <?php if (!empty($latestEpisodes)): ?>
                <div class="section section-rail section-episodes">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently" data-i18n="home.latest_episodes"><?= e(t('home.latest_episodes')) ?></h2>
                            <a class="more" href="<?= e(url('/tv-series')) ?>"><span data-i18n="home.view_all"><?= e(t('home.view_all')) ?></span> <i class="uil uil-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="media-rail-wrap">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="<?= e(t('home.scroll_left')) ?>"><i class="uil uil-angle-left"></i></button>
                        <div class="episode-rail-items media-rail-items">
                            <?php foreach ($latestEpisodes as $epItem) {
                                require __DIR__ . '/../partials/episode-card.php';
                            } ?>
                        </div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="<?= e(t('home.scroll_right')) ?>"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>
                <?php endif; ?>

                <div class="section section-rail section-episodes section-recommended">
                    <div class="head">
                        <div class="start">
                            <h1 class="title gardiently" data-i18n="home.recommended"><?= e(t('home.recommended')) ?></h1>
                            <div class="tabs" data-tabs data-id="recommended" data-persist="true">
                                <span class="tab active" data-name="movies" data-i18n="home.movies"><?= e(t('home.movies')) ?></span>
                                <span class="tab" data-name="tv" data-i18n="home.tv_shows"><?= e(t('home.tv_shows')) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="tab-content media-rail-wrap" data-name="movies">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="<?= e(t('home.scroll_left')) ?>"><i class="uil uil-angle-left"></i></button>
                        <div class="episode-rail-items media-rail-items">
                            <?php foreach ($recommendedMovies as $item) { $type = 'movie'; require __DIR__ . '/../partials/landscape-card.php'; } ?>
                        </div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="<?= e(t('home.scroll_right')) ?>"><i class="uil uil-angle-right"></i></button>
                    </div>
                    <div class="tab-content media-rail-wrap" data-name="tv" style="display:none">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="<?= e(t('home.scroll_left')) ?>"><i class="uil uil-angle-left"></i></button>
                        <div class="episode-rail-items media-rail-items">
                            <?php foreach ($recommendedTv as $item) { $type = 'tv'; require __DIR__ . '/../partials/landscape-card.php'; } ?>
                        </div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="<?= e(t('home.scroll_right')) ?>"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>

                <div class="section section-rail">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently" data-i18n="home.latest_movies"><?= e(t('home.latest_movies')) ?></h2>
                            <a class="more" href="<?= e(url('/movies')) ?>"><span data-i18n="home.view_all"><?= e(t('home.view_all')) ?></span> <i class="uil uil-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="media-rail-wrap">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="<?= e(t('home.scroll_left')) ?>"><i class="uil uil-angle-left"></i></button>
                        <div class="scaff movies items media-rail-items">
                            <?php foreach ($latestMovies as $item) { $type = 'movie'; $rank = null; require __DIR__ . '/../partials/movie-card.php'; } ?>
                        </div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="<?= e(t('home.scroll_right')) ?>"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>

                <div class="section section-rail">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently" data-i18n="home.latest_tv"><?= e(t('home.latest_tv')) ?></h2>
                            <a class="more" href="<?= e(url('/tv-series')) ?>"><span data-i18n="home.view_all"><?= e(t('home.view_all')) ?></span> <i class="uil uil-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="media-rail-wrap">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="<?= e(t('home.scroll_left')) ?>"><i class="uil uil-angle-left"></i></button>
                        <div class="scaff movies items media-rail-items">
                            <?php foreach ($latestTv as $item) { $type = 'tv'; $rank = null; require __DIR__ . '/../partials/movie-card.php'; } ?>
                        </div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="<?= e(t('home.scroll_right')) ?>"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>
            </aside>

            <aside class="sidebar home-sidebar">
                <div class="section">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently" data-i18n="home.most_commented"><?= e(t('home.most_commented')) ?></h2>
                            <div class="tabs" data-tabs data-id="most-commented" data-persist="true">
                                <span class="tab active" data-name="day" data-i18n="home.day"><?= e(t('home.day')) ?></span>
                                <span class="tab" data-name="week" data-i18n="home.week"><?= e(t('home.week')) ?></span>
                                <span class="tab" data-name="month" data-i18n="home.month"><?= e(t('home.month')) ?></span>
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
                                <div class="text-center text-muted p-3"><?= e(t('home.no_commented')) ?></div>
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
                            <h2 class="title gardiently" data-i18n="home.recently_added"><?= e(t('home.recently_added')) ?></h2>
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
