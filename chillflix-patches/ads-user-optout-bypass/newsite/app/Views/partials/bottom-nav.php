<?php
$site = (string) config('site_name');

$path = current_path();
$searchGenresMovie = config('genres_movie') ?: [];
$searchGenresTv = config('genres_tv') ?: [];
$navHome = is_active('/home') || $path === '/' || $path === '';
$navMovies = is_active('/movies') || (bool) preg_match('#^/movie(/|$)#', $path);
$navTv = is_active('/tv-series') || (bool) preg_match('#^/tv(/|$)#', $path);
?>
<div id="search-dock-recent" class="search-dock-recent" hidden>
    <div class="search-dock-recent-inner">
        <div class="search-dock-recent-list" id="search-dock-recent-list"></div>
        <button type="button" class="search-dock-recent-clear" id="search-dock-recent-clear" hidden>Clear</button>
    </div>
</div>
<nav class="bottom-nav" aria-label="<?= e(t('aria.primary')) ?>">
    <div class="bottom-nav-inner">
        <a href="<?= e(url('/home')) ?>" class="bottom-nav-item<?= $navHome ? ' active' : '' ?>"<?= $navHome ? ' aria-current="page"' : '' ?>>
            <span class="bottom-nav-icon"><i class="uil uil-estate" aria-hidden="true"></i></span>
            <span class="bottom-nav-label"><?= e(t('nav.home')) ?></span>
        </a>
        <a href="<?= e(url('/movies')) ?>" class="bottom-nav-item<?= $navMovies ? ' active' : '' ?>"<?= $navMovies ? ' aria-current="page"' : '' ?>>
            <span class="bottom-nav-icon"><i class="uil uil-clapper-board" aria-hidden="true"></i></span>
            <span class="bottom-nav-label"><?= e(t('nav.movies')) ?></span>
        </a>
        <a href="<?= e(url('/tv-series')) ?>" class="bottom-nav-item<?= $navTv ? ' active' : '' ?>"<?= $navTv ? ' aria-current="page"' : '' ?>>
            <span class="bottom-nav-icon"><i class="uil uil-tv-retro" aria-hidden="true"></i></span>
            <span class="bottom-nav-label"><?= e(t('nav.tv')) ?></span>
        </a>
        <button type="button" class="bottom-nav-item bottom-nav-search" aria-haspopup="dialog" aria-controls="search-sheet" aria-expanded="false">
            <span class="bottom-nav-icon"><i class="uil uil-search" aria-hidden="true"></i></span>
            <span class="bottom-nav-label" data-i18n="nav.search"><?= e(t('nav.search')) ?></span>
        </button>
        <button type="button" class="bottom-nav-item bottom-nav-browse" aria-haspopup="dialog" aria-controls="browse-sheet" aria-expanded="false">
            <span class="bottom-nav-icon"><i class="uil uil-apps" aria-hidden="true"></i></span>
            <span class="bottom-nav-label" data-i18n="nav.browse"><?= e(t('nav.browse')) ?></span>
        </button>

        <form class="bottom-nav-search-bar search-sheet-form" id="search-sheet-form" action="<?= e(url('/search')) ?>" method="get" autocomplete="off" role="search" hidden>
            <h2 id="search-sheet-title" class="search-sheet-sr-only"><?= e(t('nav.search')) ?></h2>
            <div class="search-sheet-field">
                <i class="uil uil-search" aria-hidden="true"></i>
                <input id="search-sheet-input" type="search" name="keyword" placeholder="<?= e(t('search.placeholder_short')) ?>" aria-label="<?= e(t('search.placeholder')) ?>" enterkeyhint="search">
                <button type="button" class="filters-open-btn search-sheet-filter" id="search-filters-open" aria-haspopup="dialog" aria-controls="search-filters-sheet" aria-expanded="false" title="<?= e(t('nav.filters')) ?>">
                    <i class="uil uil-filter" aria-hidden="true"></i>
                    <span class="search-sheet-filter-label" data-i18n="nav.filters"><?= e(t('nav.filters')) ?></span>
                    <span class="filters-count" id="search-filters-count" hidden>0</span>
                </button>
                <button type="button" class="search-sheet-close" aria-label="<?= e(t('search.close')) ?>"><i class="uil uil-times"></i></button>
            </div>
            <input type="hidden" name="type" id="sf-type" value="all">
            <input type="hidden" name="with_genres" id="sf-genres" value="">
            <input type="hidden" name="year_from" id="sf-year-from" value="">
            <input type="hidden" name="year_to" id="sf-year-to" value="">
            <input type="hidden" name="rating_from" id="sf-rating" value="">
            <input type="hidden" name="rating_to" id="sf-rating-to" value="">
        </form>
    </div>
</nav>

<div class="search-sheet" id="search-sheet" hidden>
    <button type="button" class="search-sheet-backdrop" aria-label="<?= e(t('search.close')) ?>"></button>
    <div class="search-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="search-sheet-title">
        <div class="search-sheet-idle" id="search-sheet-idle">
            <div class="search-filters-toolbar">
                <div class="filters-chips" id="search-filters-chips" aria-label="<?= e(t('a11y.active_filters')) ?>"></div>
                <button type="button" class="filters-reset-link" id="search-filters-reset" hidden><?= e(t('discover.reset')) ?></button>
            </div>

            <section class="search-sheet-section" aria-label="<?= e(t('search.recent')) ?>">
                <div class="search-sheet-section-head">
                    <h3><?= e(t('search.recent')) ?></h3>
                    <button type="button" class="search-recent-clear" id="search-recent-clear" hidden><?= e(t('browse.clear')) ?></button>
                </div>
                <div class="search-recent-list" id="search-recent-list"></div>
                <p class="search-recent-empty" id="search-recent-empty"><?= e(t('search.no_recent')) ?></p>
            </section>
        </div>

        <div class="search-sheet-suggest search-suggest" role="listbox" aria-label="<?= e(t('a11y.search_suggestions')) ?>"></div>
    </div>
</div>

<div class="filters-sheet" id="search-filters-sheet" hidden>
    <button type="button" class="filters-sheet-backdrop" aria-label="<?= e(t('a11y.close_filters')) ?>"></button>
    <div class="filters-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="search-filters-sheet-title">
        <div class="filters-sheet-handle" aria-hidden="true"></div>
        <div class="filters-sheet-head">
            <div>
                <p class="filters-sheet-kicker"><?= e(t('search.refine')) ?></p>
                <h2 id="search-filters-sheet-title"><?= e(t('nav.filters')) ?></h2>
            </div>
            <button type="button" class="filters-sheet-close" aria-label="<?= e(t('a11y.close_filters')) ?>"><i class="uil uil-times"></i></button>
        </div>

        <div class="filters-sheet-body">
            <div class="filter-home" id="search-filter-home">
                <div class="filter-rows">
                    <div class="filter-row-block">
                        <div class="filter-row-card">
                            <button type="button" class="filter-row" data-open-picker="sf-picker-type">
                                <span class="filter-row-icon"><i class="uil uil-clapper-board" aria-hidden="true"></i></span>
                                <span class="filter-row-copy">
                                    <strong><?= e(t('discover.type')) ?></strong>
                                    <em class="filter-row-hint" id="sf-type-hint"><?= e(t('common.all')) ?></em>
                                </span>
                                <i class="uil uil-angle-right filter-row-chevron" aria-hidden="true"></i>
                            </button>
                            <div class="filter-bubbles" id="sf-type-bubbles" data-summary-for="sf-picker-type" hidden></div>
                        </div>
                    </div>

                    <div class="filter-row-block">
                        <div class="filter-row-card">
                            <button type="button" class="filter-row" data-open-picker="sf-picker-genre">
                                <span class="filter-row-icon"><i class="uil uil-apps" aria-hidden="true"></i></span>
                                <span class="filter-row-copy">
                                    <strong><?= e(t('discover.genre')) ?></strong>
                                    <em class="filter-row-hint" id="sf-genre-hint"><?= e(t('discover.add_genres')) ?></em>
                                </span>
                                <i class="uil uil-angle-right filter-row-chevron" aria-hidden="true"></i>
                            </button>
                            <div class="filter-bubbles" id="sf-genre-bubbles" data-summary-for="sf-picker-genre" hidden></div>
                        </div>
                    </div>
                </div>

                <section class="filter-section filter-section-inline">
                    <h3 class="filter-section-title"><?= e(t('discover.year')) ?></h3>
                    <div class="range-timeline year-timeline" id="sf-year-timeline" data-min="1970" data-max="2026" data-step="1">
                        <div class="range-timeline-values">
                            <strong id="sf-year-from-label">1970</strong>
                            <span><?= e(t('discover.to')) ?></span>
                            <strong id="sf-year-to-label">2026</strong>
                        </div>
                        <div class="range-timeline-track">
                            <div class="range-timeline-rail" aria-hidden="true"></div>
                            <div class="range-timeline-fill" id="sf-year-range" aria-hidden="true"></div>
                            <input type="range" class="range-timeline-thumb range-timeline-thumb-from" id="sf-year-from-range" min="1970" max="2026" value="1970" step="1" aria-label="<?= e(t('a11y.from_year')) ?>">
                            <input type="range" class="range-timeline-thumb range-timeline-thumb-to" id="sf-year-to-range" min="1970" max="2026" value="2026" step="1" aria-label="<?= e(t('a11y.to_year')) ?>">
                        </div>
                        <div class="range-timeline-ticks" aria-hidden="true">
                            <?php for ($y = 1970; $y <= 2026; $y += 10): ?>
                            <span style="left: <?= (($y - 1970) / max(1, 2026 - 1970)) * 100 ?>%"><?= $y ?></span>
                            <?php endfor; ?>
                        </div>
                    </div>
                </section>

                <section class="filter-section filter-section-inline">
                    <h3 class="filter-section-title"><?= e(t('discover.rating')) ?></h3>
                    <div class="range-timeline rating-timeline" id="sf-rating-timeline" data-min="0" data-max="10" data-step="0.5">
                        <div class="range-timeline-values">
                            <strong id="sf-rating-from-label">0</strong>
                            <span><?= e(t('discover.to')) ?></span>
                            <strong id="sf-rating-to-label">10</strong>
                        </div>
                        <div class="range-timeline-track">
                            <div class="range-timeline-rail" aria-hidden="true"></div>
                            <div class="range-timeline-fill" id="sf-rating-range" aria-hidden="true"></div>
                            <input type="range" class="range-timeline-thumb range-timeline-thumb-from" id="sf-rating-from-range" min="0" max="10" value="0" step="0.5" aria-label="<?= e(t('a11y.min_rating')) ?>">
                            <input type="range" class="range-timeline-thumb range-timeline-thumb-to" id="sf-rating-to-range" min="0" max="10" value="10" step="0.5" aria-label="<?= e(t('a11y.max_rating')) ?>">
                        </div>
                        <div class="range-timeline-ticks" aria-hidden="true">
                            <?php for ($r = 0; $r <= 10; $r += 2): ?>
                            <span style="left: <?= ($r / 10) * 100 ?>%"><?= $r ?></span>
                            <?php endfor; ?>
                        </div>
                    </div>
                </section>
            </div>

            <div class="filter-picker" id="sf-picker-type" hidden>
                <div class="filter-picker-head">
                    <button type="button" class="filter-picker-back" aria-label="<?= e(t('common.back')) ?>"><i class="uil uil-angle-left"></i></button>
                    <div>
                        <p class="filters-sheet-kicker"><?= e(t('browse.choose_type')) ?></p>
                        <h3><?= e(t('discover.type')) ?></h3>
                    </div>
                </div>
                <div class="filter-picker-body">
                    <div class="filter-list">
                        <button type="button" class="filter-list-item is-on" data-sf-type="all">
                            <span class="filter-list-lead"><i class="uil uil-apps" aria-hidden="true"></i></span>
                            <span class="filter-list-label"><?= e(t('common.all')) ?></span>
                            <span class="filter-list-check" aria-hidden="true"><i class="uil uil-check"></i></span>
                        </button>
                        <button type="button" class="filter-list-item" data-sf-type="movie">
                            <span class="filter-list-lead"><i class="uil uil-clapper-board" aria-hidden="true"></i></span>
                            <span class="filter-list-label"><?= e(t('nav.movies')) ?></span>
                            <span class="filter-list-check" aria-hidden="true"><i class="uil uil-check"></i></span>
                        </button>
                        <button type="button" class="filter-list-item" data-sf-type="tv">
                            <span class="filter-list-lead"><i class="uil uil-tv-retro" aria-hidden="true"></i></span>
                            <span class="filter-list-label"><?= e(t('nav.tv_shows')) ?></span>
                            <span class="filter-list-check" aria-hidden="true"><i class="uil uil-check"></i></span>
                        </button>
                    </div>
                </div>
                <div class="filter-picker-foot">
                    <button type="button" class="filter-picker-done"><?= e(t('discover.done')) ?></button>
                </div>
            </div>

            <div class="filter-picker" id="sf-picker-genre" hidden>
                <div class="filter-picker-head">
                    <button type="button" class="filter-picker-back" aria-label="<?= e(t('common.back')) ?>"><i class="uil uil-angle-left"></i></button>
                    <div>
                        <p class="filters-sheet-kicker"><?= e(t('discover.choose_genres')) ?></p>
                        <h3><?= e(t('discover.genre')) ?></h3>
                    </div>
                </div>
                <div class="filter-picker-body">
                    <div class="filter-list" id="sf-genre-list">
                        <?php foreach ($searchGenresMovie as $gid => $gname): ?>
                        <button type="button" class="filter-list-item" data-sf-genre="<?= (int) $gid ?>" data-sf-genre-type="movie" data-label="<?= e($gname) ?>">
                            <span class="filter-list-lead"><i class="uil uil-film" aria-hidden="true"></i></span>
                            <span class="filter-list-label"><?= e($gname) ?></span>
                            <span class="filter-list-check" aria-hidden="true"><i class="uil uil-check"></i></span>
                        </button>
                        <?php endforeach; ?>
                        <?php foreach ($searchGenresTv as $gid => $gname): ?>
                        <button type="button" class="filter-list-item" data-sf-genre="<?= (int) $gid ?>" data-sf-genre-type="tv" data-label="<?= e($gname) ?>" hidden>
                            <span class="filter-list-lead"><i class="uil uil-tv-retro" aria-hidden="true"></i></span>
                            <span class="filter-list-label"><?= e($gname) ?></span>
                            <span class="filter-list-check" aria-hidden="true"><i class="uil uil-check"></i></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="filter-picker-foot">
                    <button type="button" class="filter-picker-done"><?= e(t('discover.done')) ?></button>
                </div>
            </div>
        </div>

        <div class="filters-sheet-foot">
            <button type="button" class="filters-sheet-reset" id="search-filters-sheet-reset"><?= e(t('discover.reset_all')) ?></button>
            <button type="button" class="filters-sheet-apply" id="search-filters-apply"><?= e(t('discover.show_results')) ?></button>
        </div>
    </div>
</div>

<div class="browse-sheet" id="browse-sheet" hidden>
    <button type="button" class="browse-sheet-backdrop" aria-label="<?= e(t('browse.close')) ?>"></button>
    <div class="browse-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="browse-sheet-title">
        <div class="browse-sheet-handle" aria-hidden="true"></div>
        <div class="browse-sheet-head">
            <h2 id="browse-sheet-title"><?= e(t('nav.browse')) ?></h2>
            <button type="button" class="browse-sheet-close" aria-label="<?= e(t('common.close')) ?>"><i class="uil uil-times"></i></button>
        </div>

        <div class="browse-sheet-body">
            <div class="browse-home-view" id="browse-home-view">

            <section class="browse-section browse-stats-section" id="browse-stats-section" aria-label="<?= e(t('browse.watch_stats')) ?>">
                <h3 class="browse-section-title" data-i18n="browse.your_activity"><?= e(t('browse.your_activity')) ?></h3>
                <div class="browse-stats-card">
                    <div class="browse-stats-grid" role="list">
                        <div class="browse-stat" role="listitem">
                            <i class="uil uil-clapper-board" aria-hidden="true"></i>
                            <div class="browse-stat-copy">
                                <strong id="browse-stat-movies">0</strong>
                                <span><?= e(t('nav.movies')) ?></span>
                            </div>
                        </div>
                        <div class="browse-stat" role="listitem">
                            <i class="uil uil-tv-retro" aria-hidden="true"></i>
                            <div class="browse-stat-copy">
                                <strong id="browse-stat-tv">0</strong>
                                <span><?= e(t('nav.tv_shows')) ?></span>
                            </div>
                        </div>
                        <div class="browse-stat" role="listitem">
                            <i class="uil uil-heart" aria-hidden="true"></i>
                            <div class="browse-stat-copy">
                                <strong id="browse-stat-favs">0</strong>
                                <span data-i18n="nav.watchlist"><?= e(t('nav.watchlist')) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="browse-stats-foot">
                        <i class="uil uil-clock" aria-hidden="true"></i>
                        <p class="browse-stats-time" id="browse-stats-time"><?= e(t('browse.no_history')) ?></p>
                    </div>
                </div>
            </section>

<section class="browse-section">
                <h3 class="browse-section-title" data-i18n="nav.features"><?= e(t('nav.features')) ?></h3>
                <div class="browse-grid browse-grid-3">
                    <a class="browse-tile" href="<?= e(url('/live')) ?>">
                        <span class="browse-tile-icon tone-blue"><i class="uil uil-rss-alt"></i></span>
                        <span class="browse-tile-label"><?= e(t('nav.live_tv')) ?></span>
                    </a>
                    <a class="browse-tile" href="<?= e(url('/games')) ?>">
                        <span class="browse-tile-icon tone-purple" aria-hidden="true"><svg class="browse-tile-svg" viewBox="0 0 24 24" width="1.15rem" height="1.15rem" fill="currentColor" focusable="false"><path d="M9 7h6a6 6 0 0 1 6 6v1.5a3.5 3.5 0 0 1-3.5 3.5h-.35a2 2 0 0 1-1.7-.95l-.55-.9a1 1 0 0 0-.85-.48H10a1 1 0 0 0-.85.48l-.55.9a2 2 0 0 1-1.7.95H6.5A3.5 3.5 0 0 1 3 14.5V13a6 6 0 0 1 6-6Zm-1.75 4.25a.75.75 0 0 0-.75.75v.75H5.75a.75.75 0 0 0 0 1.5H6.5v.75a.75.75 0 0 0 1.5 0v-.75h.75a.75.75 0 0 0 0-1.5H8v-.75a.75.75 0 0 0-.75-.75Zm8.5 1a1 1 0 1 0 0 2 1 1 0 0 0 0-2Zm2.25 1.75a1 1 0 1 0-2 0 1 1 0 0 0 2 0Z"/></svg></span>
                        <span class="browse-tile-label"><?= e(t('nav.games')) ?></span>
                    </a>
                    <button type="button" class="browse-tile" data-browse-open="watch-party">
                        <span class="browse-tile-icon tone-yellow"><i class="uil uil-users-alt"></i></span>
                        <span class="browse-tile-label"><?= e(t('nav.watch_party')) ?></span>
                    </button>
                </div>
            </section>

            <section class="browse-section">
                <h3 class="browse-section-title"><?= e(t('nav.personal')) ?></h3>
                <div class="browse-grid browse-grid-2">
                    <a class="browse-card" href="<?= e(url('/favorites')) ?>" data-browse-pref="watchlist">
                        <span class="browse-card-icon"><i class="uil uil-heart"></i></span>
                        <span class="browse-card-copy">
                            <strong data-i18n="nav.watchlist"><?= e(t('nav.watchlist')) ?></strong>
                            <em data-i18n="browse.saved_titles"><?= e(t('browse.saved_titles')) ?></em>
                        </span>
                    </a>
                    <a class="browse-card" href="<?= e(url('/home')) ?>#continue-watching" data-browse-open="history" data-browse-pref="continue">
                        <span class="browse-card-icon"><i class="uil uil-history"></i></span>
                        <span class="browse-card-copy">
                            <strong data-i18n="nav.history"><?= e(t('nav.history')) ?></strong>
                            <em data-i18n="nav.continue_watching"><?= e(t('nav.continue_watching')) ?></em>
                        </span>
                    </a>
                </div>
            </section>

            <section class="browse-section" id="browse-account-section">
                <h3 class="browse-section-title"><?= e(t('nav.account')) ?></h3>
                <div class="browse-auth-cta" id="browse-auth-guest">
                    <button type="button" class="browse-auth-cta-card" data-browse-auth="login">
                        <span class="browse-auth-cta-icon"><i class="uil uil-signin"></i></span>
                        <span class="browse-auth-cta-copy">
                            <strong><?= e(t('auth.login')) ?></strong>
                            <em><?= e(t('auth.sync_favorites')) ?></em>
                        </span>
                        <i class="uil uil-angle-right"></i>
                    </button>
                    <button type="button" class="browse-auth-cta-card is-accent" data-browse-auth="register">
                        <span class="browse-auth-cta-icon"><i class="uil uil-user-plus"></i></span>
                        <span class="browse-auth-cta-copy">
                            <strong><?= e(t('auth.create_account')) ?></strong>
                            <em><?= e(str_replace('{site}', $site, t('auth.join_seconds'))) ?></em>
                        </span>
                        <i class="uil uil-angle-right"></i>
                    </button>
                    <button type="button" class="browse-list-item" data-browse-open="settings">
                        <i class="uil uil-setting" aria-hidden="true"></i>
                        <span><?= e(t('browse.settings')) ?></span>
                        <i class="uil uil-angle-right" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="browse-auth-user" id="browse-auth-user" hidden>
                    <div class="browse-auth-user-card">
                        <span class="browse-auth-user-avatar" id="browse-auth-avatar">?</span>
                        <span class="browse-auth-cta-copy">
                            <strong id="browse-auth-name"><?= e(t('auth.signed_in')) ?></strong>
                            <em id="browse-auth-email"></em>
                        </span>
                    </div>
                    <button type="button" class="browse-list-item" data-browse-open="settings">
                        <i class="uil uil-setting" aria-hidden="true"></i>
                        <span><?= e(t('browse.settings')) ?></span>
                        <i class="uil uil-angle-right" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="browse-list-item" id="browse-auth-logout">
                        <i class="uil uil-signout"></i>
                        <span><?= e(t('auth.log_out')) ?></span>
                        <i class="uil uil-angle-right"></i>
                    </button>
                </div>
            </section>
            </div>

<div class="browse-settings-view" id="browse-settings-view" hidden>
                <div class="browse-settings-panel">
                    <div class="browse-auth-top">
                        <button type="button" class="browse-auth-back" id="browse-settings-back" aria-label="<?= e(t('auth.back_to_browse')) ?>">
                            <i class="uil uil-arrow-left"></i>
                        </button>
                        <div class="browse-settings-heading">
                            <p class="browse-auth-kicker"><?= e(t('browse.preferences')) ?></p>
                            <h3 class="browse-auth-title" style="margin:0;"><?= e(t('browse.settings')) ?></h3>
                        </div>
                    </div>


                    <section class="browse-settings-block">
                        <h4 class="browse-settings-label"><?= e(t('browse.theme', [], 'Theme color')) ?></h4>
                        <div class="browse-settings-themes" id="browse-settings-themes" role="listbox" aria-label="<?= e(t('browse.theme', [], 'Theme color')) ?>">
                            <button type="button" class="browse-settings-theme" data-accent="ember" style="--swatch:#db6937" title="Ember" aria-label="Ember" role="option"></button>
                            <button type="button" class="browse-settings-theme" data-accent="crimson" style="--swatch:#e11d48" title="Crimson" aria-label="Crimson" role="option"></button>
                            <button type="button" class="browse-settings-theme" data-accent="violet" style="--swatch:#8b5cf6" title="Violet" aria-label="Violet" role="option"></button>
                            <button type="button" class="browse-settings-theme" data-accent="azure" style="--swatch:#3b82f6" title="Azure" aria-label="Azure" role="option"></button>
                            <button type="button" class="browse-settings-theme" data-accent="teal" style="--swatch:#14b8a6" title="Teal" aria-label="Teal" role="option"></button>
                            <button type="button" class="browse-settings-theme" data-accent="lime" style="--swatch:#84cc16" title="Lime" aria-label="Lime" role="option"></button>
                            <button type="button" class="browse-settings-theme" data-accent="rose" style="--swatch:#ec4899" title="Rose" aria-label="Rose" role="option"></button>
                            <button type="button" class="browse-settings-theme" data-accent="gold" style="--swatch:#d4a017" title="Gold" aria-label="Gold" role="option"></button>
                            <button type="button" class="browse-settings-theme" data-accent="silver" style="--swatch:#b8c0cc" title="Silver" aria-label="Silver" role="option"></button>
                        </div>
                        <p class="browse-settings-theme-hint"><?= e(t('browse.theme_help', [], 'Updates accents, background glow, and the logo color.')) ?></p>
                    </section>

                    <section class="browse-settings-block">
                        <h4 class="browse-settings-label" data-i18n="browse.language"><?= e(t('browse.language')) ?></h4>
                        <?php $uiLang = class_exists('Locale') ? Locale::current() : 'en'; ?>
                        <div class="browse-settings-langs" id="browse-settings-langs" role="listbox" aria-label="<?= e(t('browse.language')) ?>">
                            <?php foreach ((class_exists('Locale') ? Locale::LABELS : ['en' => 'English']) as $code => $label): ?>
                            <button type="button" class="browse-settings-lang<?= $uiLang === $code ? ' is-active' : '' ?>" data-lang="<?= e($code) ?>" role="option" aria-selected="<?= $uiLang === $code ? 'true' : 'false' ?>"><?= e($label) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="browse-settings-block">
                        <h4 class="browse-settings-label">Options</h4>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong>Performance mode</strong>
                                <em>Turns off card trailers (hover / long-press).</em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-performance" role="switch" aria-checked="false" aria-label="Performance mode"></button>
                        </div>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong>Pop ads</strong>
                                <em>Turn off pop-ups. Other ads still follow site settings.</em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-ads" role="switch" aria-checked="true" aria-label="Pop ads"></button>
                        </div>
                    </section>

                    <section class="browse-settings-block">
                        <h4 class="browse-settings-label"><?= e(t('browse.playback')) ?></h4>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong data-i18n="browse.autoplay_short"><?= e(t('browse.autoplay_short')) ?></strong>
                                <em data-i18n="browse.autoplay_help"><?= e(t('browse.autoplay_help')) ?></em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-autoplay" role="switch" aria-checked="false" aria-label="<?= e(t('browse.autoplay_short')) ?>"></button>
                        </div>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong><?= e(t('browse.autonext_short')) ?></strong>
                                <em><?= e(t('browse.autonext_help')) ?></em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-autonext" role="switch" aria-checked="false" aria-label="<?= e(t('browse.autonext_short')) ?>"></button>
                        </div>
                    </section>

                    <section class="browse-settings-block">
                        <h4 class="browse-settings-label"><?= e(t('browse.library')) ?></h4>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong data-i18n="browse.watchlist"><?= e(t('browse.watchlist')) ?></strong>
                                <em data-i18n="browse.watchlist_help"><?= e(t('browse.watchlist_help')) ?></em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-watchlist" role="switch" aria-checked="true" aria-label="<?= e(t('browse.watchlist')) ?>"></button>
                        </div>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong data-i18n="browse.continue_watching"><?= e(t('browse.continue_watching')) ?></strong>
                                <em data-i18n="browse.continue_help"><?= e(t('browse.continue_help')) ?></em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-continue" role="switch" aria-checked="true" aria-label="<?= e(t('browse.continue_watching')) ?>"></button>
                        </div>
                    </section>
</div>
            </div>

            <div class="browse-auth-view" id="browse-auth-view" hidden>
                <div class="browse-auth-panel">
                    <div class="browse-auth-top">
                        <button type="button" class="browse-auth-back" id="browse-auth-back" aria-label="<?= e(t('auth.back_to_browse')) ?>">
                            <i class="uil uil-arrow-left"></i>
                        </button>
                        <div class="browse-auth-tabs" role="tablist">
                            <button type="button" class="browse-auth-tab is-active" data-auth-tab="login" role="tab" aria-selected="true"><?= e(t('auth.login')) ?></button>
                            <button type="button" class="browse-auth-tab" data-auth-tab="register" role="tab" aria-selected="false"><?= e(t('auth.register')) ?></button>
                        </div>
                    </div>

                    <div class="browse-auth-hero">
                        <div class="browse-auth-orb" aria-hidden="true"></div>
                        <p class="browse-auth-kicker" id="browse-auth-kicker"><?= e(t('auth.welcome_back')) ?></p>
                        <h3 class="browse-auth-title" id="browse-auth-title"><?= e(str_replace('{site}', $site, t('auth.sign_in_to'))) ?></h3>
                        <p class="browse-auth-sub" id="browse-auth-sub"><?= e(t('auth.sign_in_sub')) ?></p>
                    </div>

                    <form class="browse-auth-form" id="browse-login-form" autocomplete="on">
                        <label class="browse-auth-field">
                            <span><?= e(t('auth.email')) ?></span>
                            <input type="email" name="email" required autocomplete="email" inputmode="email" placeholder="<?= e(t('auth.email_ph')) ?>">
                        </label>
                        <label class="browse-auth-field">
                            <span><?= e(t('auth.password')) ?></span>
                            <input type="password" name="password" required autocomplete="current-password" placeholder="<?= e(t('auth.your_password')) ?>" minlength="6">
                        </label>
                        <div class="browse-turnstile" id="browse-turnstile-login"></div>
                        <p class="browse-auth-error" id="browse-login-error" hidden></p>
                        <button type="submit" class="browse-auth-submit" id="browse-login-submit">
                            <span><?= e(t('auth.sign_in')) ?></span>
                            <i class="uil uil-arrow-right"></i>
                        </button>
                    </form>

                    <form class="browse-auth-form" id="browse-register-form" autocomplete="on" hidden>
                        <label class="browse-auth-field">
                            <span><?= e(t('auth.name')) ?></span>
                            <input type="text" name="name" required autocomplete="name" placeholder="<?= e(t('auth.display_name')) ?>">
                        </label>
                        <label class="browse-auth-field">
                            <span><?= e(t('auth.email')) ?></span>
                            <input type="email" name="email" required autocomplete="email" inputmode="email" placeholder="<?= e(t('auth.email_ph')) ?>">
                        </label>
                        <label class="browse-auth-field">
                            <span><?= e(t('auth.password')) ?></span>
                            <input type="password" name="password" required autocomplete="new-password" placeholder="<?= e(t('auth.password_hint')) ?>" minlength="6">
                        </label>
                        <div class="browse-turnstile" id="browse-turnstile-register"></div>
                        <p class="browse-auth-error" id="browse-register-error" hidden></p>
                        <button type="submit" class="browse-auth-submit" id="browse-register-submit">
                            <span><?= e(t('auth.create_account')) ?></span>
                            <i class="uil uil-arrow-right"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
