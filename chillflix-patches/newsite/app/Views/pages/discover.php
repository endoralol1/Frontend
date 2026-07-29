<?php
$isMovie = $type === 'movie';
$label = $isMovie ? 'Movies' : 'TV Shows';
$results = $data['results'] ?? [];
$totalPages = max(1, (int) ($data['total_pages'] ?? 1));
$path = $isMovie ? '/movies' : '/tv-series';
$selectedGenres = array_values(array_filter(array_map('intval', (array) ($selectedGenres ?? ($_GET['with_genres'] ?? ($genre ? [$genre] : []))))));
$excludedGenres = array_values(array_filter(array_map('intval', (array) ($excludedGenres ?? ($_GET['without_genres'] ?? [])))));
$selectedYearFrom = (string) ($yearFrom ?? '');
$selectedYearTo = (string) ($yearTo ?? '');
if ($selectedYearFrom === '' && $selectedYearTo === '' && !empty($year)) {
    $selectedYearFrom = (string) $year;
    $selectedYearTo = (string) $year;
}
$selectedSort = $sort;
$selectedCountries = array_values(array_map('strval', (array) ($selectedCountries ?? [])));
$excludedCountries = array_values(array_map('strval', (array) ($excludedCountries ?? ($_GET['without_origin_country'] ?? []))));
$selectedRatingFrom = (string) ($ratingFrom ?? ($_GET['rating_from'] ?? ''));
$selectedRatingTo = (string) ($ratingTo ?? ($_GET['rating_to'] ?? ''));
$legacyRating = (string) ($_GET['vote_average_gte'] ?? $_GET['vote_average.gte'] ?? $_GET['rating'] ?? '');
if ($selectedRatingFrom === '' && $selectedRatingTo === '' && $legacyRating !== '') {
    $selectedRatingFrom = $legacyRating;
}
$selectedRating = $selectedRatingFrom; // legacy chip helper alias
$selectedProviders = array_values(array_map('strval', (array) ($selectedProviders ?? [])));
$excludedProviders = array_values(array_map('strval', (array) ($excludedProviders ?? ($_GET['without_watch_providers'] ?? []))));
$selectedNetworks = array_values(array_map('strval', (array) ($selectedNetworks ?? [])));
$excludedNetworks = array_values(array_map('strval', (array) ($excludedNetworks ?? ($_GET['without_networks'] ?? []))));
$genreMode = '';
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
// TV broadcast networks only — streaming apps live under Provider (no duplicates)
$networks = [
    '49' => 'HBO', '67' => 'Fox', '56' => 'Cartoon Network',
    '6' => 'NBC', '2' => 'ABC', '16' => 'CBS', '318' => 'Marvel Studios',
    '174' => 'AMC', '88' => 'FX', '54' => 'Disney Channel',
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
$timelineFrom = $selectedYearFrom !== '' ? (int) $selectedYearFrom : $yearMin;
$timelineTo = $selectedYearTo !== '' ? (int) $selectedYearTo : $yearMax;
if ($timelineFrom > $timelineTo) {
    [$timelineFrom, $timelineTo] = [$timelineTo, $timelineFrom];
}
$ratingMin = 0;
$ratingMax = 10;
$ratingStep = 0.5;
$ratingTimelineFrom = $selectedRatingFrom !== '' && is_numeric($selectedRatingFrom) ? (float) $selectedRatingFrom : $ratingMin;
$ratingTimelineTo = $selectedRatingTo !== '' && is_numeric($selectedRatingTo) ? (float) $selectedRatingTo : $ratingMax;
if ($ratingTimelineFrom > $ratingTimelineTo) {
    [$ratingTimelineFrom, $ratingTimelineTo] = [$ratingTimelineTo, $ratingTimelineFrom];
}

$triState = static function ($value, array $included, array $excluded): string {
    $v = (string) $value;
    foreach ($excluded as $x) {
        if ((string) $x === $v) {
            return 'out';
        }
    }
    foreach ($included as $x) {
        if ((string) $x === $v) {
            return 'in';
        }
    }
    return 'off';
};

$filterChips = [];
if ($selectedGenres) {
    $names = [];
    foreach ($selectedGenres as $gid) {
        if (isset($genres[$gid])) {
            $names[] = $genres[$gid];
        }
    }
    $filterChips[] = ['key' => 'genres', 'label' => $names ? implode(', ', array_slice($names, 0, 2)) . (count($names) > 2 ? ' +' . (count($names) - 2) : '') : (count($selectedGenres) . ' genres'), 'clear' => ['with_genres'], 'tone' => 'in'];
}
if ($excludedGenres) {
    $names = [];
    foreach ($excludedGenres as $gid) {
        if (isset($genres[$gid])) {
            $names[] = '−' . $genres[$gid];
        }
    }
    $filterChips[] = ['key' => 'genres-out', 'label' => $names ? implode(', ', array_slice($names, 0, 2)) . (count($names) > 2 ? ' +' . (count($names) - 2) : '') : ('−' . count($excludedGenres) . ' genres'), 'clear' => ['without_genres'], 'tone' => 'out'];
}
if ($selectedNetworks) {
    $nnames = [];
    foreach ($selectedNetworks as $nid) {
        if (isset($networks[$nid])) {
            $nnames[] = $networks[$nid];
        }
    }
    $filterChips[] = ['key' => 'network', 'label' => $nnames ? implode(', ', array_slice($nnames, 0, 2)) . (count($nnames) > 2 ? ' +' . (count($nnames) - 2) : '') : (count($selectedNetworks) . ' networks'), 'clear' => ['with_networks'], 'tone' => 'in'];
}
if ($excludedNetworks) {
    $nnames = [];
    foreach ($excludedNetworks as $nid) {
        if (isset($networks[$nid])) {
            $nnames[] = '−' . $networks[$nid];
        }
    }
    $filterChips[] = ['key' => 'network-out', 'label' => $nnames ? implode(', ', array_slice($nnames, 0, 2)) : ('−' . count($excludedNetworks)), 'clear' => ['without_networks'], 'tone' => 'out'];
}
if ($selectedCountries) {
    $cnames = [];
    foreach ($selectedCountries as $code) {
        if (isset($countries[$code])) {
            $cnames[] = $countries[$code];
        }
    }
    $filterChips[] = ['key' => 'country', 'label' => $cnames ? implode(', ', array_slice($cnames, 0, 2)) . (count($cnames) > 2 ? ' +' . (count($cnames) - 2) : '') : (count($selectedCountries) . ' countries'), 'clear' => ['with_origin_country'], 'tone' => 'in'];
}
if ($excludedCountries) {
    $cnames = [];
    foreach ($excludedCountries as $code) {
        if (isset($countries[$code])) {
            $cnames[] = '−' . $countries[$code];
        }
    }
    $filterChips[] = ['key' => 'country-out', 'label' => $cnames ? implode(', ', array_slice($cnames, 0, 2)) : ('−' . count($excludedCountries)), 'clear' => ['without_origin_country'], 'tone' => 'out'];
}
if ($selectedYearFrom !== '' || $selectedYearTo !== '') {
    $yearLabel = ($selectedYearFrom !== '' && $selectedYearTo !== '' && $selectedYearFrom === $selectedYearTo)
        ? $selectedYearFrom
        : (($selectedYearFrom !== '' ? $selectedYearFrom : (string) $yearMin) . '–' . ($selectedYearTo !== '' ? $selectedYearTo : (string) $yearMax));
    $filterChips[] = ['key' => 'year', 'label' => $yearLabel, 'clear' => ['year', 'year_from', 'year_to'], 'tone' => 'in'];
}
if ($selectedProviders) {
    $pnames = [];
    foreach ($selectedProviders as $pid) {
        if (isset($providers[$pid])) {
            $pnames[] = $providers[$pid];
        }
    }
    $filterChips[] = ['key' => 'provider', 'label' => $pnames ? implode(', ', array_slice($pnames, 0, 2)) . (count($pnames) > 2 ? ' +' . (count($pnames) - 2) : '') : (count($selectedProviders) . ' providers'), 'clear' => ['with_watch_providers'], 'tone' => 'in'];
}
if ($excludedProviders) {
    $pnames = [];
    foreach ($excludedProviders as $pid) {
        if (isset($providers[$pid])) {
            $pnames[] = '−' . $providers[$pid];
        }
    }
    $filterChips[] = ['key' => 'provider-out', 'label' => $pnames ? implode(', ', array_slice($pnames, 0, 2)) : ('−' . count($excludedProviders)), 'clear' => ['without_watch_providers'], 'tone' => 'out'];
}
if ($selectedRatingFrom !== '' || $selectedRatingTo !== '') {
    $rFromLabel = $selectedRatingFrom !== '' ? rtrim(rtrim(number_format((float) $selectedRatingFrom, 1, '.', ''), '0'), '.') : '0';
    $rToLabel = $selectedRatingTo !== '' ? rtrim(rtrim(number_format((float) $selectedRatingTo, 1, '.', ''), '0'), '.') : '10';
    $ratingLabel = ($selectedRatingFrom !== '' && $selectedRatingTo !== '' && (float) $selectedRatingFrom === (float) $selectedRatingTo)
        ? ('★ ' . $rFromLabel)
        : ('★ ' . $rFromLabel . '–' . $rToLabel);
    $filterChips[] = ['key' => 'rating', 'label' => $ratingLabel, 'clear' => ['rating', 'rating_from', 'rating_to', 'vote_average_gte', 'vote_average.gte', 'vote_average_lte', 'vote_average.lte'], 'tone' => 'in'];
}
if ($sortChosen) {
    $filterChips[] = ['key' => 'sort', 'label' => $sorts[$selectedSort] ?? 'Sorted', 'clear' => ['sort_by'], 'tone' => 'in'];
}
$activeFilterCount = count($filterChips);

$chipUrl = static function (array $clearKeys) use ($path): string {
    $qs = $_GET;
    unset($qs['page'], $qs['genre_mode']);
    foreach ($clearKeys as $k) {
        unset($qs[$k], $qs[$k . '[]']);
    }
    foreach (['with_genres', 'without_genres', 'with_networks', 'without_networks', 'with_origin_country', 'without_origin_country', 'with_watch_providers', 'without_watch_providers'] as $arrKey) {
        if (in_array($arrKey, $clearKeys, true)) {
            unset($qs[$arrKey], $qs[$arrKey . '[]']);
        }
    }
    $q = http_build_query($qs);
    return url($path) . ($q !== '' ? ('?' . $q) : '');
};

$filterBubbles = static function (array $included, array $excluded, array $labels): array {
    $bubbles = [];
    foreach ($included as $id) {
        $key = (string) $id;
        $name = $labels[$key] ?? ($labels[(int) $id] ?? null);
        if ($name) {
            $bubbles[] = ['value' => $key, 'label' => $name, 'tone' => 'in'];
        }
    }
    foreach ($excluded as $id) {
        $key = (string) $id;
        $name = $labels[$key] ?? ($labels[(int) $id] ?? null);
        if ($name) {
            $bubbles[] = ['value' => $key, 'label' => $name, 'tone' => 'out'];
        }
    }
    return $bubbles;
};

$genreBubbles = $filterBubbles($selectedGenres, $excludedGenres, $genres);
$networkBubbles = $filterBubbles($selectedNetworks, $excludedNetworks, $networks);
$countryBubbles = $filterBubbles($selectedCountries, $excludedCountries, $countries);
$providerBubbles = $filterBubbles($selectedProviders, $excludedProviders, $providers);

$renderBubbles = static function (string $pickerId, array $bubbles): void {
    ?>
    <div class="filter-row-bubbles" data-summary-for="<?= e($pickerId) ?>"<?= $bubbles ? '' : ' hidden' ?>>
        <?php foreach ($bubbles as $b): ?>
        <button type="button" class="filter-bubble is-<?= e($b['tone']) ?>" data-picker="<?= e($pickerId) ?>" data-value="<?= e($b['value']) ?>" title="Clear">
            <span><?= e($b['label']) ?></span>
            <i class="uil uil-times" aria-hidden="true"></i>
        </button>
        <?php endforeach; ?>
    </div>
    <?php
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
                                <a class="filters-chip<?= ($chip['tone'] ?? '') === 'out' ? ' is-exclude' : '' ?>" href="<?= e($chipUrl($chip['clear'])) ?>">
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
                                    <p class="filters-hint">Tap once to include · twice to exclude</p>

                                    <div class="filter-home" id="filter-home">
                                        <div class="filter-rows">
                                            <div class="filter-row-block">
                                                <button type="button" class="filter-row" data-open-picker="picker-genre">
                                                    <span class="filter-row-icon"><i class="uil uil-apps" aria-hidden="true"></i></span>
                                                    <span class="filter-row-copy">
                                                        <strong>Genre</strong>
                                                        <em class="filter-row-hint">Add genres</em>
                                                    </span>
                                                    <i class="uil uil-angle-right filter-row-chevron" aria-hidden="true"></i>
                                                </button>
                                                <?php $renderBubbles('picker-genre', $genreBubbles); ?>
                                            </div>

                                            <?php if (!$isMovie): ?>
                                            <div class="filter-row-block">
                                                <button type="button" class="filter-row" data-open-picker="picker-network">
                                                    <span class="filter-row-icon"><i class="uil uil-rss" aria-hidden="true"></i></span>
                                                    <span class="filter-row-copy">
                                                        <strong>Network</strong>
                                                        <em class="filter-row-hint">Add networks</em>
                                                    </span>
                                                    <i class="uil uil-angle-right filter-row-chevron" aria-hidden="true"></i>
                                                </button>
                                                <?php $renderBubbles('picker-network', $networkBubbles); ?>
                                            </div>
                                            <?php endif; ?>

                                            <div class="filter-row-block">
                                                <button type="button" class="filter-row" data-open-picker="picker-country">
                                                    <span class="filter-row-icon"><i class="uil uil-globe" aria-hidden="true"></i></span>
                                                    <span class="filter-row-copy">
                                                        <strong>Country</strong>
                                                        <em class="filter-row-hint">Add countries</em>
                                                    </span>
                                                    <i class="uil uil-angle-right filter-row-chevron" aria-hidden="true"></i>
                                                </button>
                                                <?php $renderBubbles('picker-country', $countryBubbles); ?>
                                            </div>

                                            <div class="filter-row-block">
                                                <button type="button" class="filter-row" data-open-picker="picker-provider">
                                                    <span class="filter-row-icon"><i class="uil uil-play" aria-hidden="true"></i></span>
                                                    <span class="filter-row-copy">
                                                        <strong>Provider</strong>
                                                        <em class="filter-row-hint">Add providers</em>
                                                    </span>
                                                    <i class="uil uil-angle-right filter-row-chevron" aria-hidden="true"></i>
                                                </button>
                                                <?php $renderBubbles('picker-provider', $providerBubbles); ?>
                                            </div>
                                        </div>

                                        <section class="filter-section filter-section-inline">
                                            <h3 class="filter-section-title">Year</h3>
                                            <div class="range-timeline year-timeline" id="year-timeline" data-min="<?= (int) $yearMin ?>" data-max="<?= (int) $yearMax ?>" data-step="1">
                                                <div class="range-timeline-values">
                                                    <strong id="year-timeline-from-label"><?= (int) $timelineFrom ?></strong>
                                                    <span>to</span>
                                                    <strong id="year-timeline-to-label"><?= (int) $timelineTo ?></strong>
                                                </div>
                                                <div class="range-timeline-track">
                                                    <div class="range-timeline-rail" aria-hidden="true"></div>
                                                    <div class="range-timeline-fill" id="year-timeline-range" aria-hidden="true"></div>
                                                    <input type="range" class="range-timeline-thumb range-timeline-thumb-from" id="year-from-range" min="<?= (int) $yearMin ?>" max="<?= (int) $yearMax ?>" value="<?= (int) $timelineFrom ?>" step="1" aria-label="From year">
                                                    <input type="range" class="range-timeline-thumb range-timeline-thumb-to" id="year-to-range" min="<?= (int) $yearMin ?>" max="<?= (int) $yearMax ?>" value="<?= (int) $timelineTo ?>" step="1" aria-label="To year">
                                                </div>
                                                <div class="range-timeline-ticks" aria-hidden="true">
                                                    <?php for ($y = $yearMin; $y <= $yearMax; $y += 10): ?>
                                                    <span style="left: <?= (($y - $yearMin) / max(1, $yearMax - $yearMin)) * 100 ?>%"><?= $y ?></span>
                                                    <?php endfor; ?>
                                                </div>
                                                <input type="hidden" name="year_from" id="year-from" value="<?= e($selectedYearFrom) ?>">
                                                <input type="hidden" name="year_to" id="year-to" value="<?= e($selectedYearTo) ?>">
                                            </div>
                                        </section>

                                        <section class="filter-section filter-section-inline">
                                            <h3 class="filter-section-title">Rating</h3>
                                            <div class="range-timeline rating-timeline" id="rating-timeline" data-min="<?= (float) $ratingMin ?>" data-max="<?= (float) $ratingMax ?>" data-step="<?= (float) $ratingStep ?>">
                                                <div class="range-timeline-values">
                                                    <strong id="rating-timeline-from-label"><?= e(rtrim(rtrim(number_format($ratingTimelineFrom, 1, '.', ''), '0'), '.') ?: '0') ?></strong>
                                                    <span>to</span>
                                                    <strong id="rating-timeline-to-label"><?= e(rtrim(rtrim(number_format($ratingTimelineTo, 1, '.', ''), '0'), '.') ?: '0') ?></strong>
                                                </div>
                                                <div class="range-timeline-track">
                                                    <div class="range-timeline-rail" aria-hidden="true"></div>
                                                    <div class="range-timeline-fill" id="rating-timeline-range" aria-hidden="true"></div>
                                                    <input type="range" class="range-timeline-thumb range-timeline-thumb-from" id="rating-from-range" min="<?= (float) $ratingMin ?>" max="<?= (float) $ratingMax ?>" value="<?= (float) $ratingTimelineFrom ?>" step="<?= (float) $ratingStep ?>" aria-label="Minimum rating">
                                                    <input type="range" class="range-timeline-thumb range-timeline-thumb-to" id="rating-to-range" min="<?= (float) $ratingMin ?>" max="<?= (float) $ratingMax ?>" value="<?= (float) $ratingTimelineTo ?>" step="<?= (float) $ratingStep ?>" aria-label="Maximum rating">
                                                </div>
                                                <div class="range-timeline-ticks" aria-hidden="true">
                                                    <?php for ($r = 0; $r <= 10; $r += 2): ?>
                                                    <span style="left: <?= ($r / 10) * 100 ?>%"><?= $r ?></span>
                                                    <?php endfor; ?>
                                                </div>
                                                <input type="hidden" name="rating_from" id="rating-from" value="<?= e($selectedRatingFrom) ?>">
                                                <input type="hidden" name="rating_to" id="rating-to" value="<?= e($selectedRatingTo) ?>">
                                            </div>
                                        </section>

                                        <section class="filter-section filter-section-inline">
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

                                    <div class="filter-picker" id="picker-genre" hidden>
                                        <div class="filter-picker-head">
                                            <button type="button" class="filter-picker-back" aria-label="Back"><i class="uil uil-angle-left"></i></button>
                                            <div>
                                                <p class="filters-sheet-kicker">Choose genres</p>
                                                <h3>Genre</h3>
                                            </div>
                                        </div>
                                        <div class="filter-picker-body">
                                            <div class="filter-pills filter-pills-wrap" data-tri-group data-summary-target="picker-genre">
                                                <?php foreach ($genres as $gid => $gname):
                                                    $st = $triState((string) $gid, $selectedGenres, $excludedGenres);
                                                ?>
                                                <button type="button" class="filter-pill filter-tri is-<?= e($st) ?>" data-value="<?= (int) $gid ?>" data-include="with_genres[]" data-exclude="without_genres[]" aria-pressed="<?= $st === 'off' ? 'false' : 'true' ?>">
                                                    <span><?= e($gname) ?></span>
                                                </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="filter-picker-foot">
                                            <button type="button" class="filter-picker-done">Done</button>
                                        </div>
                                    </div>

                                    <?php if (!$isMovie): ?>
                                    <div class="filter-picker" id="picker-network" hidden>
                                        <div class="filter-picker-head">
                                            <button type="button" class="filter-picker-back" aria-label="Back"><i class="uil uil-angle-left"></i></button>
                                            <div>
                                                <p class="filters-sheet-kicker">Broadcast networks</p>
                                                <h3>Network</h3>
                                            </div>
                                        </div>
                                        <div class="filter-picker-body">
                                            <div class="filter-pills filter-pills-wrap" data-tri-group data-summary-target="picker-network">
                                                <?php foreach ($networks as $nid => $nname):
                                                    $st = $triState((string) $nid, $selectedNetworks, $excludedNetworks);
                                                ?>
                                                <button type="button" class="filter-pill filter-tri is-<?= e($st) ?>" data-value="<?= e($nid) ?>" data-include="with_networks[]" data-exclude="without_networks[]" aria-pressed="<?= $st === 'off' ? 'false' : 'true' ?>">
                                                    <span><?= e($nname) ?></span>
                                                </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="filter-picker-foot">
                                            <button type="button" class="filter-picker-done">Done</button>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <div class="filter-picker" id="picker-country" hidden>
                                        <div class="filter-picker-head">
                                            <button type="button" class="filter-picker-back" aria-label="Back"><i class="uil uil-angle-left"></i></button>
                                            <div>
                                                <p class="filters-sheet-kicker">Origin country</p>
                                                <h3>Country</h3>
                                            </div>
                                        </div>
                                        <div class="filter-picker-body">
                                            <div class="filter-pills filter-pills-wrap" data-tri-group data-summary-target="picker-country">
                                                <?php foreach ($countries as $code => $cname):
                                                    $st = $triState($code, $selectedCountries, $excludedCountries);
                                                ?>
                                                <button type="button" class="filter-pill filter-tri is-<?= e($st) ?>" data-value="<?= e($code) ?>" data-include="with_origin_country[]" data-exclude="without_origin_country[]" aria-pressed="<?= $st === 'off' ? 'false' : 'true' ?>">
                                                    <span><?= e($cname) ?></span>
                                                </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="filter-picker-foot">
                                            <button type="button" class="filter-picker-done">Done</button>
                                        </div>
                                    </div>

                                    <div class="filter-picker" id="picker-provider" hidden>
                                        <div class="filter-picker-head">
                                            <button type="button" class="filter-picker-back" aria-label="Back"><i class="uil uil-angle-left"></i></button>
                                            <div>
                                                <p class="filters-sheet-kicker">Streaming services</p>
                                                <h3>Provider</h3>
                                            </div>
                                        </div>
                                        <div class="filter-picker-body">
                                            <div class="filter-pills filter-pills-wrap" data-tri-group data-summary-target="picker-provider">
                                                <?php foreach ($providers as $pid => $pname):
                                                    $st = $triState((string) $pid, $selectedProviders, $excludedProviders);
                                                ?>
                                                <button type="button" class="filter-pill filter-tri is-<?= e($st) ?>" data-value="<?= e($pid) ?>" data-include="with_watch_providers[]" data-exclude="without_watch_providers[]" aria-pressed="<?= $st === 'off' ? 'false' : 'true' ?>">
                                                    <span><?= e($pname) ?></span>
                                                </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="filter-picker-foot">
                                            <button type="button" class="filter-picker-done">Done</button>
                                        </div>
                                    </div>

                                    <div id="filter-tri-inputs" hidden></div>
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
