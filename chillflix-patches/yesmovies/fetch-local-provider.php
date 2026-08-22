#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI helper: fetch one local player provider and print JSON.
 * Used by PlayerSources to overlap slow scrapers (e.g. Vidmoly) with others.
 *
 * Usage: php fetch-local-provider.php <provider> <type> <tmdbId> [season] [episode]
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$provider = strtolower(trim((string) ($argv[1] ?? '')));
$type = strtolower(trim((string) ($argv[2] ?? 'movie'))) === 'tv' ? 'tv' : 'movie';
$tmdbId = (int) ($argv[3] ?? 0);
$season = max(1, (int) ($argv[4] ?? 1));
$episode = max(1, (int) ($argv[5] ?? 1));

if ($provider === '' || $tmdbId < 1) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'usage', 'sources' => [], 'diagnostics' => []]));
    exit(2);
}

// Pretend vuflix host so config overrides match production player.
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'vuflix.co';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';

require dirname(__DIR__) . '/app/bootstrap-services.php';

$result = match ($provider) {
    'vidmoly' => class_exists('VidmolySources')
        ? VidmolySources::fetch($type, $tmdbId, $season, $episode)
        : ['ok' => false, 'error' => 'VidmolySources missing', 'sources' => [], 'diagnostics' => []],
    'cineplay', 'vidking' => class_exists('CineplaySources')
        ? CineplaySources::fetch($type, $tmdbId, $season, $episode)
        : ['ok' => false, 'error' => 'CineplaySources missing', 'sources' => [], 'diagnostics' => []],
    'upcloud', 'byse' => class_exists('ByseSources')
        ? ByseSources::fetch($type, $tmdbId, $season, $episode)
        : ['ok' => false, 'error' => 'ByseSources missing', 'sources' => [], 'diagnostics' => []],
    'stremify' => class_exists('StremifySources')
        ? StremifySources::fetch($type, $tmdbId, $season, $episode)
        : ['ok' => false, 'error' => 'StremifySources missing', 'sources' => [], 'diagnostics' => []],
    'nxsha', 'castle', 'awsind', 'nitro', 'riveprime' => class_exists('NxshaSources')
        ? NxshaSources::fetch($provider, $type, $tmdbId, $season, $episode)
        : ['ok' => false, 'error' => 'NxshaSources missing', 'sources' => [], 'diagnostics' => []],
    'hdghar' => class_exists('HdgharSources')
        ? HdgharSources::fetch($type, $tmdbId, $season, $episode)
        : ['ok' => false, 'error' => 'HdgharSources missing', 'sources' => [], 'diagnostics' => []],
    'moonflix' => class_exists('MoonflixSources')
        ? MoonflixSources::fetch($type, $tmdbId, $season, $episode)
        : ['ok' => false, 'error' => 'MoonflixSources missing', 'sources' => [], 'diagnostics' => []],
    'megasource' => class_exists('MegaSourceSources')
        ? MegaSourceSources::fetch($type, $tmdbId, $season, $episode)
        : ['ok' => false, 'error' => 'MegaSourceSources missing', 'sources' => [], 'diagnostics' => []],
    'opstream' => class_exists('OpStreamSources')
        ? OpStreamSources::fetch($type, $tmdbId, $season, $episode)
        : ['ok' => false, 'error' => 'OpStreamSources missing', 'sources' => [], 'diagnostics' => []],
    'torrentio' => class_exists('TorrentioCacheSources')
        ? TorrentioCacheSources::fetch($type, $tmdbId, $season, $episode)
        : ['ok' => false, 'error' => 'TorrentioCacheSources missing', 'sources' => [], 'diagnostics' => []],
    'yesmovies' => class_exists('YesMoviesSources')
        ? YesMoviesSources::fetch($type, $tmdbId, $season, $episode)
        : ['ok' => false, 'error' => 'YesMoviesSources missing', 'sources' => [], 'diagnostics' => []],
    'hollybox' => class_exists('HollyBoxSources')
        ? HollyBoxSources::fetch($type, $tmdbId, $season, $episode)
        : ['ok' => false, 'error' => 'HollyBoxSources missing', 'sources' => [], 'diagnostics' => []],
    default => ['ok' => false, 'error' => 'unsupported provider', 'sources' => [], 'diagnostics' => []],
};

fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES));
