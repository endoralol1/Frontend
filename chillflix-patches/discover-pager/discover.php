<?php
$isMovie = $type === 'movie';
$animeMode = !empty($animeMode);
$label = (isset($pageLabel) && $pageLabel !== '') ? (string) $pageLabel : ($isMovie ? t('discover.movies') : t('discover.tv_shows'));
$results = $data['results'] ?? [];
$totalPages = max(1, (int) ($data['total_pages'] ?? 1));
$path = (isset($pagePath) && $pagePath !== '') ? (string) $pagePath : ($isMovie ? '/movies' : '/tv-series');
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
$viewMode = 'grid';

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
$providerIcons = [
    '8' => ['mark' => 'N', 'tone' => 'netflix'],
    '9' => ['mark' => 'a', 'tone' => 'prime'],
    '337' => ['mark' => '+', 'tone' => 'disney'],
    '15' => ['mark' => 'h', 'tone' => 'hulu'],
    '384' => ['mark' => 'H', 'tone' => 'hbomax'],
    '350' => ['mark' => '', 'tone' => 'apple'],
    '531' => ['mark' => 'P', 'tone' => 'paramount'],
    '386' => ['mark' => 'p', 'tone' => 'peacock'],
];
$networkIcons = [
    '49' => ['mark' => 'H', 'tone' => 'hbo'],
    '67' => ['mark' => 'F', 'tone' => 'fox'],
    '56' => ['mark' => 'C', 'tone' => 'cn'],
    '6' => ['mark' => 'N', 'tone' => 'nbc'],
    '2' => ['mark' => 'A', 'tone' => 'abc'],
    '16' => ['mark' => 'C', 'tone' => 'cbs'],
    '318' => ['mark' => 'M', 'tone' => 'marvel'],
    '174' => ['mark' => 'A', 'tone' => 'amc'],
    '88' => ['mark' => 'X', 'tone' => 'fx'],
    '54' => ['mark' => 'D', 'tone' => 'disney'],
];
$countryFlag = static function (string $code): string {
    $code = strtoupper($code);
    if (strlen($code) !== 2 || !ctype_alpha($code)) {
        return '🌐';
    }
    return mb_chr(127397 + ord($code[0])) . mb_chr(127397 + ord($code[1]));
};
// TV broadcast networks only — streaming apps live under Provider (no duplicates)
$networks = [
    '49' => 'HBO', '67' => 'Fox', '56' => 'Cartoon Network',
    '6' => 'NBC', '2' => 'ABC', '16' => 'CBS', '318' => 'Marvel Studios',
    '174' => 'AMC', '88' => 'FX', '54' => 'Disney Channel',
];
$sorts = $isMovie
    ? [
        'popularity.desc' => t('discover.popularity'),
        'primary_release_date.desc' => t('discover.sort_release'),
        'vote_average.desc' => t('discover.sort_rating'),
        'vote_count.desc' => t('discover.sort_votes_count'),
        'revenue.desc' => t('discover.sort_revenue'),
        'original_title.asc' => t('discover.sort_title_az'),
    ]
    : [
        'popularity.desc' => t('discover.popularity'),
        'first_air_date.desc' => t('discover.sort_air_date'),
        'vote_average.desc' => t('discover.sort_rating'),
        'vote_count.desc' => t('discover.sort_votes_count'),
        'name.asc' => t('discover.sort_name_az'),
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

$chipUrl = static function (array $clearKeys) use ($path, $animeMode, $isMovie): string {
    $qs = $_GET;
    unset($qs['page'], $qs['genre_mode']);
    if ($animeMode && $isMovie) {
        $qs['type'] = 'movie';
    } elseif ($animeMode) {
        unset($qs['type']);
    }
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

$renderBubbles = static function (string $pickerId, array $bubbles) use ($countryFlag, $providerIcons, $networkIcons): void {
    ?>
    <div class="filter-row-bubbles" data-summary-for="<?= e($pickerId) ?>"<?= $bubbles ? '' : ' hidden' ?>>
        <?php foreach ($bubbles as $b):
            $lead = '';
            $toneClass = '';
            if ($pickerId === 'picker-country') {
                $lead = $countryFlag((string) $b['value']);
            } elseif ($pickerId === 'picker-provider' && isset($providerIcons[$b['value']])) {
                $lead = $providerIcons[$b['value']]['mark'];
                $toneClass = ' tone-' . $providerIcons[$b['value']]['tone'];
            } elseif ($pickerId === 'picker-network' && isset($networkIcons[$b['value']])) {
                $lead = $networkIcons[$b['value']]['mark'];
                $toneClass = ' tone-' . $networkIcons[$b['value']]['tone'];
            }
        ?>
        <button type="button" class="filter-bubble is-<?= e($b['tone']) ?>" data-picker="<?= e($pickerId) ?>" data-value="<?= e($b['value']) ?>" title="<?= e(t('common.clear')) ?>">
            <?php if ($lead !== '' || $toneClass !== ''): ?>
            <span class="filter-bubble-lead<?= e($toneClass) ?>" aria-hidden="true"><?= $pickerId === 'picker-country' ? $lead : e((string) $lead) ?></span>
            <?php endif; ?>
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
                            <?php if ($animeMode): ?>
                            <p class="discover-sub">Japanese anime only — no regular movies or TV</p>
                            <div class="anime-type-tabs" role="tablist" aria-label="<?= e(t('discover.anime_type')) ?>">
                                <a class="anime-type-tab<?= !$isMovie ? ' is-active' : '' ?>" href="<?= e(url($path)) ?>" role="tab" aria-selected="<?= !$isMovie ? 'true' : 'false' ?>"><?= e(t('discover.series')) ?></a>
                                <a class="anime-type-tab<?= $isMovie ? ' is-active' : '' ?>" href="<?= e(url($path) . '?type=movie') ?>" role="tab" aria-selected="<?= $isMovie ? 'true' : 'false' ?>"><?= e(t('nav.movies')) ?></a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form id="site-filters" class="filters-compact" method="get" action="<?= e(url($path)) ?>" autocomplete="off">
                        <?php if ($animeMode && $isMovie): ?>
                        <input type="hidden" name="type" value="movie">
                        <?php endif; ?>

                        <div class="filters-toolbar">
                            <button type="button" class="filters-open-btn" id="filters-open" aria-haspopup="dialog" aria-controls="filters-sheet" aria-expanded="false">
                                <i class="uil uil-filter" aria-hidden="true"></i>
                                <span><?= e(t('nav.filters')) ?></span>
                                <?php if ($activeFilterCount > 0): ?>
                                <span class="filters-count"><?= (int) $activeFilterCount ?></span>
                                <?php endif; ?>
                            </button>

                            <?php if ($filterChips): ?>
                            <div class="filters-chips" aria-label="<?= e(t('discover.active_filters')) ?>">
                                <?php foreach ($filterChips as $chip): ?>
                                <a class="filters-chip<?= ($chip['tone'] ?? '') === 'out' ? ' is-exclude' : '' ?>" href="<?= e($chipUrl($chip['clear'])) ?>">
                                    <span><?= e($chip['label']) ?></span>
                                    <i class="uil uil-times" aria-hidden="true"></i>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($activeFilterCount > 0): ?>
                            <a class="filters-reset-link" href="<?= e(url($path)) ?>"><?= e(t('discover.reset')) ?></a>
                            <?php endif; ?>
                        </div>

                        <div class="filters-sheet" id="filters-sheet" hidden>
                            <button type="button" class="filters-sheet-backdrop" aria-label="<?= e(t('discover.close_filters')) ?>"></button>
                            <div class="filters-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="filters-sheet-title">
                                <div class="filters-sheet-handle" aria-hidden="true"></div>
                                <div class="filters-sheet-head">
                                    <div>
                                        <p class="filters-sheet-kicker"><?= e(t('discover.refine')) ?></p>
                                        <h2 id="filters-sheet-title"><?= e(t('discover.filters')) ?></h2>
                                    </div>
                                    <button type="button" class="filters-sheet-close" aria-label="<?= e(t('discover.close_filters')) ?>"><i class="uil uil-times"></i></button>
                                </div>

                                <div class="filters-sheet-body">
                                    <p class="filters-hint"><?= e(t('discover.tap_hint')) ?></p>

                                    <div class="filter-home" id="filter-home">
                                        <div class="filter-rows">
                                            <div class="filter-row-block">
                                                <div class="filter-row-card">
                                                    <button type="button" class="filter-row" data-open-picker="picker-genre">
                                                        <span class="filter-row-icon"><i class="uil uil-apps" aria-hidden="true"></i></span>
                                                        <span class="filter-row-copy">
                                                            <strong><?= e(t('discover.genre')) ?></strong>
                                                            <em class="filter-row-hint"><?= e(t('discover.add_genres')) ?></em>
                                                        </span>
                                                        <i class="uil uil-angle-right filter-row-chevron" aria-hidden="true"></i>
                                                    </button>
                                                    <?php $renderBubbles('picker-genre', $genreBubbles); ?>
                                                </div>
                                            </div>

                                            <?php if (!$isMovie): ?>
                                            <div class="filter-row-block">
                                                <div class="filter-row-card">
                                                    <button type="button" class="filter-row" data-open-picker="picker-network">
                                                        <span class="filter-row-icon"><i class="uil uil-rss" aria-hidden="true"></i></span>
                                                        <span class="filter-row-copy">
                                                            <strong><?= e(t('discover.network')) ?></strong>
                                                            <em class="filter-row-hint"><?= e(t('discover.add_networks')) ?></em>
                                                        </span>
                                                        <i class="uil uil-angle-right filter-row-chevron" aria-hidden="true"></i>
                                                    </button>
                                                    <?php $renderBubbles('picker-network', $networkBubbles); ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>

                                            <div class="filter-row-block">
                                                <div class="filter-row-card">
                                                    <button type="button" class="filter-row" data-open-picker="picker-country">
                                                        <span class="filter-row-icon"><i class="uil uil-globe" aria-hidden="true"></i></span>
                                                        <span class="filter-row-copy">
                                                            <strong><?= e(t('discover.country')) ?></strong>
                                                            <em class="filter-row-hint"><?= e(t('discover.add_countries')) ?></em>
                                                        </span>
                                                        <i class="uil uil-angle-right filter-row-chevron" aria-hidden="true"></i>
                                                    </button>
                                                    <?php $renderBubbles('picker-country', $countryBubbles); ?>
                                                </div>
                                            </div>

                                            <div class="filter-row-block">
                                                <div class="filter-row-card">
                                                    <button type="button" class="filter-row" data-open-picker="picker-provider">
                                                        <span class="filter-row-icon"><i class="uil uil-play" aria-hidden="true"></i></span>
                                                        <span class="filter-row-copy">
                                                            <strong><?= e(t('discover.provider')) ?></strong>
                                                            <em class="filter-row-hint"><?= e(t('discover.add_providers')) ?></em>
                                                        </span>
                                                        <i class="uil uil-angle-right filter-row-chevron" aria-hidden="true"></i>
                                                    </button>
                                                    <?php $renderBubbles('picker-provider', $providerBubbles); ?>
                                                </div>
                                            </div>
                                        </div>

                                        <section class="filter-section filter-section-inline">
                                            <h3 class="filter-section-title"><?= e(t('discover.year')) ?></h3>
                                            <div class="range-timeline year-timeline" id="year-timeline" data-min="<?= (int) $yearMin ?>" data-max="<?= (int) $yearMax ?>" data-step="1">
                                                <div class="range-timeline-values">
                                                    <strong id="year-timeline-from-label"><?= (int) $timelineFrom ?></strong>
                                                    <span><?= e(t('discover.to')) ?></span>
                                                    <strong id="year-timeline-to-label"><?= (int) $timelineTo ?></strong>
                                                </div>
                                                <div class="range-timeline-track">
                                                    <div class="range-timeline-rail" aria-hidden="true"></div>
                                                    <div class="range-timeline-fill" id="year-timeline-range" aria-hidden="true"></div>
                                                    <input type="range" class="range-timeline-thumb range-timeline-thumb-from" id="year-from-range" min="<?= (int) $yearMin ?>" max="<?= (int) $yearMax ?>" value="<?= (int) $timelineFrom ?>" step="1" aria-label="<?= e(t('discover.from_year')) ?>">
                                                    <input type="range" class="range-timeline-thumb range-timeline-thumb-to" id="year-to-range" min="<?= (int) $yearMin ?>" max="<?= (int) $yearMax ?>" value="<?= (int) $timelineTo ?>" step="1" aria-label="<?= e(t('discover.to_year')) ?>">
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
                                            <h3 class="filter-section-title"><?= e(t('discover.rating')) ?></h3>
                                            <div class="range-timeline rating-timeline" id="rating-timeline" data-min="<?= (float) $ratingMin ?>" data-max="<?= (float) $ratingMax ?>" data-step="<?= (float) $ratingStep ?>">
                                                <div class="range-timeline-values">
                                                    <strong id="rating-timeline-from-label"><?= e(rtrim(rtrim(number_format($ratingTimelineFrom, 1, '.', ''), '0'), '.') ?: '0') ?></strong>
                                                    <span><?= e(t('discover.to')) ?></span>
                                                    <strong id="rating-timeline-to-label"><?= e(rtrim(rtrim(number_format($ratingTimelineTo, 1, '.', ''), '0'), '.') ?: '0') ?></strong>
                                                </div>
                                                <div class="range-timeline-track">
                                                    <div class="range-timeline-rail" aria-hidden="true"></div>
                                                    <div class="range-timeline-fill" id="rating-timeline-range" aria-hidden="true"></div>
                                                    <input type="range" class="range-timeline-thumb range-timeline-thumb-from" id="rating-from-range" min="<?= (float) $ratingMin ?>" max="<?= (float) $ratingMax ?>" value="<?= (float) $ratingTimelineFrom ?>" step="<?= (float) $ratingStep ?>" aria-label="<?= e(t('discover.min_rating')) ?>">
                                                    <input type="range" class="range-timeline-thumb range-timeline-thumb-to" id="rating-to-range" min="<?= (float) $ratingMin ?>" max="<?= (float) $ratingMax ?>" value="<?= (float) $ratingTimelineTo ?>" step="<?= (float) $ratingStep ?>" aria-label="<?= e(t('discover.max_rating')) ?>">
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
                                            <h3 class="filter-section-title"><?= e(t('discover.sort_by')) ?></h3>
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
                                            <button type="button" class="filter-picker-back" aria-label="<?= e(t('common.back')) ?>"><i class="uil uil-angle-left"></i></button>
                                            <div>
                                                <p class="filters-sheet-kicker"><?= e(t('discover.choose_genres')) ?></p>
                                                <h3><?= e(t('discover.genre')) ?></h3>
                                            </div>
                                        </div>
                                        <div class="filter-picker-body">
                                            <div class="filter-list" data-tri-group data-summary-target="picker-genre">
                                                <?php foreach ($genres as $gid => $gname):
                                                    $st = $triState((string) $gid, $selectedGenres, $excludedGenres);
                                                ?>
                                                <button type="button" class="filter-list-item filter-tri is-<?= e($st) ?>" data-value="<?= (int) $gid ?>" data-include="with_genres[]" data-exclude="without_genres[]" data-label="<?= e($gname) ?>" aria-pressed="<?= $st === 'off' ? 'false' : 'true' ?>">
                                                    <span class="filter-list-lead"><i class="uil uil-film" aria-hidden="true"></i></span>
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

                                    <?php if (!$isMovie): ?>
                                    <div class="filter-picker" id="picker-network" hidden>
                                        <div class="filter-picker-head">
                                            <button type="button" class="filter-picker-back" aria-label="<?= e(t('common.back')) ?>"><i class="uil uil-angle-left"></i></button>
                                            <div>
                                                <p class="filters-sheet-kicker"><?= e(t('discover.broadcast_networks')) ?></p>
                                                <h3><?= e(t('discover.network')) ?></h3>
                                            </div>
                                        </div>
                                        <div class="filter-picker-body">
                                            <div class="filter-list" data-tri-group data-summary-target="picker-network">
                                                <?php foreach ($networks as $nid => $nname):
                                                    $st = $triState((string) $nid, $selectedNetworks, $excludedNetworks);
                                                    $icon = $networkIcons[$nid] ?? ['mark' => 'TV', 'tone' => 'default'];
                                                ?>
                                                <button type="button" class="filter-list-item filter-tri is-<?= e($st) ?>" data-value="<?= e($nid) ?>" data-include="with_networks[]" data-exclude="without_networks[]" data-label="<?= e($nname) ?>" data-mark="<?= e($icon['mark']) ?>" data-tone="<?= e($icon['tone']) ?>" aria-pressed="<?= $st === 'off' ? 'false' : 'true' ?>">
                                                    <span class="filter-list-lead tone-<?= e($icon['tone']) ?>" aria-hidden="true"><?= e($icon['mark']) ?></span>
                                                    <span class="filter-list-label"><?= e($nname) ?></span>
                                                    <span class="filter-list-check" aria-hidden="true"><i class="uil uil-check"></i></span>
                                                </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="filter-picker-foot">
                                            <button type="button" class="filter-picker-done"><?= e(t('discover.done')) ?></button>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <div class="filter-picker" id="picker-country" hidden>
                                        <div class="filter-picker-head">
                                            <button type="button" class="filter-picker-back" aria-label="<?= e(t('common.back')) ?>"><i class="uil uil-angle-left"></i></button>
                                            <div>
                                                <p class="filters-sheet-kicker"><?= e(t('discover.origin_country')) ?></p>
                                                <h3><?= e(t('discover.country')) ?></h3>
                                            </div>
                                        </div>
                                        <div class="filter-picker-body">
                                            <div class="filter-list" data-tri-group data-summary-target="picker-country">
                                                <?php foreach ($countries as $code => $cname):
                                                    $st = $triState($code, $selectedCountries, $excludedCountries);
                                                    $flag = $countryFlag($code);
                                                ?>
                                                <button type="button" class="filter-list-item filter-tri is-<?= e($st) ?>" data-value="<?= e($code) ?>" data-include="with_origin_country[]" data-exclude="without_origin_country[]" data-label="<?= e($cname) ?>" data-flag="<?= e($flag) ?>" aria-pressed="<?= $st === 'off' ? 'false' : 'true' ?>">
                                                    <span class="filter-list-lead filter-list-flag" aria-hidden="true"><?= $flag ?></span>
                                                    <span class="filter-list-label"><?= e($cname) ?></span>
                                                    <span class="filter-list-check" aria-hidden="true"><i class="uil uil-check"></i></span>
                                                </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="filter-picker-foot">
                                            <button type="button" class="filter-picker-done"><?= e(t('discover.done')) ?></button>
                                        </div>
                                    </div>

                                    <div class="filter-picker" id="picker-provider" hidden>
                                        <div class="filter-picker-head">
                                            <button type="button" class="filter-picker-back" aria-label="<?= e(t('common.back')) ?>"><i class="uil uil-angle-left"></i></button>
                                            <div>
                                                <p class="filters-sheet-kicker"><?= e(t('discover.streaming_services')) ?></p>
                                                <h3><?= e(t('discover.provider')) ?></h3>
                                            </div>
                                        </div>
                                        <div class="filter-picker-body">
                                            <div class="filter-list" data-tri-group data-summary-target="picker-provider">
                                                <?php foreach ($providers as $pid => $pname):
                                                    $st = $triState((string) $pid, $selectedProviders, $excludedProviders);
                                                    $icon = $providerIcons[$pid] ?? ['mark' => '▶', 'tone' => 'default'];
                                                ?>
                                                <button type="button" class="filter-list-item filter-tri is-<?= e($st) ?>" data-value="<?= e($pid) ?>" data-include="with_watch_providers[]" data-exclude="without_watch_providers[]" data-label="<?= e($pname) ?>" data-mark="<?= e($icon['mark']) ?>" data-tone="<?= e($icon['tone']) ?>" aria-pressed="<?= $st === 'off' ? 'false' : 'true' ?>">
                                                    <span class="filter-list-lead tone-<?= e($icon['tone']) ?>" aria-hidden="true"><?= e($icon['mark']) ?></span>
                                                    <span class="filter-list-label"><?= e($pname) ?></span>
                                                    <span class="filter-list-check" aria-hidden="true"><i class="uil uil-check"></i></span>
                                                </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="filter-picker-foot">
                                            <button type="button" class="filter-picker-done"><?= e(t('discover.done')) ?></button>
                                        </div>
                                    </div>

                                    <div id="filter-tri-inputs" hidden></div>
                                </div>

                                <div class="filters-sheet-foot">
                                    <a class="filters-sheet-reset" href="<?= e(url($path)) ?>"><?= e(t('discover.reset_all')) ?></a>
                                    <button type="submit" class="filters-sheet-apply"><?= e(t('discover.show_results')) ?></button>
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
                        $qsBase = $_GET;
                        unset($qsBase['page']);
                        $pagerHref = static function (int $p) use ($qsBase): string {
                            $q = $qsBase;
                            $q['page'] = $p;
                            return '?' . http_build_query($q);
                        };
                        // Windowed page list: 1 … n-1 n [n+1] n+2 … last
                        $pagerPages = [];
                        $win = 2; // neighbors each side
                        $candidates = [1, $totalPages];
                        for ($i = $page - $win; $i <= $page + $win; $i++) {
                            if ($i >= 1 && $i <= $totalPages) {
                                $candidates[] = $i;
                            }
                        }
                        $candidates = array_values(array_unique($candidates));
                        sort($candidates, SORT_NUMERIC);
                        $prevN = 0;
                        foreach ($candidates as $n) {
                            if ($prevN && $n > $prevN + 1) {
                                $pagerPages[] = null; // ellipsis
                            }
                            $pagerPages[] = $n;
                            $prevN = $n;
                        }
                    ?>
                    <nav class="cf-pager" aria-label="<?= e(t('page.page')) ?>">
                        <div class="cf-pager-bar">
                            <?php if ($page > 1): ?>
                            <a class="cf-pager-btn cf-pager-edge" href="<?= e($pagerHref(1)) ?>" aria-label="<?= e(t('common.first') !== 'common.first' ? t('common.first') : 'First page') ?>" title="First">
                                <i class="uil uil-angle-double-left" aria-hidden="true"></i>
                            </a>
                            <a class="cf-pager-btn" href="<?= e($pagerHref($page - 1)) ?>" aria-label="<?= e(t('common.previous')) ?>">
                                <i class="uil uil-angle-left-b" aria-hidden="true"></i>
                            </a>
                            <?php else: ?>
                            <span class="cf-pager-btn cf-pager-edge is-disabled" aria-disabled="true"><i class="uil uil-angle-double-left" aria-hidden="true"></i></span>
                            <span class="cf-pager-btn is-disabled" aria-disabled="true"><i class="uil uil-angle-left-b" aria-hidden="true"></i></span>
                            <?php endif; ?>

                            <div class="cf-pager-pages" role="list">
                                <?php foreach ($pagerPages as $pn): ?>
                                    <?php if ($pn === null): ?>
                                    <span class="cf-pager-ellipsis" aria-hidden="true">…</span>
                                    <?php elseif ($pn === (int) $page): ?>
                                    <span class="cf-pager-num is-current" role="listitem" aria-current="page"><?= (int) $pn ?></span>
                                    <?php else: ?>
                                    <a class="cf-pager-num" role="listitem" href="<?= e($pagerHref((int) $pn)) ?>"><?= (int) $pn ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($page < $totalPages): ?>
                            <a class="cf-pager-btn" href="<?= e($pagerHref($page + 1)) ?>" aria-label="<?= e(t('common.next')) ?>">
                                <i class="uil uil-angle-right-b" aria-hidden="true"></i>
                            </a>
                            <a class="cf-pager-btn cf-pager-edge" href="<?= e($pagerHref($totalPages)) ?>" aria-label="Last page" title="Last">
                                <i class="uil uil-angle-double-right" aria-hidden="true"></i>
                            </a>
                            <?php else: ?>
                            <span class="cf-pager-btn is-disabled" aria-disabled="true"><i class="uil uil-angle-right-b" aria-hidden="true"></i></span>
                            <span class="cf-pager-btn cf-pager-edge is-disabled" aria-disabled="true"><i class="uil uil-angle-double-right" aria-hidden="true"></i></span>
                            <?php endif; ?>
                        </div>

                        <form class="cf-pager-jump" method="get" action="">
                            <?php foreach ($qsBase as $qk => $qv):
                                if (is_array($qv)) {
                                    foreach ($qv as $sub) {
                                        if ($sub === '' || $sub === null) continue;
                                        ?>
                            <input type="hidden" name="<?= e((string) $qk) ?>[]" value="<?= e((string) $sub) ?>">
                                        <?php
                                    }
                                    continue;
                                }
                                if ($qv === '' || $qv === null) continue;
                            ?>
                            <input type="hidden" name="<?= e((string) $qk) ?>" value="<?= e((string) $qv) ?>">
                            <?php endforeach; ?>
                            <label class="cf-pager-jump-label" for="cf-pager-goto"><?= e(t('page.page')) ?></label>
                            <input class="cf-pager-jump-input" id="cf-pager-goto" type="number" name="page" min="1" max="<?= (int) $totalPages ?>" value="<?= (int) $page ?>" inputmode="numeric" aria-label="<?= e(t('page.page')) ?>">
                            <span class="cf-pager-jump-of">/ <?= number_format((int) $totalPages) ?></span>
                            <button type="submit" class="cf-pager-jump-go"><?= e(t('search.go')) ?></button>
                        </form>
                    </nav>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
</main>
