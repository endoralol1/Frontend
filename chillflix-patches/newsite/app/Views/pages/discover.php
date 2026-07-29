<?php
$isMovie = $type === 'movie';
$label = $isMovie ? 'Movies' : 'TV Shows';
$results = $data['results'] ?? [];
$totalPages = max(1, (int) ($data['total_pages'] ?? 1));
$path = $isMovie ? '/movies' : '/tv-series';
$selectedGenres = array_filter(array_map('intval', (array) ($_GET['with_genres'] ?? ($genre ? [$genre] : []))));
$selectedYearFrom = (string) ($yearFrom ?? '');
$selectedYearTo = (string) ($yearTo ?? '');
if ($selectedYearFrom === '' && $selectedYearTo === '' && $year !== '') {
    $selectedYearFrom = (string) $year;
    $selectedYearTo = (string) $year;
}
$selectedSort = $sort;
$selectedCountries = array_values(array_map('strval', (array) ($selectedCountries ?? [])));
if (!$selectedCountries) {
    $legacyCountry = trim((string) ($_GET['with_origin_country'] ?? ''));
    if ($legacyCountry !== '' && !is_array($_GET['with_origin_country'] ?? null)) {
        $selectedCountries = [$legacyCountry];
    }
}
$selectedRating = (string) ($_GET['vote_average_gte'] ?? $_GET['vote_average.gte'] ?? $_GET['rating'] ?? '');
$selectedProviders = array_values(array_map('strval', (array) ($selectedProviders ?? [])));
if (!$selectedProviders) {
    $legacyProvider = trim((string) ($_GET['with_watch_providers'] ?? ''));
    if ($legacyProvider !== '' && !is_array($_GET['with_watch_providers'] ?? null)) {
        $selectedProviders = [$legacyProvider];
    }
}
$selectedNetworks = array_values(array_map('strval', (array) ($selectedNetworks ?? [])));
if (!$selectedNetworks) {
    $legacyNetwork = trim((string) ($_GET['with_networks'] ?? ''));
    if ($legacyNetwork !== '' && !is_array($_GET['with_networks'] ?? null)) {
        $selectedNetworks = [$legacyNetwork];
    }
}
$genreMode = (string) ($_GET['genre_mode'] ?? '') === 'and' ? 'and' : '';
$sortChosen = isset($_GET['sort_by']);
$viewMode = (($_GET['view'] ?? 'grid') === 'list') ? 'list' : 'grid';

$countries = [
    'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia',
    'IN' => 'India', 'JP' => 'Japan', 'KR' => 'South Korea', 'FR' => 'France', 'DE' => 'Germany',
    'ES' => 'Spain', 'IT' => 'Italy', 'BR' => 'Brazil', 'MX' => 'Mexico', 'TR' => 'Turkey',
    'CN' => 'China', 'RU' => 'Russia', 'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark',
];
$providers = [
    '8' => 'Netflix', '9' => 'Amazon Prime', '337' => 'Disney+', '15' => 'Hulu',
    '384' => 'HBO Max', '350' => 'Apple TV+', '531' => 'Paramount+', '386' => 'Peacock',
];
$networks = [
    '213' => 'Netflix', '49' => 'HBO', '67' => 'Fox', '56' => 'Cartoon Network',
    '6' => 'NBC', '2' => 'ABC', '16' => 'CBS', '453' => 'Hulu', '318' => 'Marvel Studios',
];
$sorts = $isMovie
    ? [
        'popularity.desc' => 'Popularity',
        'primary_release_date.desc' => 'Release Date',
        'vote_average.desc' => 'Rating',
        'vote_count.desc' => 'Vote Count',
        'revenue.desc' => 'Revenue',
        'original_title.asc' => 'Title (A-Z)',
    ]
    : [
        'popularity.desc' => 'Popularity',
        'first_air_date.desc' => 'Air Date',
        'vote_average.desc' => 'Rating',
        'vote_count.desc' => 'Vote Count',
        'name.asc' => 'Name (A-Z)',
    ];
$ratings = ['9' => '9+', '8' => '8+', '7' => '7+', '6' => '6+', '5' => '5+', '4' => '4+'];
$sidebarItems = $sidebarItems ?? [];
$yearMax = (int) date('Y') + 1;
$yearMin = 1970;

$filterChips = [];
if ($selectedGenres) {
    $names = [];
    foreach ($selectedGenres as $gid) {
        if (isset($genres[$gid])) {
            $names[] = $genres[$gid];
        }
    }
    $filterChips[] = ['key' => 'genres', 'label' => $names ? implode(', ', array_slice($names, 0, 2)) . (count($names) > 2 ? ' +' . (count($names) - 2) : '') : (count($selectedGenres) . ' genres'), 'clear' => ['with_genres', 'genre_mode']];
}
if ($selectedNetworks) {
    $nnames = [];
    foreach ($selectedNetworks as $nid) {
        if (isset($networks[$nid])) {
            $nnames[] = $networks[$nid];
        }
    }
    $filterChips[] = ['key' => 'network', 'label' => $nnames ? implode(', ', array_slice($nnames, 0, 2)) . (count($nnames) > 2 ? ' +' . (count($nnames) - 2) : '') : (count($selectedNetworks) . ' networks'), 'clear' => ['with_networks']];
}
if ($selectedCountries) {
    $cnames = [];
    foreach ($selectedCountries as $code) {
        if (isset($countries[$code])) {
            $cnames[] = $countries[$code];
        }
    }
    $filterChips[] = ['key' => 'country', 'label' => $cnames ? implode(', ', array_slice($cnames, 0, 2)) . (count($cnames) > 2 ? ' +' . (count($cnames) - 2) : '') : (count($selectedCountries) . ' countries'), 'clear' => ['with_origin_country']];
}
if ($selectedYearFrom !== '' || $selectedYearTo !== '') {
    $yearLabel = ($selectedYearFrom !== '' && $selectedYearTo !== '' && $selectedYearFrom === $selectedYearTo)
        ? $selectedYearFrom
        : (($selectedYearFrom !== '' ? $selectedYearFrom : '…') . '–' . ($selectedYearTo !== '' ? $selectedYearTo : '…'));
    $filterChips[] = ['key' => 'year', 'label' => $yearLabel, 'clear' => ['year', 'year_from', 'year_to']];
}
if ($selectedProviders) {
    $pnames = [];
    foreach ($selectedProviders as $pid) {
        if (isset($providers[$pid])) {
            $pnames[] = $providers[$pid];
        }
    }
    $filterChips[] = ['key' => 'provider', 'label' => $pnames ? implode(', ', array_slice($pnames, 0, 2)) . (count($pnames) > 2 ? ' +' . (count($pnames) - 2) : '') : (count($selectedProviders) . ' providers'), 'clear' => ['with_watch_providers']];
}
if ($selectedRating !== '') {
    $filterChips[] = ['key' => 'rating', 'label' => $selectedRating . '+', 'clear' => ['rating', 'vote_average_gte', 'vote_average.gte']];
}
if ($sortChosen) {
    $filterChips[] = ['key' => 'sort', 'label' => $sorts[$selectedSort] ?? 'Sorted', 'clear' => ['sort_by']];
}
$activeFilterCount = count($filterChips);

$chipUrl = static function (array $clearKeys) use ($path): string {
    $qs = $_GET;
    unset($qs['page']);
    foreach ($clearKeys as $k) {
        unset($qs[$k], $qs[$k . '[]']);
    }
    // also clear array genre/network/country/provider param variants
    foreach (['with_genres', 'with_networks', 'with_origin_country', 'with_watch_providers'] as $arrKey) {
        if (in_array($arrKey, $clearKeys, true)) {
            unset($qs[$arrKey], $qs[$arrKey . '[]']);
        }
    }
    $q = http_build_query($qs);
    return url($path) . ($q !== '' ? ('?' . $q) : '');
};
?>
<main class="page-pad-top discover-page">
    <div class="container">
        <div class="aside-wrapper">
            <aside class="main">
                <div class="section">
                    <div class="head d-flex justify-content-between align-items-center">
                        <div class="start">
                            <h2 class="title gardiently"><?= e($label) ?></h2>
                        </div>
                        <div class="end">
                            <div class="btn-group view-switcher" role="group">
                                <button type="button" class="btn btn-sm btn-outline-primary btn-view-switcher <?= $viewMode === 'list' ? 'active' : '' ?>" data-view="list" aria-label="List view">
                                    <i class="uil uil-list-ul"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary btn-view-switcher <?= $viewMode === 'grid' ? 'active' : '' ?>" data-view="grid" aria-label="Grid view">
                                    <i class="uil uil-apps"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <form id="site-filters" class="filters-compact" method="get" action="<?= e(url($path)) ?>" autocomplete="off">
                        <input type="hidden" name="view" id="view-mode-input" value="<?= e($viewMode) ?>">

                        <div class="filters-toolbar">
                            <button type="button" class="filters-open-btn" id="filters-open" aria-haspopup="dialog" aria-controls="filters-sheet" aria-expanded="false">
                                <i class="uil uil-filter" aria-hidden="true"></i>
                                <span>Filters</span>
                                <?php if ($activeFilterCount > 0): ?>
                                <span class="filters-count"><?= (int) $activeFilterCount ?></span>
                                <?php endif; ?>
                            </button>

                            <?php if ($filterChips): ?>
                            <div class="filters-chips" aria-label="Active filters">
                                <?php foreach ($filterChips as $chip): ?>
                                <a class="filters-chip" href="<?= e($chipUrl($chip['clear'])) ?>">
                                    <span><?= e($chip['label']) ?></span>
                                    <i class="uil uil-times" aria-hidden="true"></i>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($activeFilterCount > 0): ?>
                            <a class="filters-reset-link" href="<?= e(url($path) . ($viewMode !== 'grid' ? ('?view=' . rawurlencode($viewMode)) : '')) ?>">Reset</a>
                            <?php endif; ?>
                        </div>

                        <div class="filters-sheet" id="filters-sheet" hidden>
                            <button type="button" class="filters-sheet-backdrop" aria-label="Close filters"></button>
                            <div class="filters-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="filters-sheet-title">
                                <div class="filters-sheet-handle" aria-hidden="true"></div>
                                <div class="filters-sheet-head">
                                    <div>
                                        <p class="filters-sheet-kicker">Refine results</p>
                                        <h2 id="filters-sheet-title">Filters</h2>
                                    </div>
                                    <button type="button" class="filters-sheet-close" aria-label="Close filters"><i class="uil uil-times"></i></button>
                                </div>

                                <div class="filters-sheet-body">
                                    <section class="filter-section">
                                        <h3 class="filter-section-title">Type</h3>
                                        <div class="filter-pills">
                                            <label class="filter-pill">
                                                <input type="checkbox" id="type-movie" name="type[]" value="movie" data-href="<?= e(url('/movies')) ?>" <?= $isMovie ? 'checked' : '' ?>>
                                                <span>Movies</span>
                                            </label>
                                            <label class="filter-pill">
                                                <input type="checkbox" id="type-tv" name="type[]" value="tv" data-href="<?= e(url('/tv-series')) ?>" <?= !$isMovie ? 'checked' : '' ?>>
                                                <span>TV Shows</span>
                                            </label>
                                        </div>
                                    </section>

                                    <section class="filter-section">
                                        <h3 class="filter-section-title">Genre</h3>
                                        <div class="filter-pills filter-pills-wrap">
                                            <?php foreach ($genres as $gid => $gname): ?>
                                            <label class="filter-pill">
                                                <input type="checkbox" id="genre-<?= (int) $gid ?>" name="with_genres[]" value="<?= (int) $gid ?>" <?= in_array((int) $gid, $selectedGenres, true) ? 'checked' : '' ?>>
                                                <span><?= e($gname) ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <label class="filter-check-row">
                                            <input type="checkbox" id="genre_mode" name="genre_mode" value="and" <?= $genreMode === 'and' ? 'checked' : '' ?>>
                                            <span>Must have all selected genres</span>
                                        </label>
                                    </section>

                                    <section class="filter-section">
                                        <h3 class="filter-section-title">Network</h3>
                                        <div class="filter-pills filter-pills-wrap">
                                            <?php foreach ($networks as $nid => $nname): ?>
                                            <label class="filter-pill">
                                                <input type="checkbox" id="net-<?= e($nid) ?>" name="with_networks[]" value="<?= e($nid) ?>" <?= in_array((string) $nid, $selectedNetworks, true) ? 'checked' : '' ?>>
                                                <span><?= e($nname) ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>

                                    <section class="filter-section">
                                        <h3 class="filter-section-title">Country</h3>
                                        <div class="filter-pills filter-pills-wrap filter-pills-scroll">
                                            <?php foreach ($countries as $code => $cname): ?>
                                            <label class="filter-pill">
                                                <input type="checkbox" id="country-<?= e($code) ?>" name="with_origin_country[]" value="<?= e($code) ?>" <?= in_array($code, $selectedCountries, true) ? 'checked' : '' ?>>
                                                <span><?= e($cname) ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>

                                    <section class="filter-section">
                                        <h3 class="filter-section-title">Year</h3>
                                        <div class="filter-year-range">
                                            <label class="filter-year-field">
                                                <span class="filter-year-label">From</span>
                                                <select name="year_from" id="year-from" aria-label="Year from">
                                                    <option value="">Any</option>
                                                    <?php for ($y = $yearMax; $y >= $yearMin; $y--): ?>
                                                    <option value="<?= $y ?>" <?= $selectedYearFrom === (string) $y ? 'selected' : '' ?>><?= $y ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </label>
                                            <span class="filter-year-sep" aria-hidden="true">to</span>
                                            <label class="filter-year-field">
                                                <span class="filter-year-label">To</span>
                                                <select name="year_to" id="year-to" aria-label="Year to">
                                                    <option value="">Any</option>
                                                    <?php for ($y = $yearMax; $y >= $yearMin; $y--): ?>
                                                    <option value="<?= $y ?>" <?= $selectedYearTo === (string) $y ? 'selected' : '' ?>><?= $y ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </label>
                                        </div>
                                    </section>

                                    <section class="filter-section">
                                        <h3 class="filter-section-title">Provider</h3>
                                        <div class="filter-pills filter-pills-wrap">
                                            <?php foreach ($providers as $pid => $pname): ?>
                                            <label class="filter-pill">
                                                <input type="checkbox" id="prov-<?= e($pid) ?>" name="with_watch_providers[]" value="<?= e($pid) ?>" <?= in_array((string) $pid, $selectedProviders, true) ? 'checked' : '' ?>>
                                                <span><?= e($pname) ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>

                                    <section class="filter-section">
                                        <h3 class="filter-section-title">Rating</h3>
                                        <div class="filter-pills">
                                            <?php foreach ($ratings as $rval => $rlabel): ?>
                                            <label class="filter-pill">
                                                <input type="radio" id="rating-<?= e($rval) ?>" name="rating" value="<?= e($rval) ?>" <?= $selectedRating === (string) $rval ? 'checked' : '' ?>>
                                                <span><?= e($rlabel) ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>

                                    <section class="filter-section">
                                        <h3 class="filter-section-title">Sort by</h3>
                                        <div class="filter-pills filter-pills-wrap">
                                            <?php foreach ($sorts as $sval => $slabel): ?>
                                            <label class="filter-pill">
                                                <input type="radio" id="sort-<?= md5($sval) ?>" name="sort_by" value="<?= e($sval) ?>" <?= $sortChosen && $selectedSort === $sval ? 'checked' : '' ?>>
                                                <span><?= e($slabel) ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                </div>

                                <div class="filters-sheet-foot">
                                    <a class="filters-sheet-reset" href="<?= e(url($path) . ($viewMode !== 'grid' ? ('?view=' . rawurlencode($viewMode)) : '')) ?>">Reset all</a>
                                    <button type="submit" class="filters-sheet-apply">Show results</button>
                                </div>
                            </div>
                        </div>
                    </form>

                    <div class="scaff movies items view-<?= e($viewMode) ?>" id="discover-results">
                        <?php foreach ($results as $item) {
                            require __DIR__ . '/../partials/movie-card.php';
                        } ?>
                    </div>

                    <?php if ($totalPages > 1):
                        $qs = $_GET;
                    ?>
                    <nav class="navigation">
                        <ul class="pagination pagination-sm justify-content-center">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <?php $qs['page'] = max(1, $page - 1); ?>
                                <a class="page-link" href="?<?= e(http_build_query($qs)) ?>" aria-label="Previous">
                                    <i class="uil uil-angle-left-b"></i>
                                </a>
                            </li>
                            <li class="page-item disabled">
                                <span class="page-link"><?= (int) $page ?> / <?= (int) $totalPages ?></span>
                            </li>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <?php $qs['page'] = min($totalPages, $page + 1); ?>
                                <a class="page-link" href="?<?= e(http_build_query($qs)) ?>" aria-label="Next">
                                    <i class="uil uil-angle-right-b"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </aside>

            <aside class="sidebar">
                <div class="section">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">You may like</h2>
                        </div>
                    </div>
                    <div class="body">
                        <div class="scaff movie-sidebar items">
                            <?php
                            foreach ($sidebarItems as $item) {
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
