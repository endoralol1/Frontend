<?php
declare(strict_types=1);

/**
 * Thin, clean sources client for newsite /player.
 * Pulls VAPlayer + Huhu from the main Chillflix API and normalizes the payload.
 * Also fetches external subtitle lists (VDRK + OpenSubtitles + optional Wyzie).
 */
final class PlayerSources
{
    /**
     * Enabled providers in admin playback order (no scrape).
     *
     * @return array{ok:bool,providers:list<array<string,mixed>>}
     */
    public static function listProviders(): array
    {
        $reveal = class_exists('Auth') && Auth::isStaff();
        $rows = [];
        try {
            if (class_exists('SourcesService')) {
                SourcesService::ensureCatalogRows();
                foreach (SourcesService::enabledOrdered() as $s) {
                    $id = (string) ($s['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    $public = (string) ($s['publicLabel'] ?? $s['name'] ?? $id);
                    $real = (string) ($s['name'] ?? $id);
                    $rows[] = [
                        'id' => $id,
                        'name' => $reveal ? $real : $public,
                        'publicLabel' => $public,
                        'realName' => $reveal ? $real : null,
                        'providerName' => $reveal ? $real : $public,
                        'autoLoad' => !empty($s['autoLoad']),
                        'autoloadNonEnglish' => !empty($s['autoloadNonEnglish']),
                        'scrapeTimeoutSec' => (int) ($s['scrapeTimeoutSec'] ?? 45),
                        'autoWaitSec' => (int) ($s['autoWaitSec'] ?? 15),
                    ];
                }
            }
        } catch (Throwable $e) {
            // fall through
        }
        if ($rows === []) {
            foreach ((array) config('player_providers', ['vaplayer', 'huhu']) as $id) {
                $id = strtolower(trim((string) $id));
                if ($id === '') {
                    continue;
                }
                $rows[] = [
                    'id' => $id,
                    'name' => ucfirst($id),
                    'publicLabel' => ucfirst($id),
                    'realName' => ucfirst($id),
                    'providerName' => ucfirst($id),
                ];
            }
        }
        return ['ok' => true, 'providers' => $rows];
    }

    /** @return array{ok:bool,sources?:list<array<string,mixed>>,subtitles?:list<array<string,mixed>>,error?:string,meta?:array<string,mixed>} */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1, ?string $onlyProvider = null): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        if ($tmdbId < 1) {
            return ['ok' => false, 'error' => 'Invalid tmdbId'];
        }

        $providers = config('player_providers', ['vaplayer', 'huhu']);
        // Config override (admin single-provider test) must win over DB order.
        $providerOverride = isset($GLOBALS['__cf_config_override'])
            && is_array($GLOBALS['__cf_config_override'])
            && array_key_exists('player_providers', $GLOBALS['__cf_config_override']);
        if (!$providerOverride) {
            try {
                if (class_exists('SourcesService')) {
                    $dbProviders = SourcesService::enabledIds();
                    if (is_array($dbProviders) && $dbProviders) {
                        $providers = $dbProviders;
                    }
                }
            } catch (Throwable $e) {
                // keep config fallback
            }
        }
        if (!is_array($providers) || !$providers) {
            $providers = ['vaplayer', 'huhu'];
        }

        // Progressive player: scrape only the requested provider.
        $onlyProvider = $onlyProvider !== null ? strtolower(trim($onlyProvider)) : '';
        if ($onlyProvider !== '') {
            $providers = [$onlyProvider];
        }

        $origin = rtrim((string) config('player_main_api', config('main_origin', 'https://www.chillflix.lol')), '/');
        $merged = [];
        $subtitles = [];
        $diagnostics = [];
        $playbackToken = null;

        // Normalize provider ids once.
        $providers = array_values(array_filter(array_map(
            static fn($p): string => strtolower(trim((string) $p)),
            $providers
        ), static fn(string $p): bool => $p !== ''));

        // Kick off cinepro remotes (VAPlayer, etc.) on local loopback immediately so they
        // overlap with slower local scrapers (Vidmoly / Cineplay) instead of waiting in line.
        $localProviderIds = ['stremify', 'nxsha', 'castle', 'awsind', 'nitro', 'riveprime', 'hdghar', 'moonflix', 'hollybox', 'moviebox', 'flixhqz', 'cinejoy', 'novahd', 'netmirror', 'megasource', 'opstream', 'torrentio', 'ridomovies', 'filesun', 'bingr', 'moviesonlinehd', 'vsembed', 'cineplay', 'vidking', 'vidmoly', 'upcloud', 'byse'];
        $remotePrefetch = self::startCineproPrefetch($providers, $localProviderIds, $type, $tmdbId, $season, $episode, $origin);

        // Overlap Vidmoly (often 0.5–0.8s) with Cineplay / remotes via a CLI child process.
        $bgLocal = self::startBackgroundLocalProvider(
            'vidmoly',
            $providers,
            $type,
            $tmdbId,
            $season,
            $episode
        );

        foreach ($providers as $provider) {
            if ($provider === '') {
                continue;
            }


            // VSEmbed — Vuflix-only: CinePro probe (TMDB → vsembed.ru HLS) via media-proxy (IP-bound CDN).
            if ($provider === 'vsembed') {
                $vs = self::fetchCineproProbeProvider('vsembed', $type, $tmdbId, $season, $episode);
                foreach ($vs['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($vs['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    // CDN JWTs are IP-bound to the VPS — proxy + rewrite playlists for browser playback.
                    $playUrl = $streamUrl;
                    if (class_exists('VidmolySources')) {
                        // No Referer/Origin: CDN /content segments get CF 403 with any Referer.
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
                        ], true);
                    } elseif (class_exists('CineplaySources')) {
                        $playUrl = CineplaySources::mintProxy($streamUrl, [], true);
                    }
                    $key = 'vsembed|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $lang = trim((string) ($src['language'] ?? ''));
                    if ($lang === '') {
                        $lang = 'en';
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => 'vsembed',
                        'providerName' => (string) ($src['providerName'] ?? 'VSEmbed'),
                        'label' => (string) ($src['label'] ?? ('VSEmbed' . (!empty($src['quality']) ? (' · ' . $src['quality']) : ''))),
                        'language' => $lang,
                        'hasEnglish' => true,
                        'audioTracks' => [],
                    ];
                }
                if (empty($vs['ok']) && empty($vs['sources'])) {
                    $diagnostics[] = [
                        'code' => 'VSEMBED_EMPTY',
                        'message' => (string) ($vs['error'] ?? 'No VSEmbed streams'),
                        'severity' => 'warning',
                        'provider' => 'vsembed',
                    ];
                }
                continue;
            }




            // CineSu — upstream redesigned (cine.su → glendale-plumbing CDN). Old
            // /v1/stream/master/{type}/{id}.m3u8 returns 404; new CDN times out from VPS.
            if ($provider === 'cinesu') {
                $diagnostics[] = [
                    'code' => 'CINESU_UPSTREAM_DEAD',
                    'message' => 'CineSu upstream changed; streams unavailable from this server.',
                    'severity' => 'warning',
                    'provider' => 'cinesu',
                ];
                continue;
            }

            // Flixhqz — CinePro scraper (flixhqz.com → IP-bound HLS). Not on allowlist, so remote
            // /api/cinepro/sources prefetch returns empty; probe locally + rewrite via media-proxy.
            if ($provider === 'flixhqz') {
                $fz = self::fetchCineproProbeProvider('flixhqz', $type, $tmdbId, $season, $episode);
                foreach ($fz['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($fz['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $rawUrl = (string) $src['url'];
                    $unwrapped = self::unwrapCineproProxyUrl($rawUrl);
                    $streamUrl = (string) ($unwrapped['url'] ?? $rawUrl);
                    $cdnHeaders = $unwrapped['headers'] ?? [];
                    if ($cdnHeaders === []) {
                        $cdnHeaders = [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                            'Referer' => 'https://cloudorchestranova.com/',
                            'Origin' => 'https://cloudorchestranova.com',
                        ];
                    }
                    $playUrl = $streamUrl;
                    $isHls = str_contains(strtolower((string) ($src['type'] ?? '')), 'hls')
                        || preg_match('/\.m3u8(\?|$)/i', $streamUrl);
                    // CDN JWTs are IP-bound to the VPS — must rewrite playlists for browser play.
                    if (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, (bool) $isHls);
                    } elseif (class_exists('CineplaySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = CineplaySources::mintProxy($streamUrl, $cdnHeaders, (bool) $isHls);
                    }
                    $key = 'flixhqz|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    $lang = trim((string) ($src['language'] ?? ''));
                    if ($lang === '') {
                        $qLower = strtolower($quality);
                        if (str_contains($qLower, 'english') || $qLower === 'en') {
                            $lang = 'en';
                        } elseif ($qLower !== '' && $qLower !== 'auto' && $qLower !== 'unknown') {
                            $lang = $qLower;
                        } else {
                            $lang = 'en';
                        }
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? ($isHls ? 'hls' : 'mp4')),
                        'quality' => $quality !== '' ? $quality : 'Auto',
                        'provider' => 'flixhqz',
                        'providerName' => (string) ($src['providerName'] ?? 'Flixhqz'),
                        'label' => (string) ($src['label'] ?? ('Flixhqz' . ($quality !== '' ? (' · ' . $quality) : ''))),
                        'language' => $lang,
                        'hasEnglish' => $lang === 'en' || str_contains(strtolower($quality), 'english'),
                        'audioTracks' => [],
                    ];
                }
                if (empty($fz['ok']) && empty($fz['sources'])) {
                    $diagnostics[] = [
                        'code' => 'FLIXHQZ_EMPTY',
                        'message' => (string) ($fz['error'] ?? 'No Flixhqz streams'),
                        'severity' => 'warning',
                        'provider' => 'flixhqz',
                    ];
                }
                continue;
            }

            // MovieBox — CinePro provider (moviebox.ph). Was broken when allowlist dropped it
            // and remote /api/cinepro/sources prefetch returned empty. Probe locally + media-proxy.
            if ($provider === 'moviebox') {
                $mb = self::fetchCineproProbeProvider('moviebox', $type, $tmdbId, $season, $episode);
                foreach ($mb['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($mb['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $rawUrl = (string) $src['url'];
                    $unwrapped = self::unwrapCineproProxyUrl($rawUrl);
                    $streamUrl = (string) ($unwrapped['url'] ?? $rawUrl);
                    $cdnHeaders = $unwrapped['headers'] ?? [
                        'Referer' => 'https://moviebox.ph/',
                        'Origin' => 'https://moviebox.ph',
                    ];
                    if (!isset($cdnHeaders['Referer'])) {
                        $cdnHeaders['Referer'] = 'https://moviebox.ph/';
                    }
                    if (!isset($cdnHeaders['Origin'])) {
                        $cdnHeaders['Origin'] = 'https://moviebox.ph';
                    }
                    $playUrl = $streamUrl;
                    if (class_exists('StremifySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = StremifySources::signedProxyUrl($streamUrl, $cdnHeaders);
                    } elseif (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, false);
                    }
                    $key = 'moviebox|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? 'mp4'),
                        'quality' => $quality,
                        'provider' => 'moviebox',
                        'providerName' => (string) ($src['providerName'] ?? 'MovieBox'),
                        'label' => (string) ($src['label'] ?? ('MovieBox' . ($quality !== '' ? (' · ' . $quality) : ''))),
                        'language' => (string) ($src['language'] ?? 'en'),
                        'hasEnglish' => true,
                        'audioTracks' => [],
                    ];
                }
                if (empty($mb['ok']) && empty($mb['sources'])) {
                    $diagnostics[] = [
                        'code' => 'MOVIEBOX_EMPTY',
                        'message' => (string) ($mb['error'] ?? 'No MovieBox streams'),
                        'severity' => 'warning',
                        'provider' => 'moviebox',
                    ];
                }
                continue;
            }

            // MoviesOnlineHD — Vuflix-only: hit CinePro probe endpoint directly (not Chillflix allowlist).
            if ($provider === 'moviesonlinehd') {
                $moh = self::fetchCineproProbeProvider('moviesonlinehd', $type, $tmdbId, $season, $episode);
                foreach ($moh['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($moh['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $key = 'moviesonlinehd|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'mp4'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => 'moviesonlinehd',
                        'providerName' => (string) ($src['providerName'] ?? 'MoviesOnlineHD'),
                        'label' => (string) ($src['label'] ?? ('MoviesOnlineHD' . (!empty($src['quality']) ? (' · ' . $src['quality']) : ''))),
                        'language' => (string) ($src['language'] ?? ''),
                        'audioTracks' => [],
                    ];
                }
                if (empty($moh['ok']) && empty($moh['sources'])) {
                    $diagnostics[] = [
                        'code' => 'MOVIESONLINEHD_EMPTY',
                        'message' => (string) ($moh['error'] ?? 'No MoviesOnlineHD streams'),
                        'severity' => 'warning',
                        'provider' => 'moviesonlinehd',
                    ];
                }
                continue;
            }


            // NovaHD — CinePro scraper (novahd.cc → IP-bound nova-edge HLS).
            // Probe locally + media-proxy playlist rewrite (CDN JWTs bind to VPS IP).

            // RidoMovies — closeload.top HLS (IP-friendly CDN; Referer required).

            // FileSuN — embed API → embedsun/vidmoly-family HLS.

            // Bingr — Sirius/HDGHAR-family demuxed HLS needs lang-proxy (not Vidmoly media-proxy).
            if ($provider === 'bingr') {
                $bg = self::fetchCineproProbeProvider('bingr', $type, $tmdbId, $season, $episode);
                foreach ($bg['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($bg['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $rawUrl = (string) $src['url'];
                    $unwrapped = self::unwrapCineproProxyUrl($rawUrl);
                    $streamUrl = (string) ($unwrapped['url'] ?? $rawUrl);
                    $cdnHeaders = $unwrapped['headers'] ?? [];
                    if ($cdnHeaders === []) {
                        $cdnHeaders = [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                            'Referer' => 'https://hdghartv.cc/',
                            'Origin' => 'https://hdghartv.cc',
                        ];
                    }
                    if (!isset($cdnHeaders['User-Agent'])) {
                        $cdnHeaders['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
                    }
                    if (!isset($cdnHeaders['Referer'])) {
                        $cdnHeaders['Referer'] = 'https://hdghartv.cc/';
                    }
                    if (!isset($cdnHeaders['Origin'])) {
                        $ref = (string) $cdnHeaders['Referer'];
                        $scheme = parse_url($ref, PHP_URL_SCHEME);
                        $host = parse_url($ref, PHP_URL_HOST);
                        $cdnHeaders['Origin'] = ($scheme && $host)
                            ? ($scheme . '://' . $host)
                            : 'https://hdghartv.cc';
                    }
                    $playUrl = $streamUrl;
                    $srcType = strtolower((string) ($src['type'] ?? ''));
                    $isDash = str_contains($srcType, 'dash')
                        || preg_match('/\.mpd(\?|$)/i', $streamUrl)
                        || str_contains(strtolower($streamUrl), '/dash/');
                    $isHls = (!$isDash) && (str_contains($srcType, 'hls')
                        || preg_match('/\.m3u8(\?|$)/i', $streamUrl));
                    // Same path as HDGHAR: lang-proxy rewrites masters, forces video/mp2t,
                    // and player.js enables tolerant HLS buffers for /lang-proxy URLs.
                    if ($isHls && class_exists('StreamLangProxy') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = StreamLangProxy::mintHlsHeaders($streamUrl, $cdnHeaders, 'eng');
                    } elseif (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, (bool) $isHls);
                    } elseif (class_exists('CineplaySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = CineplaySources::mintProxy($streamUrl, $cdnHeaders, (bool) $isHls);
                    }
                    if ($isDash) { /* keep dash through proxy wrappers */ }
                    $key = 'bingr|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    if ($quality === '') {
                        $quality = 'Auto';
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($isDash ? 'dash' : ($src['type'] ?? ($isHls ? 'hls' : 'mp4'))),
                        'quality' => $quality,
                        'provider' => 'bingr',
                        'providerName' => (string) ($src['providerName'] ?? 'Bingr'),
                        'label' => 'Bingr',
                        'language' => 'en',
                        'hasEnglish' => true,
                        'preferAudio' => 'en',
                        'audioTracks' => [
                            ['id' => 'en', 'label' => 'English', 'name' => 'English', 'language' => 'en', 'lang' => 'en'],
                            ['id' => 'hi', 'label' => 'Hindi', 'name' => 'Hindi', 'language' => 'hi', 'lang' => 'hi'],
                        ],
                    ];
                }
                if (empty($bg['ok']) && empty($bg['sources'])) {
                    $diagnostics[] = [
                        'code' => 'BINGR_EMPTY',
                        'message' => (string) ($bg['error'] ?? 'No Bingr streams'),
                        'severity' => 'warning',
                        'provider' => 'bingr',
                    ];
                }
                continue;
            }

            if ($provider === 'filesun') {
                $fs = self::fetchCineproProbeProvider('filesun', $type, $tmdbId, $season, $episode);
                foreach ($fs['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($fs['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $rawUrl = (string) $src['url'];
                    $unwrapped = self::unwrapCineproProxyUrl($rawUrl);
                    $streamUrl = (string) ($unwrapped['url'] ?? $rawUrl);
                    $cdnHeaders = $unwrapped['headers'] ?? [];
                    if ($cdnHeaders === []) {
                        $cdnHeaders = [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                            'Referer' => 'https://embedsun.cc/',
                            'Origin' => 'https://embedsun.cc',
                        ];
                    }
                    if (!isset($cdnHeaders['Referer'])) {
                        $cdnHeaders['Referer'] = 'https://embedsun.cc/';
                    }
                    if (!isset($cdnHeaders['Origin'])) {
                        $cdnHeaders['Origin'] = 'https://embedsun.cc';
                    }
                    $playUrl = $streamUrl;
                    $isHls = str_contains(strtolower((string) ($src['type'] ?? '')), 'hls')
                        || preg_match('/\.m3u8(\?|$)/i', $streamUrl);
                    if (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, (bool) $isHls);
                    } elseif (class_exists('CineplaySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = CineplaySources::mintProxy($streamUrl, $cdnHeaders, (bool) $isHls);
                    }
                    $key = 'filesun|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    if ($quality === '') {
                        $quality = 'Auto';
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? ($isHls ? 'hls' : 'mp4')),
                        'quality' => $quality,
                        'provider' => 'filesun',
                        'providerName' => (string) ($src['providerName'] ?? 'FileSuN'),
                        'label' => 'FileSuN',
                        'language' => 'en',
                        'hasEnglish' => true,
                        'audioTracks' => [],
                    ];
                }
                if (empty($fs['ok']) && empty($fs['sources'])) {
                    $diagnostics[] = [
                        'code' => 'FILESUN_EMPTY',
                        'message' => (string) ($fs['error'] ?? 'No FileSuN streams'),
                        'severity' => 'warning',
                        'provider' => 'filesun',
                    ];
                }
                continue;
            }

            if ($provider === 'ridomovies') {
                $rm = self::fetchCineproProbeProvider('ridomovies', $type, $tmdbId, $season, $episode);
                foreach ($rm['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($rm['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $rawUrl = (string) $src['url'];
                    $unwrapped = self::unwrapCineproProxyUrl($rawUrl);
                    $streamUrl = (string) ($unwrapped['url'] ?? $rawUrl);
                    $cdnHeaders = $unwrapped['headers'] ?? [];
                    if ($cdnHeaders === []) {
                        $cdnHeaders = [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                            'Referer' => 'https://closeload.top/',
                            'Origin' => 'https://closeload.top',
                        ];
                    }
                    if (!isset($cdnHeaders['Referer'])) {
                        $cdnHeaders['Referer'] = 'https://closeload.top/';
                    }
                    if (!isset($cdnHeaders['Origin'])) {
                        $cdnHeaders['Origin'] = 'https://closeload.top';
                    }
                    $playUrl = $streamUrl;
                    $isHls = str_contains(strtolower((string) ($src['type'] ?? '')), 'hls')
                        || preg_match('/\.(m3u8|txt)(\?|$)/i', $streamUrl);
                    if (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, (bool) $isHls);
                    } elseif (class_exists('CineplaySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = CineplaySources::mintProxy($streamUrl, $cdnHeaders, (bool) $isHls);
                    }
                    $key = 'ridomovies|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    if ($quality === '' || preg_match('/closeload|ridorapid|playmix/i', $quality)) {
                        $quality = 'Auto';
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? ($isHls ? 'hls' : 'mp4')),
                        'quality' => $quality,
                        'provider' => 'ridomovies',
                        'providerName' => (string) ($src['providerName'] ?? 'RidoMovies'),
                        'label' => 'RidoMovies',
                        'language' => 'en',
                        'hasEnglish' => true,
                        'audioTracks' => [],
                    ];
                }
                if (empty($rm['ok']) && empty($rm['sources'])) {
                    $diagnostics[] = [
                        'code' => 'RIDOMOVIES_EMPTY',
                        'message' => (string) ($rm['error'] ?? 'No RidoMovies streams'),
                        'severity' => 'warning',
                        'provider' => 'ridomovies',
                    ];
                }
                continue;
            }

            if ($provider === 'novahd') {
                $nh = self::fetchCineproProbeProvider('novahd', $type, $tmdbId, $season, $episode);
                foreach ($nh['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($nh['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $rawUrl = (string) $src['url'];
                    $unwrapped = self::unwrapCineproProxyUrl($rawUrl);
                    $streamUrl = (string) ($unwrapped['url'] ?? $rawUrl);
                    $cdnHeaders = $unwrapped['headers'] ?? [];
                    if ($cdnHeaders === []) {
                        $cdnHeaders = [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                            'Referer' => 'https://novahd.cc/',
                            'Origin' => 'https://novahd.cc',
                        ];
                    }
                    if (!isset($cdnHeaders['Referer'])) {
                        $cdnHeaders['Referer'] = 'https://novahd.cc/';
                    }
                    if (!isset($cdnHeaders['Origin'])) {
                        $cdnHeaders['Origin'] = 'https://novahd.cc';
                    }
                    $playUrl = $streamUrl;
                    $isHls = str_contains(strtolower((string) ($src['type'] ?? '')), 'hls')
                        || preg_match('/\.m3u8(\?|$)/i', $streamUrl)
                        || str_contains(strtolower($streamUrl), 'workers.dev');
                    if (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, (bool) $isHls);
                    } elseif (class_exists('CineplaySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = CineplaySources::mintProxy($streamUrl, $cdnHeaders, (bool) $isHls);
                    }
                    $key = 'novahd|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    // Real quality only (1080p/Auto). Server/edge names stay out of the Quality tab —
                    // CinePro already raced edges and returns the single fastest healthy stream.
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    if (preg_match('/vega|orion|raven|falcon|marlin|helios|ember/i', $quality)) {
                        $quality = 'Auto';
                    }
                    if ($quality === '') {
                        $quality = 'Auto';
                    }
                    $lang = trim((string) ($src['language'] ?? ''));
                    if ($lang === '') {
                        $lang = 'en';
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? ($isHls ? 'hls' : 'mp4')),
                        'quality' => $quality,
                        'provider' => 'novahd',
                        'providerName' => (string) ($src['providerName'] ?? 'NovaHD'),
                        'label' => 'NovaHD',
                        'language' => $lang,
                        'hasEnglish' => true,
                        'audioTracks' => [],
                    ];
                }
                if (empty($nh['ok']) && empty($nh['sources'])) {
                    $diagnostics[] = [
                        'code' => 'NOVAHD_EMPTY',
                        'message' => (string) ($nh['error'] ?? 'No NovaHD streams'),
                        'severity' => 'warning',
                        'provider' => 'novahd',
                    ];
                }
                continue;
            }

            if ($provider === 'netmirror') {
                if (!class_exists('NetMirrorSources')) {
                    $diagnostics[] = [
                        'code' => 'NETMIRROR_MISSING',
                        'message' => 'NetMirrorSources class not loaded',
                        'severity' => 'warning',
                        'provider' => 'netmirror',
                    ];
                    continue;
                }
                $nm = NetMirrorSources::fetch($type, $tmdbId, $season, $episode);
                foreach ($nm['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($nm['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $cdnHeaders = [];
                    if (is_array($src['headers'] ?? null)) {
                        foreach ($src['headers'] as $hk => $hv) {
                            $hk = trim((string) $hk);
                            $hv = trim((string) $hv);
                            if ($hk !== '' && $hv !== '') {
                                $cdnHeaders[$hk] = $hv;
                            }
                        }
                    }
                    if ($cdnHeaders === []) {
                        $cdnHeaders = [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                            'Referer' => 'https://net77.cc/',
                            'Origin' => 'https://net77.cc',
                        ];
                    }
                    $playUrl = $streamUrl;
                    if (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, true);
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    $key = 'netmirror|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => 'hls',
                        'quality' => $quality !== '' ? $quality : 'Auto',
                        'provider' => 'netmirror',
                        'providerName' => (string) ($src['providerName'] ?? 'NetMirror'),
                        'label' => (string) ($src['label'] ?? ('NetMirror · ' . $quality)),
                        'language' => 'en',
                        'hasEnglish' => true,
                        'audioTracks' => [],
                    ];
                }
                if (empty($nm['ok']) && empty($nm['sources'])) {
                    $diagnostics[] = [
                        'code' => 'NETMIRROR_EMPTY',
                        'message' => (string) ($nm['error'] ?? 'No NetMirror streams'),
                        'severity' => 'warning',
                        'provider' => 'netmirror',
                    ];
                }
                continue;
            }

            if ($provider === 'megasource') {
                if (!class_exists('MegaSourceSources')) {
                    $diagnostics[] = [
                        'code' => 'MEGASOURCE_MISSING',
                        'message' => 'MegaSourceSources class not loaded',
                        'severity' => 'warning',
                        'provider' => 'megasource',
                    ];
                    continue;
                }
                $ms = MegaSourceSources::fetch($type, $tmdbId, $season, $episode);
                foreach ($ms['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($ms['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $cdnHeaders = [];
                    if (is_array($src['headers'] ?? null)) {
                        foreach ($src['headers'] as $hk => $hv) {
                            $hk = trim((string) $hk);
                            $hv = trim((string) $hv);
                            if ($hk !== '' && $hv !== '') {
                                $cdnHeaders[$hk] = $hv;
                            }
                        }
                    }
                    if ($cdnHeaders === []) {
                        $cdnHeaders = [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
                            'Referer' => 'https://v1.watchplay.shop/',
                            'Origin' => 'https://v1.watchplay.shop',
                        ];
                    }
                    $playUrl = $streamUrl;
                    if (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, true);
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    $key = 'megasource|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => $quality !== '' ? $quality : 'Auto',
                        'provider' => 'megasource',
                        'providerName' => (string) ($src['providerName'] ?? 'MegaSource'),
                        'label' => (string) ($src['label'] ?? ('MegaSource · ' . $quality)),
                        'language' => (string) ($src['language'] ?? 'en'),
                        'hasEnglish' => !empty($src['hasEnglish']),
                        'audioTracks' => [],
                    ];
                }
                if (empty($ms['ok']) && empty($ms['sources'])) {
                    $diagnostics[] = [
                        'code' => 'MEGASOURCE_EMPTY',
                        'message' => (string) ($ms['error'] ?? 'No MegaSource streams'),
                        'severity' => 'warning',
                        'provider' => 'megasource',
                    ];
                }
                continue;
            }

            // OpStream = SpeedRace API (api.speedracelight.com) → peakstorm CDN.
            // Same backend as opstream.fun. Peakstorm 403s browser Origin — mint v-relay without Origin.
            if ($provider === 'opstream') {
                if (!class_exists('OpStreamSources')) {
                    $diagnostics[] = [
                        'code' => 'OPSTREAM_MISSING',
                        'message' => 'OpStreamSources class not loaded',
                        'severity' => 'warning',
                        'provider' => 'opstream',
                    ];
                    continue;
                }
                $os = OpStreamSources::fetch($type, $tmdbId, $season, $episode);
                foreach ($os['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($os['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $cdnHeaders = [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => '*/*',
                    ];
                    if (is_array($src['headers'] ?? null)) {
                        foreach ($src['headers'] as $hk => $hv) {
                            $hk = trim((string) $hk);
                            $hv = trim((string) $hv);
                            if ($hk === '' || $hv === '') {
                                continue;
                            }
                            // Peakstorm returns 403 when Origin is present (any site).
                            if (strcasecmp($hk, 'Origin') === 0) {
                                continue;
                            }
                            $cdnHeaders[$hk] = $hv;
                        }
                    }
                    unset($cdnHeaders['Origin'], $cdnHeaders['origin']);
                    $streamUrl = (string) $src['url'];
                    $playUrl = $streamUrl;
                    if (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, true);
                    }
                    $qualities = [];
                    foreach ($src['qualities'] ?? [] as $q) {
                        if (!is_array($q) || empty($q['url'])) {
                            continue;
                        }
                        $qUrl = (string) $q['url'];
                        $qPlay = $qUrl;
                        if (class_exists('VidmolySources') && preg_match('#^https?://#i', $qUrl)) {
                            $qPlay = VidmolySources::signedProxyUrl($qUrl, $cdnHeaders, true);
                        }
                        $qualities[] = [
                            'quality' => (string) ($q['quality'] ?? 'Auto'),
                            'url' => $qPlay,
                            'type' => (string) ($q['type'] ?? 'hls'),
                        ];
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    $key = 'opstream|' . ($qualities !== [] ? 'pack' : $streamUrl);
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => $quality !== '' ? $quality : 'Auto',
                        'provider' => 'opstream',
                        'providerName' => (string) ($src['providerName'] ?? 'OpStream'),
                        'label' => (string) ($src['label'] ?? ('OpStream · ' . $quality)),
                        'language' => (string) ($src['language'] ?? 'en'),
                        'hasEnglish' => !empty($src['hasEnglish']),
                        'audioTracks' => [],
                        'qualities' => $qualities,
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($os['ok']) && empty($os['sources'])) {
                    $diagnostics[] = [
                        'code' => 'OPSTREAM_EMPTY',
                        'message' => (string) ($os['error'] ?? 'No OpStream streams'),
                        'severity' => 'warning',
                        'provider' => 'opstream',
                    ];
                }
                continue;
            }

            // Torrentio cache = stream.torrentio.to pre-uploaded Byse embeds (VidPlay fast path).
            if ($provider === 'torrentio') {
                if (!class_exists('TorrentioCacheSources')) {
                    $diagnostics[] = [
                        'code' => 'TORRENTIO_MISSING',
                        'message' => 'TorrentioCacheSources class not loaded',
                        'severity' => 'warning',
                        'provider' => 'torrentio',
                    ];
                    continue;
                }
                $tc = TorrentioCacheSources::fetch($type, $tmdbId, $season, $episode);
                foreach ($tc['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($tc['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    $key = 'torrentio|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => $quality !== '' ? $quality : 'Auto',
                        'provider' => 'torrentio',
                        'providerName' => (string) ($src['providerName'] ?? 'Torrentio'),
                        'label' => (string) ($src['label'] ?? ('Torrentio · ' . $quality)),
                        'language' => (string) ($src['language'] ?? 'en'),
                        'hasEnglish' => !empty($src['hasEnglish']),
                        'audioTracks' => [],
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($tc['ok']) && empty($tc['sources'])) {
                    $diagnostics[] = [
                        'code' => 'TORRENTIO_EMPTY',
                        'message' => (string) ($tc['error'] ?? 'No Torrentio cache streams'),
                        'severity' => 'warning',
                        'provider' => 'torrentio',
                    ];
                }
                continue;
            }

            if ($provider === 'moonflix') {
                if (!class_exists('MoonflixSources')) {
                    $diagnostics[] = [
                        'code' => 'MOONFLIX_MISSING',
                        'message' => 'MoonflixSources class not loaded',
                        'severity' => 'warning',
                        'provider' => 'moonflix',
                    ];
                    continue;
                }
                $mf = MoonflixSources::fetch($type, $tmdbId, $season, $episode);
                foreach ($mf['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($mf['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $cdnHeaders = [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Referer' => 'https://hdghartv.cc/',
                        'Origin' => 'https://hdghartv.cc',
                    ];
                    if (is_array($src['headers'] ?? null)) {
                        foreach ($src['headers'] as $hk => $hv) {
                            $hk = trim((string) $hk);
                            $hv = trim((string) $hv);
                            if ($hk !== '' && $hv !== '') {
                                $cdnHeaders[$hk] = $hv;
                            }
                        }
                    }
                    $streamUrl = (string) $src['url'];
                    $playUrl = self::mintHdgharPlayUrl($streamUrl, $cdnHeaders);
                    $qualities = [];
                    foreach ($src['qualities'] ?? [] as $q) {
                        if (!is_array($q) || empty($q['url'])) {
                            continue;
                        }
                        $qualities[] = [
                            'quality' => (string) ($q['quality'] ?? 'Auto'),
                            'url' => self::mintHdgharPlayUrl((string) $q['url'], $cdnHeaders),
                            'type' => (string) ($q['type'] ?? 'hls'),
                        ];
                    }
                    $key = 'moonflix|' . ($qualities !== [] ? 'pack' : $streamUrl);
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $audioTracks = [];
                    foreach ($src['audioTracks'] ?? [] as $at) {
                        if (!is_array($at)) {
                            continue;
                        }
                        $audioTracks[] = [
                            'id' => (string) ($at['id'] ?? ''),
                            'label' => (string) ($at['label'] ?? $at['name'] ?? 'Audio'),
                            'name' => (string) ($at['name'] ?? $at['label'] ?? 'Audio'),
                            'language' => (string) ($at['language'] ?? $at['lang'] ?? ''),
                            'lang' => (string) ($at['lang'] ?? $at['language'] ?? ''),
                            'switchUrl' => (string) ($at['switchUrl'] ?? ''),
                        ];
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => 'moonflix',
                        'providerName' => (string) ($src['providerName'] ?? 'Moonflix'),
                        'label' => (string) ($src['label'] ?? 'Moonflix'),
                        'language' => (string) ($src['language'] ?? 'en'),
                        'hasEnglish' => true,
                        'preferAudio' => 'en',
                        'audioTracks' => $audioTracks,
                        'qualities' => $qualities,
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($mf['ok']) && empty($mf['sources'])) {
                    $diagnostics[] = [
                        'code' => 'MOONFLIX_EMPTY',
                        'message' => (string) ($mf['error'] ?? 'No Moonflix streams'),
                        'severity' => 'warning',
                        'provider' => 'moonflix',
                    ];
                }
                continue;
            }

            if ($provider === 'cinejoy') {
                $cj = self::fetchCineproProbeProvider('cinejoy', $type, $tmdbId, $season, $episode);
                foreach ($cj['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($cj['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $playUrl = $streamUrl;
                    // earthcleaner CDN requires cinejoy Referer — proxy + rewrite playlists.
                    if (class_exists('VidmolySources')) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
                            'Referer' => 'https://cinejoy.to/',
                            'Origin' => 'https://cinejoy.to',
                        ], true);
                    } elseif (class_exists('CineplaySources')) {
                        $playUrl = CineplaySources::mintProxy($streamUrl, [
                            'Referer' => 'https://cinejoy.to/',
                            'Origin' => 'https://cinejoy.to',
                        ], true);
                    }
                    $quality = (string) ($src['quality'] ?? '1080p');
                    $key = 'cinejoy|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => $quality,
                        'provider' => 'cinejoy',
                        'providerName' => '4K',
                        'label' => '4K' . ($quality !== '' && strcasecmp($quality, 'Auto') !== 0 ? (' · ' . $quality) : ''),
                        'language' => (string) ($src['language'] ?? 'en'),
                        'audioTracks' => [],
                    ];
                }
                if (empty($cj['ok']) && empty($cj['sources'])) {
                    $diagnostics[] = [
                        'code' => 'CINEJOY_EMPTY',
                        'message' => (string) ($cj['error'] ?? 'No Cinejoy streams'),
                        'severity' => 'warning',
                        'provider' => 'cinejoy',
                    ];
                }
                continue;
            }


            // Vuflix-only Railway aggregators (Nxsha / HDGHAR / HollyBox)
            if (class_exists('NxshaSources') && NxshaSources::supports($provider)) {
                if (!NxshaSources::hostAllowed()) {
                    $diagnostics[] = [
                        'code' => 'NXSHA_HOST_BLOCKED',
                        'message' => 'Nxsha scrapers are Vuflix-only',
                        'severity' => 'info',
                        'provider' => $provider,
                    ];
                    continue;
                }
                $nx = NxshaSources::fetch($provider, $type, $tmdbId, $season, $episode);
                foreach ($nx['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($nx['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $key = $provider . '|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $qualities = [];
                    foreach ($src['qualities'] ?? [] as $q) {
                        if (!is_array($q) || empty($q['url'])) {
                            continue;
                        }
                        $qualities[] = [
                            'quality' => (string) ($q['quality'] ?? 'Auto'),
                            'url' => (string) $q['url'],
                            'type' => (string) ($q['type'] ?? ($src['type'] ?? 'hls')),
                        ];
                    }
                    $audioTracks = [];
                    foreach ($src['audioTracks'] ?? [] as $at) {
                        if (!is_array($at)) {
                            continue;
                        }
                        $audioTracks[] = [
                            'id' => (string) ($at['id'] ?? ''),
                            'label' => (string) ($at['label'] ?? $at['name'] ?? 'Audio'),
                            'name' => (string) ($at['name'] ?? $at['label'] ?? 'Audio'),
                            'language' => (string) ($at['language'] ?? $at['lang'] ?? ''),
                            'lang' => (string) ($at['lang'] ?? $at['language'] ?? ''),
                            'switchUrl' => (string) ($at['switchUrl'] ?? $at['url'] ?? ''),
                            'url' => (string) ($at['url'] ?? $at['switchUrl'] ?? ''),
                        ];
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => (string) ($src['provider'] ?? $provider),
                        'providerName' => (string) ($src['providerName'] ?? ucfirst($provider)),
                        'label' => (string) ($src['label'] ?? ucfirst($provider)),
                        'language' => (string) ($src['language'] ?? 'en'),
                        'hasEnglish' => array_key_exists('hasEnglish', $src) ? (bool) $src['hasEnglish'] : true,
                        'preferAudio' => (string) ($src['preferAudio'] ?? 'en'),
                        'audioTracks' => $audioTracks,
                        'qualities' => $qualities,
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($nx['ok']) && empty($nx['sources'])) {
                    $diagnostics[] = [
                        'code' => 'NXSHA_EMPTY',
                        'message' => (string) ($nx['error'] ?? 'No Nxsha streams'),
                        'severity' => 'warning',
                        'provider' => $provider,
                    ];
                }
                continue;
            }
            if ($provider === 'hdghar') {
                if (!class_exists('HdgharSources') || !HdgharSources::hostAllowed()) {
                    $diagnostics[] = [
                        'code' => 'HDGHAR_HOST_BLOCKED',
                        'message' => 'HDGHAR is Vuflix-only',
                        'severity' => 'info',
                        'provider' => 'hdghar',
                    ];
                    continue;
                }
                $hg = HdgharSources::fetch($type, $tmdbId, $season, $episode);
                foreach ($hg['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($hg['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $cdnHeaders = [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Referer' => 'https://hdghartv.cc/',
                        'Origin' => 'https://hdghartv.cc',
                    ];
                    if (is_array($src['headers'] ?? null)) {
                        foreach ($src['headers'] as $hk => $hv) {
                            $hk = trim((string) $hk);
                            $hv = trim((string) $hv);
                            if ($hk !== '' && $hv !== '') {
                                $cdnHeaders[$hk] = $hv;
                            }
                        }
                    }
                    $streamUrl = (string) $src['url'];
                    $playUrl = self::mintHdgharPlayUrl($streamUrl, $cdnHeaders);
                    $qualities = [];
                    foreach ($src['qualities'] ?? [] as $q) {
                        if (!is_array($q) || empty($q['url'])) {
                            continue;
                        }
                        $qualities[] = [
                            'quality' => (string) ($q['quality'] ?? 'Auto'),
                            'url' => self::mintHdgharPlayUrl((string) $q['url'], $cdnHeaders),
                            'type' => (string) ($q['type'] ?? 'hls'),
                        ];
                    }
                    $key = 'hdghar|' . ($qualities !== [] ? 'pack' : $streamUrl);
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $audioTracks = [];
                    foreach ($src['audioTracks'] ?? [] as $at) {
                        if (!is_array($at)) {
                            continue;
                        }
                        $audioTracks[] = [
                            'id' => (string) ($at['id'] ?? ''),
                            'label' => (string) ($at['label'] ?? $at['name'] ?? 'Audio'),
                            'name' => (string) ($at['name'] ?? $at['label'] ?? 'Audio'),
                            'language' => (string) ($at['language'] ?? $at['lang'] ?? ''),
                            'lang' => (string) ($at['lang'] ?? $at['language'] ?? ''),
                            'switchUrl' => (string) ($at['switchUrl'] ?? ''),
                        ];
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => 'hdghar',
                        'providerName' => (string) ($src['providerName'] ?? 'HDGHAR'),
                        'label' => (string) ($src['label'] ?? 'HDGHAR'),
                        'language' => (string) ($src['language'] ?? 'en'),
                        'hasEnglish' => true,
                        'preferAudio' => 'en',
                        'audioTracks' => $audioTracks,
                        'qualities' => $qualities,
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($hg['ok']) && empty($hg['sources'])) {
                    $diagnostics[] = [
                        'code' => 'HDGHAR_EMPTY',
                        'message' => (string) ($hg['error'] ?? 'No HDGHAR streams'),
                        'severity' => 'warning',
                        'provider' => 'hdghar',
                    ];
                }
                continue;
            }
            if ($provider === 'hollybox') {
                if (!class_exists('HollyBoxSources') || !HollyBoxSources::hostAllowed()) {
                    $diagnostics[] = [
                        'code' => 'HOLLYBOX_HOST_BLOCKED',
                        'message' => 'HollyBox is Vuflix-only',
                        'severity' => 'info',
                        'provider' => 'hollybox',
                    ];
                    continue;
                }
                $hb = HollyBoxSources::fetch($type, $tmdbId, $season, $episode);
                foreach ($hb['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($hb['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $qualities = [];
                    foreach ($src['qualities'] ?? [] as $q) {
                        if (!is_array($q) || empty($q['url'])) {
                            continue;
                        }
                        $qualities[] = [
                            'quality' => (string) ($q['quality'] ?? 'Auto'),
                            'url' => (string) $q['url'],
                            'type' => (string) ($q['type'] ?? 'file'),
                        ];
                    }
                    $lang = (string) ($src['language'] ?? '');
                    $key = 'hollybox|' . $lang . '|' . ($qualities !== [] ? 'pack' : $streamUrl);
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $audioTracks = [];
                    foreach ($src['audioTracks'] ?? [] as $at) {
                        if (!is_array($at)) {
                            continue;
                        }
                        $audioTracks[] = [
                            'id' => (string) ($at['id'] ?? ''),
                            'label' => (string) ($at['label'] ?? $at['name'] ?? 'Audio'),
                            'name' => (string) ($at['name'] ?? $at['label'] ?? 'Audio'),
                            'language' => (string) ($at['language'] ?? $at['lang'] ?? ''),
                            'lang' => (string) ($at['lang'] ?? $at['language'] ?? ''),
                            'switchUrl' => (string) ($at['switchUrl'] ?? $at['url'] ?? ''),
                            'url' => (string) ($at['url'] ?? $at['switchUrl'] ?? ''),
                        ];
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'file'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => 'hollybox',
                        'providerName' => (string) ($src['providerName'] ?? 'HollyBox'),
                        'label' => (string) ($src['label'] ?? 'HollyBox'),
                        'language' => (string) ($src['language'] ?? ($lang !== '' ? $lang : 'en')),
                        'hasEnglish' => array_key_exists('hasEnglish', $src) ? (bool) $src['hasEnglish'] : ($lang === 'en' || $lang === 'english'),
                        'preferAudio' => 'en',
                        'audioTracks' => $audioTracks,
                        'qualities' => $qualities,
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($hb['ok']) && empty($hb['sources'])) {
                    $diagnostics[] = [
                        'code' => 'HOLLYBOX_EMPTY',
                        'message' => (string) ($hb['error'] ?? 'No HollyBox streams'),
                        'severity' => 'warning',
                        'provider' => 'hollybox',
                    ];
                }
                continue;
            }

            // Experimental Stremify (Hayduk) — Vuflix test provider
            if ($provider === 'stremify') {
                if (!class_exists('StremifySources') || !StremifySources::hostAllowed()) {
                    $diagnostics[] = [
                        'code' => 'STREMIFY_HOST_BLOCKED',
                        'message' => 'Stremify test provider is Vuflix-only',
                        'severity' => 'info',
                        'provider' => 'stremify',
                    ];
                    continue;
                }
                $sf = StremifySources::fetch($type, $tmdbId, $season, $episode);
                foreach ($sf['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($sf['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $key = 'stremify|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'file'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => 'stremify',
                        'providerName' => (string) ($src['providerName'] ?? 'Stremify'),
                        'label' => (string) ($src['label'] ?? 'Stremify'),
                        'language' => (string) ($src['language'] ?? ''),
                        'audioTracks' => [],
                    ];
                }
                if (empty($sf['ok']) && empty($sf['sources'])) {
                    $diagnostics[] = [
                        'code' => 'STREMIFY_EMPTY',
                        'message' => (string) ($sf['error'] ?? 'No Stremify streams'),
                        'severity' => 'warning',
                        'provider' => 'stremify',
                    ];
                }
                continue;
            }
            // Cineplay → Yoru HLS (one source, Quality tab variants)
            if ($provider === 'cineplay' || $provider === 'vidking') {
                if (!class_exists('CineplaySources')) {
                    $diagnostics[] = [
                        'code' => 'CINEPLAY_MISSING',
                        'message' => 'CineplaySources class not loaded',
                        'severity' => 'warning',
                        'provider' => 'cineplay',
                    ];
                    continue;
                }
                $cp = CineplaySources::fetch($type, $tmdbId, $season, $episode);
                foreach ($cp['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($cp['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $qualities = [];
                    foreach ($src['qualities'] ?? [] as $q) {
                        if (!is_array($q) || empty($q['url'])) {
                            continue;
                        }
                        $qualities[] = [
                            'quality' => (string) ($q['quality'] ?? 'Auto'),
                            'url' => (string) $q['url'],
                            'type' => (string) ($q['type'] ?? ($src['type'] ?? 'hls')),
                        ];
                    }
                    $key = 'cineplay|' . ($qualities !== [] ? 'yoru' : $streamUrl);
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => (string) ($src['quality'] ?? '1080p'),
                        'provider' => 'cineplay',
                        'providerName' => (string) ($src['providerName'] ?? 'Cineplay'),
                        'label' => (string) ($src['label'] ?? 'Cineplay · Yoru'),
                        'language' => (string) ($src['language'] ?? 'en'),
                        'audioTracks' => [],
                        'qualities' => $qualities,
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($cp['ok']) && empty($cp['sources'])) {
                    $diagnostics[] = [
                        'code' => 'CINEPLAY_EMPTY',
                        'message' => (string) ($cp['error'] ?? 'No Cineplay streams'),
                        'severity' => 'warning',
                        'provider' => 'cineplay',
                    ];
                }
                continue;
            }
            // HiMovies/0123movie → Vidmoly HLS (HTTP scrape)
            if ($provider === 'vidmoly') {
                if (!class_exists('VidmolySources') && $bgLocal === null) {
                    $diagnostics[] = [
                        'code' => 'VIDMOLY_MISSING',
                        'message' => 'VidmolySources class missing',
                        'severity' => 'warning',
                        'provider' => 'vidmoly',
                    ];
                    continue;
                }
                $vm = self::collectBackgroundLocalProvider($bgLocal);
                $bgLocal = null;
                if (!is_array($vm)) {
                    $vm = class_exists('VidmolySources')
                        ? VidmolySources::fetch($type, $tmdbId, $season, $episode)
                        : ['ok' => false, 'error' => 'VidmolySources missing', 'sources' => [], 'diagnostics' => []];
                }
                foreach ($vm['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($vm['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $key = 'vidmoly|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => 'vidmoly',
                        'providerName' => (string) ($src['providerName'] ?? 'Vidmoly'),
                        'label' => (string) ($src['label'] ?? 'Vidmoly'),
                        'language' => (string) ($src['language'] ?? ''),
                        'audioTracks' => [],
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($vm['ok']) && empty($vm['sources'])) {
                    $diagnostics[] = [
                        'code' => 'VIDMOLY_EMPTY',
                        'message' => (string) ($vm['error'] ?? 'No Vidmoly streams'),
                        'severity' => 'warning',
                        'provider' => 'vidmoly',
                    ];
                }
                continue;
            }
            // HiMovies UpCloud → Byse (gn1r5n) HLS via PoW helper
            if ($provider === 'upcloud' || $provider === 'byse') {
                if (!class_exists('ByseSources')) {
                    $diagnostics[] = [
                        'code' => 'UPCLOUD_MISSING',
                        'message' => 'ByseSources class missing',
                        'severity' => 'warning',
                        'provider' => 'upcloud',
                    ];
                    continue;
                }
                $uc = ByseSources::fetch($type, $tmdbId, $season, $episode);
                foreach ($uc['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($uc['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $key = 'upcloud|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => 'upcloud',
                        'providerName' => (string) ($src['providerName'] ?? 'UpCloud'),
                        'label' => (string) ($src['label'] ?? 'UpCloud'),
                        'language' => (string) ($src['language'] ?? ''),
                        'audioTracks' => [],
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($uc['ok']) && empty($uc['sources'])) {
                    $diagnostics[] = [
                        'code' => 'UPCLOUD_EMPTY',
                        'message' => (string) ($uc['error'] ?? 'No UpCloud/Byse streams'),
                        'severity' => 'warning',
                        'provider' => 'upcloud',
                    ];
                }
                continue;
            }
            $qs = [
                'type' => $type,
                'tmdbId' => (string) $tmdbId,
                'provider' => $provider,
            ];
            if ($type === 'tv') {
                $qs['season'] = (string) max(1, $season);
                $qs['episode'] = (string) max(1, $episode);
            }
            $url = $origin . '/api/cinepro/sources?' . http_build_query($qs);
            // Prefer the in-flight loopback prefetch (overlaps with Vidmoly/Cineplay).
            $payload = self::takeCineproPrefetch($remotePrefetch, $provider);
            if (!is_array($payload)) {
                $payload = self::httpGetJson($url, $origin);
            }
            if (!$payload) {
                $diagnostics[] = [
                    'code' => 'PROVIDER_FETCH_FAILED',
                    'message' => "Failed to fetch {$provider}",
                    'severity' => 'warning',
                    'provider' => $provider,
                ];
                continue;
            }
            if (!empty($payload['playbackToken']) && is_string($payload['playbackToken'])) {
                $playbackToken = $payload['playbackToken'];
            }
            foreach ($payload['diagnostics'] ?? [] as $d) {
                if (is_array($d)) {
                    $diagnostics[] = $d;
                }
            }
            foreach ($payload['sources'] ?? [] as $src) {
                if (!is_array($src) || empty($src['url'])) {
                    continue;
                }
                $provRaw = $src['provider'] ?? $provider;
                if (is_array($provRaw)) {
                    $pid = strtolower((string) ($provRaw['id'] ?? $provider));
                    $pname = (string) ($provRaw['name'] ?? ucfirst($pid));
                } else {
                    $pid = strtolower((string) ($provRaw ?: $provider));
                    $pname = ucfirst($pid);
                }
                $allowed = array_map('strtolower', $providers);
                if (!in_array($pid, $allowed, true) && !in_array($provider, $allowed, true)) {
                    continue;
                }
                if (!in_array($pid, $allowed, true)) {
                    $pid = $provider;
                }
                $streamUrl = self::absolutizeUrl((string) $src['url'], $origin);
                $key = $pid . '|' . $streamUrl;
                if (isset($merged[$key])) {
                    continue;
                }
                $quality = (string) ($src['quality'] ?? '');
                $name = $pname !== '' ? $pname : ucfirst($pid);
                $audioTracks = [];
                foreach ($src['audioTracks'] ?? [] as $at) {
                    if (!is_array($at)) {
                        continue;
                    }
                    $audioTracks[] = [
                        'id' => (string) ($at['id'] ?? ''),
                        'language' => (string) ($at['language'] ?? $at['lang'] ?? ''),
                        'label' => (string) ($at['label'] ?? $at['name'] ?? $at['language'] ?? 'Audio'),
                    ];
                }

                // Huhu exposes separate DE / EN resolves via ?lang=
                if ($pid === 'huhu') {
                    $huhuLangs = [
                        ['code' => 'de', 'label' => 'German'],
                        ['code' => 'en', 'label' => 'English'],
                    ];
                    foreach ($huhuLangs as $hl) {
                        $langUrl = self::rewriteHuhuToRequestHost(self::withQueryParam($streamUrl, 'lang', $hl['code']));
                        $langKey = $pid . '|' . $hl['code'] . '|' . $langUrl;
                        if (isset($merged[$langKey])) {
                            continue;
                        }
                        $deUrl = self::rewriteHuhuToRequestHost(self::withQueryParam($streamUrl, 'lang', 'de'));
                        $enUrl = self::rewriteHuhuToRequestHost(self::withQueryParam($streamUrl, 'lang', 'en'));
                        $merged[$langKey] = [
                            'id' => substr(sha1($langKey), 0, 12),
                            'provider' => $pid,
                            'providerName' => 'Huhu',
                            'label' => 'Huhu · ' . $hl['label'],
                            'quality' => $quality !== '' ? $quality : 'Auto',
                            'type' => (string) ($src['type'] ?? 'hls'),
                            'url' => $langUrl,
                            'language' => $hl['code'],
                            'audioTracks' => [
                                [
                                    'id' => 'huhu-de',
                                    'language' => 'de',
                                    'label' => 'German',
                                    'switchUrl' => $deUrl,
                                ],
                                [
                                    'id' => 'huhu-en',
                                    'language' => 'en',
                                    'label' => 'English',
                                    'switchUrl' => $enUrl,
                                ],
                            ],
                        ];
                    }
                    continue;
                }

                $label = trim($name . ($quality !== '' ? (' · ' . $quality) : ''));
                $merged[$key] = [
                    'id' => substr(sha1($key), 0, 12),
                    'provider' => $pid,
                    'providerName' => $name,
                    'label' => $label !== '' ? $label : $pid,
                    'quality' => $quality,
                    'type' => (string) ($src['type'] ?? 'hls'),
                    'url' => $streamUrl,
                    'audioTracks' => $audioTracks,
                ];
            }
            foreach ($payload['subtitles'] ?? [] as $sub) {
                if (!is_array($sub)) {
                    continue;
                }
                $srcUrl = (string) ($sub['src'] ?? $sub['url'] ?? $sub['file'] ?? '');
                if ($srcUrl === '') {
                    continue;
                }
                $sid = (string) ($sub['id'] ?? sha1($srcUrl));
                $subtitles[$sid] = [
                    'id' => $sid,
                    'label' => (string) ($sub['label'] ?? $sub['display'] ?? 'Subtitle'),
                    'language' => (string) ($sub['language'] ?? $sub['lang'] ?? ''),
                    'kind' => (string) ($sub['kind'] ?? 'subtitles'),
                    'type' => (string) ($sub['type'] ?? 'vtt'),
                    'src' => self::absolutizeUrl($srcUrl, $origin),
                ];
            }
        }

        self::closeCineproPrefetch($remotePrefetch);
        if ($bgLocal !== null) {
            self::collectBackgroundLocalProvider($bgLocal);
        }

        $sources = self::collapseProviderCandidates(array_values($merged), ['vaplayer']);
        if (!$sources) {
            return [
                'ok' => false,
                'error' => 'No playable sources right now.',
                'diagnostics' => array_values($diagnostics),
                'sources' => [],
                'subtitles' => [],
            ];
        }

        return [
            'ok' => true,
            'type' => $type,
            'tmdbId' => $tmdbId,
            'season' => $type === 'tv' ? max(1, $season) : null,
            'episode' => $type === 'tv' ? max(1, $episode) : null,
            'provider' => $onlyProvider !== '' ? $onlyProvider : null,
            'sources' => (class_exists('SourcesService')
                ? SourcesService::applyPublicLabels($sources, class_exists('Auth') && Auth::isStaff())
                : $sources),
            'subtitles' => array_values($subtitles),
            'playbackToken' => $playbackToken,
            'diagnostics' => array_values($diagnostics),
            'fetchedAt' => gmdate('c'),
        ];
    }

    /**
     * Collapse N streams from the same provider into one Source-tab entry.
     * Extra URLs go into candidates[] so the player can race the fastest.
     *
     * @param list<array<string,mixed>> $sources
     * @param list<string> $providerIds
     * @return list<array<string,mixed>>
     */
    private static function collapseProviderCandidates(array $sources, array $providerIds): array
    {
        $want = [];
        foreach ($providerIds as $id) {
            $want[strtolower(trim((string) $id))] = true;
        }
        $out = [];
        /** @var array<string,int> $indexByProvider */
        $indexByProvider = [];

        foreach ($sources as $src) {
            if (!is_array($src) || empty($src['url'])) {
                continue;
            }
            $pid = strtolower((string) ($src['provider'] ?? ''));
            if ($pid === '' || !isset($want[$pid])) {
                $out[] = $src;
                continue;
            }

            $cand = [
                'url' => (string) $src['url'],
                'quality' => (string) ($src['quality'] ?? ''),
                'type' => (string) ($src['type'] ?? 'hls'),
            ];

            if (!isset($indexByProvider[$pid])) {
                $src['candidates'] = [$cand];
                $src['quality'] = 'Auto';
                $indexByProvider[$pid] = count($out);
                $out[] = $src;
                continue;
            }

            $idx = $indexByProvider[$pid];
            $existing = $out[$idx]['candidates'] ?? [];
            if (!is_array($existing)) {
                $existing = [];
            }
            $dup = false;
            foreach ($existing as $e) {
                if (is_array($e) && (string) ($e['url'] ?? '') === $cand['url']) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $existing[] = $cand;
                $out[$idx]['candidates'] = $existing;
            }
        }

        return $out;
    }

    /**
     * External subtitle catalogue (VDRK + OpenSubtitles + optional Wyzie).
     * @return array{ok:bool,subtitles:list<array<string,mixed>>,error?:string}
     */
    public static function fetchSubtitles(string $type, int $tmdbId, ?string $imdbId = null, int $season = 1, int $episode = 1): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        if ($tmdbId < 1) {
            return ['ok' => false, 'subtitles' => [], 'error' => 'Invalid tmdbId'];
        }
        $subs = [];
        $season = max(1, $season);
        $episode = max(1, $episode);

        $imdb = $imdbId ? trim((string) $imdbId) : '';
        if ($imdb !== '' && !str_starts_with($imdb, 'tt')) {
            $imdb = 'tt' . preg_replace('/\D+/', '', $imdb);
        }
        if ($imdb === '' || !str_starts_with($imdb, 'tt')) {
            $imdb = self::resolveImdbId($type, $tmdbId);
        }

        // Wyzie (optional) — aggregates OpenSubtitles + others; needs WYZIE_API_KEY
        $wyzieKey = self::subtitleEnv('WYZIE_API_KEY');
        if ($wyzieKey !== '') {
            $q = [
                'id' => (string) $tmdbId,
                'key' => $wyzieKey,
                'format' => 'srt,vtt,ass',
            ];
            if ($type === 'tv') {
                $q['season'] = (string) $season;
                $q['episode'] = (string) $episode;
            }
            $wyzieUrl = 'https://sub.wyzie.io/search?' . http_build_query($q);
            $wyzie = self::httpGetJsonRaw($wyzieUrl, [
                'Accept: application/json',
                'User-Agent: ChillflixNewsitePlayer/1.0',
            ], null, 25);
            if (is_array($wyzie)) {
                foreach ($wyzie as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $file = trim((string) ($row['url'] ?? $row['file'] ?? $row['link'] ?? ''));
                    if ($file === '' || !preg_match('#^https?://#i', $file)) {
                        continue;
                    }
                    $lang = strtolower(trim((string) ($row['language'] ?? $row['lang'] ?? '')));
                    if ($lang === '' || strlen($lang) > 8) {
                        $lang = self::labelToLangCode((string) ($row['display'] ?? $row['label'] ?? ''));
                    } else {
                        $lang = substr(preg_replace('/[^a-z]/', '', $lang) ?? 'und', 0, 8) ?: 'und';
                    }
                    $label = trim((string) ($row['display'] ?? $row['label'] ?? ''));
                    if ($label === '') {
                        $label = strtoupper($lang);
                    }
                    $fmt = strtolower((string) ($row['format'] ?? 'srt')) ?: 'srt';
                    $id = 'wyzie:' . sha1($file);
                    if (isset($subs[$id])) {
                        continue;
                    }
                    $subs[$id] = [
                        'id' => $id,
                        'label' => $label,
                        'language' => $lang,
                        'type' => $fmt,
                        'src' => $file,
                        'source' => 'wyzie',
                    ];
                }
            }
        }

        // VDRK — ready-to-use VTT
        $vdrkUrl = $type === 'tv'
            ? "https://sub.vdrk.site/v1/tv/{$tmdbId}/{$season}/{$episode}"
            : "https://sub.vdrk.site/v1/movie/{$tmdbId}";
        $vdrk = self::httpGetJsonRaw($vdrkUrl);
        if (is_array($vdrk)) {
            foreach ($vdrk as $row) {
                if (!is_array($row) || empty($row['file']) || empty($row['label'])) {
                    continue;
                }
                $label = trim((string) $row['label']);
                $lang = self::labelToLangCode($label);
                $id = 'vdrk:' . sha1((string) $row['file']);
                $subs[$id] = [
                    'id' => $id,
                    'label' => $label,
                    'language' => $lang,
                    'type' => 'vtt',
                    'src' => (string) $row['file'],
                    'source' => 'granite',
                ];
            }
        }

        // OpenSubtitles legacy REST (needs imdb). Downloads require VLSub UA — see proxyCaption.
        if ($imdb !== '' && str_starts_with($imdb, 'tt')) {
            $path = 'https://rest.opensubtitles.org/search/';
            if ($type === 'tv') {
                $path .= 'episode-' . $episode . '/imdbid-' . substr($imdb, 2) . '/season-' . $season;
            } else {
                $path .= 'imdbid-' . substr($imdb, 2);
            }
            $os = self::httpGetJsonRaw($path, [
                'X-User-Agent: VLSub 0.10.2',
                'User-Agent: TemporaryUserAgent',
                'Accept: application/json',
            ]);
            if (is_array($os)) {
                $osCount = 0;
                foreach ($os as $row) {
                    if ($osCount >= 80) {
                        break;
                    }
                    if (!is_array($row) || empty($row['SubDownloadLink'])) {
                        continue;
                    }
                    $download = str_replace(
                        ['download/', '.gz'],
                        ['download/subencoding-utf8/', ''],
                        (string) $row['SubDownloadLink']
                    );
                    $langName = (string) ($row['LanguageName'] ?? '');
                    $lang = self::labelToLangCode($langName);
                    if ($download === '' || $lang === '') {
                        continue;
                    }
                    $id = 'os:' . sha1($download);
                    if (isset($subs[$id])) {
                        continue;
                    }
                    $subs[$id] = [
                        'id' => $id,
                        'label' => $langName !== '' ? $langName : strtoupper($lang),
                        'language' => $lang,
                        'type' => strtolower((string) ($row['SubFormat'] ?? 'srt')) ?: 'srt',
                        'src' => $download,
                        'source' => 'opensubs',
                    ];
                    $osCount++;
                }
            }
        }

        $list = array_values($subs);
        // Prefer common languages first; Wyzie slightly ahead of raw OS for same lang
        usort($list, static function (array $a, array $b): int {
            $prio = ['en' => 0, 'de' => 1, 'es' => 2, 'fr' => 3, 'it' => 4, 'pt' => 5, 'nl' => 6, 'hr' => 7];
            $pa = $prio[$a['language'] ?? ''] ?? 50;
            $pb = $prio[$b['language'] ?? ''] ?? 50;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            $srcRank = ['wyzie' => 0, 'granite' => 1, 'opensubs' => 2];
            $sa = $srcRank[$a['source'] ?? ''] ?? 9;
            $sb = $srcRank[$b['source'] ?? ''] ?? 9;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return strcasecmp((string) $a['label'], (string) $b['label']);
        });

        return [
            'ok' => true,
            'subtitles' => $list,
            'count' => count($list),
            'meta' => [
                'imdbId' => $imdb !== '' ? $imdb : null,
                'wyzie' => $wyzieKey !== '',
            ],
        ];
    }

    /** Proxy remote caption file as VTT text/vtt */
    public static function proxyCaption(string $url): void
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid url']);
            exit;
        }
        $headers = self::captionFetchHeaders($url);
        $raw = self::httpGetRaw($url, $headers, null, 30);
        if ($raw === null || $raw === '') {
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Caption fetch failed']);
            exit;
        }
        // OpenSubtitles may still return gzip bytes
        if (strlen($raw) >= 2 && ord($raw[0]) === 0x1f && ord($raw[1]) === 0x8b) {
            $unz = @gzdecode($raw);
            if (is_string($unz) && $unz !== '') {
                $raw = $unz;
            }
        }
        $trim = ltrim($raw);
        // Bot / challenge / HTML pages must not be painted as cues
        if ($trim === ''
            || str_starts_with($trim, '<!DOCTYPE')
            || str_starts_with($trim, '<!doctype')
            || str_starts_with($trim, '<html')
            || str_contains($trim, 'Making sure you&#39;re not a bot')
            || str_contains($trim, "Making sure you're not a bot")
        ) {
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Caption blocked by upstream']);
            exit;
        }
        $body = $raw;
        if (!str_starts_with($trim, 'WEBVTT')) {
            $body = self::srtToVtt($raw);
        }
        header('Content-Type: text/vtt; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Access-Control-Allow-Origin: *');
        echo $body;
        exit;
    }


    /** Headers that unlock OpenSubtitles downloads (legacy REST requires X-User-Agent). */
    private static function captionFetchHeaders(string $url): array
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if (str_contains($host, 'opensubtitles.org') || str_contains($host, 'opensubtitles.com')) {
            return [
                'X-User-Agent: VLSub 0.10.2',
                'User-Agent: TemporaryUserAgent',
                'Accept: text/plain, text/vtt, application/octet-stream, */*',
            ];
        }
        return [
            'User-Agent: ChillflixNewsitePlayer/1.0',
            'Accept: text/vtt, text/plain, */*',
        ];
    }

    private static function subtitleEnv(string $key): string
    {
        $v = getenv($key);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
        foreach ([
            dirname(__DIR__, 2) . '/.env',
            '/var/www/chillflix-newsite/.env',
        ] as $file) {
            if (!is_readable($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $val] = explode('=', $line, 2);
                if (trim($k) === $key) {
                    $val = trim($val, " \t\"'");
                    if ($val !== '') {
                        return $val;
                    }
                }
            }
        }
        return '';
    }

    private static function resolveImdbId(string $type, int $tmdbId): string
    {
        try {
            $endpoint = ($type === 'tv' ? 'tv/' : 'movie/') . $tmdbId . '/external_ids';
            $base = rtrim((string) config('tmdb_base', 'https://api.themoviedb.org/3'), '/');
            $key = (string) config('tmdb_api_key', '');
            if ($key === '') {
                return '';
            }
            $url = $base . '/' . $endpoint . '?api_key=' . rawurlencode($key);
            $data = self::httpGetJsonRaw($url, ['Accept: application/json'], null, 12);
            $imdb = trim((string) ($data['imdb_id'] ?? ''));
            if ($imdb !== '' && str_starts_with($imdb, 'tt')) {
                return $imdb;
            }
        } catch (Throwable $e) {
            // ignore
        }
        return '';
    }

    private static function srtToVtt(string $srt): string
    {
        $srt = preg_replace("/^\xEF\xBB\xBF/", '', $srt) ?? $srt;
        $srt = str_replace("\r\n", "\n", $srt);
        $srt = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $srt) ?? $srt;
        return "WEBVTT\n\n" . trim($srt) . "\n";
    }

    private static function labelToLangCode(string $label): string
    {
        $l = strtolower(trim($label));
        $l = preg_replace('/\s*hi\d*$/', '', $l) ?? $l;
        $l = preg_replace('/\d+$/', '', $l) ?? $l;
        $l = trim($l);
        $map = [
            'english' => 'en', 'german' => 'de', 'deutsch' => 'de', 'spanish' => 'es',
            'spanish (eu)' => 'es', 'spanish (la)' => 'es', 'french' => 'fr', 'italian' => 'it',
            'portuguese' => 'pt', 'dutch' => 'nl', 'russian' => 'ru', 'arabic' => 'ar',
            'turkish' => 'tr', 'polish' => 'pl', 'swedish' => 'sv', 'norwegian' => 'no',
            'danish' => 'da', 'finnish' => 'fi', 'greek' => 'el', 'hebrew' => 'he',
            'hindi' => 'hi', 'japanese' => 'ja', 'korean' => 'ko', 'chinese' => 'zh',
            'vietnamese' => 'vi', 'thai' => 'th', 'indonesian' => 'id', 'romanian' => 'ro',
            'hungarian' => 'hu', 'czech' => 'cs', 'slovak' => 'sk', 'ukrainian' => 'uk',
            'croatian' => 'hr', 'serbian' => 'sr', 'bulgarian' => 'bg', 'albanian' => 'sq',
            'estonian' => 'et', 'latvian' => 'lv', 'lithuanian' => 'lt', 'slovenian' => 'sl',
            'original' => 'orig', 'org' => 'orig',
        ];
        if (isset($map[$l])) {
            return $map[$l];
        }
        // "Spanish (LA)2" etc
        foreach ($map as $name => $code) {
            if (str_starts_with($l, $name)) {
                return $code;
            }
        }
        if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $l)) {
            return substr($l, 0, 2);
        }
        return $l !== '' ? substr(preg_replace('/[^a-z]/', '', $l) ?? 'und', 0, 8) : 'und';
    }

    private static function forceHttps(string $url): string
    {
        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }
        return $url;
    }

    private static function withQueryParam(string $url, string $key, string $value): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query[$key] = $value;
        $rebuilt = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '')
            . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '')
            . '?' . http_build_query($query);
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }
        return $rebuilt;
    }

    private static function absolutizeUrl(string $url, string $origin): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return self::forceHttps('https:' . $url);
        }
        if (str_starts_with($url, '/')) {
            return rtrim($origin, '/') . $url;
        }
        return self::forceHttps($url);
    }

    /** @return array<string,mixed>|list<mixed>|null */
    private static function httpGetJson(string $url, string $origin): ?array
    {
        return self::httpGetJsonRaw($url, [
            'Accept: application/json',
            'User-Agent: ChillflixNewsitePlayer/1.0',
            'Origin: ' . $origin,
            'Referer: ' . $origin . '/',
        ], $origin);
    }

    /**
     * @param list<string> $headers
     * @return array<string,mixed>|list<mixed>|null
     */
    private static function httpGetJsonRaw(string $url, array $headers = [], ?string $origin = null, int $timeoutSec = 45): ?array
    {
        $raw = self::httpGetRaw($url, $headers, $origin, $timeoutSec);
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @param list<string> $headers */
    private static function httpGetRaw(string $url, array $headers = [], ?string $origin = null, int $timeoutSec = 45): ?string
    {
        if (!$headers) {
            $headers = ['Accept: */*', 'User-Agent: ChillflixNewsitePlayer/1.0'];
        }
        $timeoutSec = max(5, $timeoutSec);

        // Same-machine Chillflix API: hit loopback first (skips public HTTPS ~100–200ms).
        if (str_contains($url, 'chillflix.lol')) {
            $loop = preg_replace('@^https?://[^/]+@', 'http://127.0.0.1:3000', $url) ?? $url;
            $hdrs = $headers;
            array_unshift($hdrs, 'Host: www.chillflix.lol');
            $ctxLoop = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeoutSec,
                    'header' => implode("\r\n", $hdrs),
                    'ignore_errors' => true,
                ],
            ]);
            $rawLoop = @file_get_contents($loop, false, $ctxLoop);
            if ($rawLoop !== false && $rawLoop !== '') {
                return $rawLoop;
            }
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSec,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                    'follow_location' => 1,
                    'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            return null;
        }
        return $raw;
    }

    /**
     * Start non-blocking curl_multi fetches for cinepro providers (VAPlayer, etc.).
     *
     * @param list<string> $providers
     * @param list<string> $localProviderIds
     * @return array{mh:?\CurlMultiHandle,handles:array<string,\CurlHandle>}
     */

    /**
     * Call CinePro single-provider endpoint with probe=true (on-demand scrapers).
     * Used for Vuflix-only providers that must not go through Chillflix enable gates.
     *
     * @return array{ok:bool,sources?:list<array<string,mixed>>,diagnostics?:list<array<string,mixed>>,error?:string}
     */

    /**
     * Decode CinePro loopback /v1/proxy?data=... into a direct URL + playback headers.
     *
     * @return array{url:?string,headers:array<string,string>}
     */
    private static function unwrapCineproProxyUrl(string $url): array
    {
        $empty = ['url' => null, 'headers' => []];
        if ($url === '' || !preg_match('#/v1/proxy\?#i', $url)) {
            return $empty;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['query'])) {
            return $empty;
        }
        parse_str((string) $parts['query'], $qs);
        $raw = $qs['data'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return $empty;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['url']) || !is_string($decoded['url'])) {
            return $empty;
        }
        $headers = [];
        if (isset($decoded['headers']) && is_array($decoded['headers'])) {
            foreach ($decoded['headers'] as $k => $v) {
                if (is_string($k) && (is_string($v) || is_numeric($v))) {
                    $headers[$k] = (string) $v;
                }
            }
        }
        return ['url' => (string) $decoded['url'], 'headers' => $headers];
    }

    private static function fetchCineproProbeProvider(
        string $providerId,
        string $type,
        int $tmdbId,
        int $season,
        int $episode
    ): array {
        $base = rtrim((string) (getenv('CINEPRO_URL') ?: 'http://127.0.0.1:3001'), '/');
        if ($type === 'tv') {
            $url = sprintf(
                '%s/v1/tv/%d/seasons/%d/episodes/%d/provider/%s?probe=true',
                $base,
                $tmdbId,
                max(1, $season),
                max(1, $episode),
                rawurlencode($providerId)
            );
        } else {
            $url = sprintf(
                '%s/v1/movies/%d/provider/%s?probe=true',
                $base,
                $tmdbId,
                rawurlencode($providerId)
            );
        }

        // Keep probes snappy: empty VSEmbed answers in ~3–5s; don't block the player for 80s+.
        // Other local probes (bingr/hdghar/…) stay at the default httpGetJsonRaw timeout.
        // VSEmbed origin often returns Cloudflare 521 — one retry with a longer budget.
        $probeTimeout = strtolower($providerId) === 'vsembed' ? 28 : 45;
        $payload = self::httpGetJsonRaw($url, [
            'Accept: application/json',
            'User-Agent: VuflixMoviesOnlineHD/1.0',
        ], null, $probeTimeout);

        $isVsembed = strtolower($providerId) === 'vsembed';
        if ($isVsembed && self::cineproProbeSoftFail($payload)) {
            usleep(600000);
            $payload = self::httpGetJsonRaw($url, [
                'Accept: application/json',
                'User-Agent: VuflixMoviesOnlineHD/1.0',
            ], null, $probeTimeout);
        }

        if (!is_array($payload)) {
            return [
                'ok' => false,
                'error' => 'CinePro probe fetch failed',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'PROVIDER_ERROR',
                    'message' => strtoupper($providerId) . ': probe fetch failed / timed out',
                    'severity' => 'warning',
                    'provider' => $providerId,
                ]],
            ];
        }
        if (!empty($payload['error'])) {
            $err = $payload['error'];
            $msg = is_array($err) ? (string) ($err['message'] ?? json_encode($err)) : (string) $err;
            return ['ok' => false, 'error' => $msg, 'sources' => [], 'diagnostics' => $payload['diagnostics'] ?? []];
        }

        $sources = [];
        foreach ($payload['sources'] ?? [] as $src) {
            if (!is_array($src) || empty($src['url'])) {
                continue;
            }
            $prov = $src['provider'] ?? null;
            if (is_array($prov)) {
                $pname = (string) ($prov['name'] ?? $providerId);
                $pid = (string) ($prov['id'] ?? $providerId);
            } else {
                $pname = $providerId;
                $pid = $providerId;
            }
            $sources[] = [
                'url' => (string) $src['url'],
                'type' => (string) ($src['type'] ?? 'mp4'),
                'quality' => (string) ($src['quality'] ?? 'Auto'),
                'provider' => $pid,
                'providerName' => $pname,
                'label' => $pname . (!empty($src['quality']) ? (' · ' . $src['quality']) : ''),
                'language' => '',
            ];
        }

        return [
            'ok' => $sources !== [],
            'sources' => $sources,
            'diagnostics' => is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : [],
            'error' => $sources === [] ? 'No streams returned' : null,
        ];
    }

    /** @param mixed $payload */
    private static function cineproProbeSoftFail($payload): bool
    {
        if (!is_array($payload)) {
            return true;
        }
        $err = $payload['error'] ?? null;
        $msg = is_array($err) ? (string) ($err['message'] ?? json_encode($err)) : (string) ($err ?? '');
        $diagBlob = '';
        foreach ($payload['diagnostics'] ?? [] as $d) {
            if (is_array($d)) {
                $diagBlob .= ' ' . ($d['code'] ?? '') . ' ' . ($d['message'] ?? '');
            }
        }
        $blob = $msg . $diagBlob;
        // Cloudflare 521/522/523 = origin down; 429/5xx = retryable.
        return (bool) preg_match('/\b521\b|\b522\b|\b523\b|\b429\b|HTTP\s*5\d\d|timed?\s*out|rate.?limit|ECONN|fetch failed|unavailable/i', $blob);
    }

    /** Keep Huhu stream URLs same-origin (vuflix proxies /api/huhu/ → chillflix). */
    private static function rewriteHuhuToRequestHost(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#/api/huhu/#i', $url)) {
            return $url;
        }
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        if ($host !== 'vuflix.co' && $host !== 'localhost' && $host !== '127.0.0.1') {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            return $url;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
        $reqHost = (string) ($_SERVER['HTTP_HOST'] ?? 'vuflix.co');
        $q = isset($parts['query']) ? ('?' . $parts['query']) : '';
        return $scheme . '://' . $reqHost . $parts['path'] . $q;
    }

    private static function startCineproPrefetch(
        array $providers,
        array $localProviderIds,
        string $type,
        int $tmdbId,
        int $season,
        int $episode,
        string $origin
    ): array {
        $empty = ['mh' => null, 'handles' => []];
        if (!function_exists('curl_multi_init')) {
            return $empty;
        }

        $mh = curl_multi_init();
        if ($mh === false) {
            return $empty;
        }

        $handles = [];
        foreach ($providers as $provider) {
            if (in_array($provider, $localProviderIds, true)) {
                continue;
            }
            $qs = [
                'type' => $type,
                'tmdbId' => (string) $tmdbId,
                'provider' => $provider,
            ];
            if ($type === 'tv') {
                $qs['season'] = (string) max(1, $season);
                $qs['episode'] = (string) max(1, $episode);
            }
            $publicUrl = rtrim($origin, '/') . '/api/cinepro/sources?' . http_build_query($qs);
            $loopUrl = preg_replace('@^https?://[^/]+@', 'http://127.0.0.1:3000', $publicUrl) ?? $publicUrl;

            $ch = curl_init($loopUrl);
            if ($ch === false) {
                continue;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: ChillflixNewsitePlayer/1.0',
                    'Host: www.chillflix.lol',
                    'Origin: ' . $origin,
                    'Referer: ' . $origin . '/',
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$provider] = $ch;
        }

        if ($handles === []) {
            curl_multi_close($mh);
            return $empty;
        }

        // Kick the event loop so requests leave the machine while we scrape locals.
        $running = 0;
        curl_multi_exec($mh, $running);

        return ['mh' => $mh, 'handles' => $handles];
    }

    /**
     * @param array{mh:?\CurlMultiHandle,handles:array<string,\CurlHandle>} $prefetch
     * @return array<string,mixed>|null
     */
    private static function takeCineproPrefetch(array &$prefetch, string $provider): ?array
    {
        $mh = $prefetch['mh'] ?? null;
        $handles = $prefetch['handles'] ?? [];
        if ($mh === null || !isset($handles[$provider])) {
            return null;
        }

        $ch = $handles[$provider];
        $running = 0;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $raw = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($prefetch['handles'][$provider]);

        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array{mh:?\CurlMultiHandle,handles:array<string,\CurlHandle>} $prefetch
     */
    private static function closeCineproPrefetch(array &$prefetch): void
    {
        $mh = $prefetch['mh'] ?? null;
        $handles = $prefetch['handles'] ?? [];
        if ($mh === null) {
            return;
        }
        foreach ($handles as $ch) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        $prefetch = ['mh' => null, 'handles' => []];
    }

    /**
     * Start a local provider scrape in a child PHP process (overlaps with other work).
     *
     * @param list<string> $providers
     * @return array{proc:resource,pipes:array<int,resource>}|null
     */
    private static function startBackgroundLocalProvider(
        string $provider,
        array $providers,
        string $type,
        int $tmdbId,
        int $season,
        int $episode
    ): ?array {
        if (!in_array($provider, $providers, true)) {
            return null;
        }
        // Only worth forking when another local scraper will run alongside it.
        $locals = array_values(array_intersect($providers, ['cineplay', 'vidking', 'vidmoly', 'upcloud', 'byse', 'stremify', 'nxsha', 'castle', 'awsind', 'nitro', 'riveprime', 'hdghar', 'hollybox']));
        if (count($locals) < 2) {
            return null;
        }

        $script = dirname(__DIR__, 2) . '/bin/fetch-local-provider.php';
        if (!is_readable($script)) {
            // Patched layout: app/Services → ../../bin from db-system root, or /var/www path.
            $alt = '/var/www/chillflix-newsite/bin/fetch-local-provider.php';
            $script = is_readable($alt) ? $alt : $script;
        }
        if (!is_readable($script)) {
            return null;
        }

        $cmd = [
            PHP_BINARY,
            $script,
            $provider,
            $type,
            (string) $tmdbId,
            (string) max(1, $season),
            (string) max(1, $episode),
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, dirname($script, 2), [
            'HTTP_HOST' => 'vuflix.co',
            'HTTPS' => 'on',
        ]);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /**
     * @param array{proc:resource,pipes:array<int,resource>}|null $bg
     * @return array<string,mixed>|null
     */
    private static function collectBackgroundLocalProvider(?array $bg): ?array
    {
        if ($bg === null || !isset($bg['proc'], $bg['pipes'])) {
            return null;
        }
        $stdout = '';
        $stderr = '';
        $pipes = $bg['pipes'];
        $proc = $bg['proc'];
        $deadline = microtime(true) + 12.0;
        while (true) {
            $status = proc_get_status($proc);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                @proc_terminate($proc, 15);
                break;
            }
            usleep(20000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $stdout = trim($stdout);
        if ($stdout === '') {
            return null;
        }
        // Child may accidentally print HTML noise — take the last JSON object.
        $jsonStart = strrpos($stdout, '{');
        if ($jsonStart === false) {
            return null;
        }
        $data = json_decode(substr($stdout, $jsonStart), true);
        return is_array($data) ? $data : null;
    }

    /**
     * HDGharTV / streamraiwind play URL.
     * streamraiwind already CORS-allows vuflix.co — return the CDN URL direct (same as
     * hdghartv). Proxying every chunk through a-relay made Moonflix stall/Fail on phones.
     *
     * @param array<string,string> $headers
     */
    private static function mintHdgharPlayUrl(string $url, array $headers): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $url;
        }
        // Already proxied.
        if (str_contains($url, '/api/player/a-relay') || str_contains($url, '/api/player/v-relay') || str_contains($url, 'lang-proxy')) {
            return $url;
        }
        // Direct CDN — player.js overrideMimeType fixes image/jpeg TS segments.
        if (preg_match('/streamraiwind\.stream/i', $url)) {
            return $url;
        }
        if (class_exists('StreamLangProxy') && preg_match('/\.m3u8(\?|$)/i', $url)) {
            return StreamLangProxy::mintHlsHeaders($url, $headers, 'eng');
        }
        if (class_exists('VidmolySources')) {
            return VidmolySources::signedProxyUrl($url, $headers, true);
        }
        return $url;
    }
}
