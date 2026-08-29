<?php
declare(strict_types=1);

/**
 * FzMovies (fzmovies.host) — progressive MP4/MKV download mirrors.
 *
 * Movies only for now: MobileTVshows sister site currently serves a
 * "downloads are not working" banner (no CDN URLs).
 *
 * Flow:
 *   1) TMDB title/year → search / direct movie-{Title}--hmp4.htm
 *   2) Pick quality rows (download1.php?downloadoptionskey=&pt=)
 *   3) download1 → download.php → mirror CDN URLs (try until Range works)
 *   4) Mint through v-relay (binary Range) with insecure TLS for CDN certs
 */
final class FzMoviesSources
{
    public const PROVIDER_ID = 'fzmovies';
    public const PROVIDER_NAME = 'FzMovies';

    private const SITE = 'https://fzmovies.host';
    private const UA =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

    /**
     * Resolve only the best quality for speed (each quality needs 2 site hops + CDN probe).
     * Player still gets a working stream; secondary qualities can be added later if needed.
     */
    private const MAX_QUALITIES = 1;

    /** Cache TMDB→page URL (page year verified) to skip slug/search on repeat plays. */
    private const PAGE_CACHE_TTL = 21600; // 6h

    /** Cache resolved CDN file URL (mirrors expire ~12h; keep shorter). */
    private const CDN_CACHE_TTL = 1800; // 30m

    /**
     * Internal proxy hint — stripped by VidmolySources before upstream fetch.
     * CDN hosts often ship expired TLS certs.
     */
    public const HEADER_INSECURE_TLS = 'X-Vuflix-Ssl';

    public static function hostAllowed(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        return $host === 'vuflix.co' || $host === 'localhost' || $host === '127.0.0.1';
    }

    /**
     * @return array{ok:bool,sources?:list<array<string,mixed>>,diagnostics?:list<array<string,mixed>>,error?:string}
     */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        $diagnostics = [];
        if (!self::hostAllowed()) {
            return [
                'ok' => false,
                'error' => 'FzMovies is Vuflix-only for now',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'FZMOVIES_HOST_BLOCKED',
                    'message' => 'FzMovies is Vuflix-only for now',
                    'severity' => 'info',
                    'provider' => self::PROVIDER_ID,
                ]],
            ];
        }
        if ($tmdbId < 1) {
            return ['ok' => false, 'error' => 'Invalid TMDB id', 'sources' => [], 'diagnostics' => []];
        }

        $type = $type === 'tv' ? 'tv' : 'movie';
        if ($type === 'tv') {
            return [
                'ok' => false,
                'error' => 'FzMovies TV downloads are currently offline upstream',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'FZMOVIES_TV_DISABLED',
                    'message' => 'mobiletvshows.site downloads unavailable',
                    'severity' => 'info',
                    'provider' => self::PROVIDER_ID,
                ]],
            ];
        }

        $meta = self::tmdbMeta($type, $tmdbId);
        if ($meta === null) {
            return [
                'ok' => false,
                'error' => 'No TMDB metadata',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'FZMOVIES_NO_TMDB',
                    'message' => 'TMDB details missing',
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ]],
            ];
        }

        $cookie = '';
        $titlePlain = self::titleForSlug($meta['title']);
        $year = $meta['year'];

        // Repeat-play fast path: cached page + CDN → probe only (skip scrape chain).
        $cached = self::pageCacheGet($titlePlain, $year);
        if ($cached !== null) {
            $cachedCdn = trim((string) ($cached['cdn'] ?? ''));
            $cachedQ = trim((string) ($cached['quality'] ?? 'Auto')) ?: 'Auto';
            if ($cachedCdn !== '' && preg_match('#^https?://#i', $cachedCdn)) {
                $probe = self::probeCdn($cachedCdn);
                if ($probe !== null) {
                    $playUrl = self::mint($cachedCdn, [
                        'Referer' => self::SITE . '/',
                        'Origin' => self::SITE,
                        'Accept' => '*/*',
                        'User-Agent' => self::UA,
                        self::HEADER_INSECURE_TLS => 'insecure',
                    ]);
                    $diagnostics[] = [
                        'code' => 'FZMOVIES_FAST',
                        'message' => 'Warm CDN path ' . $cachedQ,
                        'severity' => 'info',
                        'provider' => self::PROVIDER_ID,
                    ];
                    return [
                        'ok' => true,
                        'sources' => [[
                            'url' => $playUrl,
                            'type' => 'file',
                            'quality' => $cachedQ,
                            'provider' => self::PROVIDER_ID,
                            'providerName' => self::PROVIDER_NAME,
                            'label' => self::PROVIDER_NAME . ' · ' . $cachedQ,
                            'language' => 'en',
                            'hasEnglish' => true,
                            'qualities' => [['quality' => $cachedQ, 'url' => $playUrl, 'type' => 'file']],
                            'meta' => [
                                'backend' => 'fzmovies-host',
                                'page' => (string) ($cached['url'] ?? ''),
                                'pageYear' => $year,
                                'title' => $meta['title'],
                                'year' => $meta['year'],
                                'direct' => $cachedCdn,
                                'mirror' => (string) (parse_url($cachedCdn, PHP_URL_HOST) ?: ''),
                                'variants' => [$cachedQ],
                                'fast' => true,
                            ],
                        ]],
                        'diagnostics' => $diagnostics,
                    ];
                }
            }
        }

        $page = self::findMoviePage($meta, $cookie, $diagnostics);
        if ($page === null) {
            return [
                'ok' => false,
                'error' => 'Title not found on FzMovies',
                'sources' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $qualities = self::parseQualities($page['html']);
        if ($qualities === []) {
            $diagnostics[] = [
                'code' => 'FZMOVIES_NO_QUALITY',
                'message' => 'No download1 quality rows on ' . $page['url'],
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return [
                'ok' => false,
                'error' => 'No FzMovies quality options',
                'sources' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        // Prefer higher quality first; try a couple if the best mirrors are dead.
        usort($qualities, static fn($a, $b) => self::qualityRank($b['quality']) <=> self::qualityRank($a['quality']));
        $qualities = array_slice($qualities, 0, max(self::MAX_QUALITIES, 3));

        $resolved = [];
        foreach ($qualities as $q) {
            $cdn = null;
            $cacheKey = $page['url'] . '|' . $q['quality'];
            $cachedCdn = self::cdnCacheGet($cacheKey);
            if ($cachedCdn !== null) {
                $probe = self::probeCdn($cachedCdn);
                if ($probe !== null) {
                    $cdn = [
                        'url' => $cachedCdn,
                        'mirror' => (string) (parse_url($cachedCdn, PHP_URL_HOST) ?: ''),
                        'size' => $probe['size'],
                    ];
                    $diagnostics[] = [
                        'code' => 'FZMOVIES_CDN_CACHE',
                        'message' => 'CDN cache hit ' . $q['quality'],
                        'severity' => 'info',
                        'provider' => self::PROVIDER_ID,
                    ];
                }
            }
            if ($cdn === null) {
                $cdn = self::resolveQuality($q['href'], $page['url'], $cookie, $diagnostics);
                if ($cdn !== null) {
                    self::cdnCachePut($cacheKey, $cdn['url']);
                }
            }
            if ($cdn === null) {
                // Fall through to next listed quality if best mirror pack is dead.
                continue;
            }
            $playUrl = self::mint($cdn['url'], [
                'Referer' => self::SITE . '/',
                'Origin' => self::SITE,
                'Accept' => '*/*',
                'User-Agent' => self::UA,
                self::HEADER_INSECURE_TLS => 'insecure',
            ]);
            $resolved[] = [
                'quality' => $q['quality'],
                'label' => $q['label'],
                'url' => $playUrl,
                'type' => 'file',
                'direct' => $cdn['url'],
                'mirror' => $cdn['mirror'],
                'size' => $cdn['size'] ?? null,
            ];
            // First success is enough for playback latency.
            self::pageCachePut($titlePlain, $year, $page['url'], $cdn['url'], $q['quality']);
            break;
        }

        if ($resolved === []) {
            return [
                'ok' => false,
                'error' => 'FzMovies mirrors failed',
                'sources' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $best = $resolved[0];
        $qualityRows = [];
        foreach ($resolved as $r) {
            $qualityRows[] = [
                'quality' => $r['quality'],
                'url' => $r['url'],
                'type' => 'file',
            ];
        }

        $diagnostics[] = [
            'code' => 'FZMOVIES_OK',
            'message' => count($resolved) . ' quality/mirror packs for ' . $meta['title'],
            'severity' => 'info',
            'provider' => self::PROVIDER_ID,
        ];

        return [
            'ok' => true,
            'sources' => [[
                'url' => $best['url'],
                'type' => 'file',
                'quality' => $best['quality'],
                'provider' => self::PROVIDER_ID,
                'providerName' => self::PROVIDER_NAME,
                'label' => self::PROVIDER_NAME . ' · ' . $best['quality'],
                'language' => 'en',
                'hasEnglish' => true,
                'qualities' => $qualityRows,
                'meta' => [
                    'backend' => 'fzmovies-host',
                    'page' => $page['url'],
                    'pageYear' => self::pageYear($page['html']),
                    'title' => $meta['title'],
                    'year' => $meta['year'],
                    'direct' => $best['direct'],
                    'mirror' => $best['mirror'],
                    'variants' => array_map(static fn($r) => $r['quality'], $resolved),
                ],
            ]],
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param array{title:string,year:?int,imdb:?string} $meta
     * @param list<array<string,mixed>> $diagnostics
     * @return array{url:string,html:string}|null
     */
    private static function findMoviePage(array $meta, string &$cookie, array &$diagnostics): ?array
    {
        $title = $meta['title'];
        $year = $meta['year'];
        $titlePlain = self::titleForSlug($title);

        // Repeat-play cache (verified page URL).
        $cached = self::pageCacheGet($titlePlain, $year);
        $cachedUrl = is_array($cached) ? trim((string) ($cached['url'] ?? '')) : '';
        if ($cachedUrl !== '' && preg_match('#^https?://#i', $cachedUrl)) {
            $res = self::httpGet($cachedUrl, $cookie, self::SITE . '/');
            if ($res !== null && $res['status'] >= 200 && $res['status'] < 300
                && str_contains($res['body'], 'download1.php')
                && self::pageYearMatches($res['body'], $year)) {
                $diagnostics[] = [
                    'code' => 'FZMOVIES_CACHE',
                    'message' => 'Cache hit ' . $cachedUrl,
                    'severity' => 'info',
                    'provider' => self::PROVIDER_ID,
                ];
                return ['url' => $res['url'], 'html' => $res['body']];
            }
        }

        // Bare slug first (common case = 1 hop). Year check prevents remake collisions;
        // on mismatch try Title+Year next.
        $candidates = [];
        $candidates[] = 'movie-' . $titlePlain . '--hmp4.htm';
        if ($year !== null && !preg_match('/\b' . preg_quote((string) $year, '/') . '\b/', $titlePlain)) {
            $candidates[] = 'movie-' . $titlePlain . ' ' . $year . '--hmp4.htm';
        }

        foreach ($candidates as $directPath) {
            $directUrl = self::SITE . '/' . str_replace(' ', '%20', $directPath);
            $res = self::httpGet($directUrl, $cookie, self::SITE . '/');
            if ($res === null || $res['status'] < 200 || $res['status'] >= 300) {
                continue;
            }
            if (!str_contains($res['body'], 'download1.php')) {
                continue;
            }
            if (!self::pageYearMatches($res['body'], $year)) {
                $got = self::pageYear($res['body']);
                $diagnostics[] = [
                    'code' => 'FZMOVIES_YEAR_SKIP',
                    'message' => $directPath . ' year=' . ($got ?? 'null') . ' want=' . ($year ?? 'null'),
                    'severity' => 'info',
                    'provider' => self::PROVIDER_ID,
                ];
                continue;
            }
            $diagnostics[] = [
                'code' => 'FZMOVIES_DIRECT',
                'message' => 'Hit ' . $directPath . ' year=' . (self::pageYear($res['body']) ?? '?'),
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            self::pageCachePut($titlePlain, $year, $res['url']);
            return ['url' => $res['url'], 'html' => $res['body']];
        }

        // Search fallback.
        $post = http_build_query([
            'searchname' => $titlePlain,
            'searchby' => 'Name',
            'category' => 'All',
            'Search' => 'Search',
        ]);
        $search = self::httpPost(self::SITE . '/csearch.php', $post, $cookie, self::SITE . '/');
        if ($search === null || $search['status'] < 200 || $search['status'] >= 400) {
            $diagnostics[] = [
                'code' => 'FZMOVIES_SEARCH',
                'message' => 'csearch.php failed',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }

        $hits = [];
        if (preg_match_all('#href=["\'](movie-[^"\']+\.htm)["\']#i', $search['body'], $m)) {
            $seen = [];
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
                if (isset($seen[$href])) {
                    continue;
                }
                $seen[$href] = true;
                $hits[] = $href;
            }
        }
        if ($hits === []) {
            $diagnostics[] = [
                'code' => 'FZMOVIES_NO_HITS',
                'message' => 'No search hits for ' . $title,
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }

        $ranked = [];
        foreach ($hits as $href) {
            $score = self::slugScore($href, $titlePlain, $year);
            if ($score >= 70) {
                $ranked[] = [$score, $href];
            }
        }
        usort($ranked, static fn($a, $b) => $b[0] <=> $a[0]);
        if ($ranked === []) {
            $diagnostics[] = [
                'code' => 'FZMOVIES_NO_MATCH',
                'message' => 'No strong search match for ' . $title . ($year ? " ($year)" : ''),
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }

        // Fetch top candidates and require page year to match TMDB year.
        foreach (array_slice($ranked, 0, 6) as [$bestScore, $best]) {
            $pageUrl = self::SITE . '/' . str_replace(' ', '%20', ltrim($best, '/'));
            $res = self::httpGet($pageUrl, $cookie, self::SITE . '/');
            if ($res === null || $res['status'] < 200 || $res['status'] >= 300 || !str_contains($res['body'], 'download1.php')) {
                continue;
            }
            if (!self::pageYearMatches($res['body'], $year)) {
                $got = self::pageYear($res['body']);
                $diagnostics[] = [
                    'code' => 'FZMOVIES_YEAR_SKIP',
                    'message' => $best . ' year=' . ($got ?? 'null') . ' want=' . ($year ?? 'null') . ' score=' . $bestScore,
                    'severity' => 'info',
                    'provider' => self::PROVIDER_ID,
                ];
                continue;
            }
            $diagnostics[] = [
                'code' => 'FZMOVIES_MATCH',
                'message' => 'Matched ' . $best . ' score=' . $bestScore . ' year=' . (self::pageYear($res['body']) ?? '?'),
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            self::pageCachePut($titlePlain, $year, $res['url']);
            return ['url' => $res['url'], 'html' => $res['body']];
        }

        $diagnostics[] = [
            'code' => 'FZMOVIES_YEAR_MISS',
            'message' => 'No FzMovies page with matching year for ' . $title . ($year ? " ($year)" : ''),
            'severity' => 'warning',
            'provider' => self::PROVIDER_ID,
        ];
        return null;
    }

    /** Normalize TMDB title into fzmovies slug form (spaces, no colon). */
    private static function titleForSlug(string $title): string
    {
        $t = html_entity_decode($title, ENT_QUOTES | ENT_HTML5);
        $t = str_replace([':', '–', '—', '/', '\\'], ' ', $t);
        $t = preg_replace('/\s+/', ' ', $t) ?: $t;
        return trim($t);
    }

    /** Extract release year from an fzmovies movie page. */
    private static function pageYear(string $html): ?int
    {
        if (preg_match('/itemprop=[\'"]datePublished[\'"][^>]*content=[\'"](\d{4})/i', $html, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/year\.php\?[^\'"]*year=(\d{4})/i', $html, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/<title>\s*[^<(]+\((\d{4})\)\s*</i', $html, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * When TMDB year is known, require exact page year match.
     * If TMDB year unknown, accept any page.
     */
    private static function pageYearMatches(string $html, ?int $wantYear): bool
    {
        if ($wantYear === null || $wantYear < 1900) {
            return true;
        }
        $got = self::pageYear($html);
        if ($got === null) {
            // No year on page — reject when we have a TMDB year (avoid remake collisions).
            return false;
        }
        return $got === $wantYear;
    }

    /**
     * @return list<array{label:string,quality:string,href:string}>
     */
    private static function parseQualities(string $html): array
    {
        $out = [];
        if (!preg_match_all(
            '#href=["\'](download1\.php\?[^"\']+)["\'][^>]*>(.*?)</a>#is',
            $html,
            $m,
            PREG_SET_ORDER
        )) {
            return [];
        }
        $seen = [];
        foreach ($m as $row) {
            $href = html_entity_decode($row[1], ENT_QUOTES | ENT_HTML5);
            if (isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;
            $label = trim(html_entity_decode(strip_tags($row[2]), ENT_QUOTES | ENT_HTML5));
            $label = preg_replace('/\s+/', ' ', $label) ?: $label;
            if ($label === '' || strlen($label) > 160) {
                continue;
            }
            $out[] = [
                'label' => $label,
                'quality' => self::qualityFromLabel($label),
                'href' => $href,
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{url:string,mirror:string,size:?int}|null
     */
    private static function resolveQuality(string $download1Href, string $movieUrl, string &$cookie, array &$diagnostics): ?array
    {
        $d1 = self::absUrl($download1Href);
        $res = self::httpGet($d1, $cookie, $movieUrl);
        if ($res === null || $res['status'] < 200 || $res['status'] >= 300) {
            $diagnostics[] = [
                'code' => 'FZMOVIES_D1',
                'message' => 'download1 failed',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $html = $res['body'];
        if (!preg_match('#href=["\'](download\.php\?downloadkey=[^"\']+)["\']#i', $html, $m)) {
            $diagnostics[] = [
                'code' => 'FZMOVIES_DKEY',
                'message' => 'No downloadkey on download1 (key may have expired)',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $d2 = self::absUrl(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
        $res2 = self::httpGet($d2, $cookie, $d1);
        if ($res2 === null || $res2['status'] < 200 || $res2['status'] >= 300) {
            $diagnostics[] = [
                'code' => 'FZMOVIES_D2',
                'message' => 'download.php failed',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $html2 = $res2['body'];

        $mirrors = self::extractMirrors($html2);
        if ($mirrors === []) {
            $diagnostics[] = [
                'code' => 'FZMOVIES_NO_MIRROR',
                'message' => 'No CDN mirrors on download.php',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }

        $hit = self::probeCdnFirst($mirrors);
        if ($hit !== null) {
            return [
                'url' => $hit['url'],
                'mirror' => (string) (parse_url($hit['url'], PHP_URL_HOST) ?: ''),
                'size' => $hit['size'],
            ];
        }

        $diagnostics[] = [
            'code' => 'FZMOVIES_MIRROR_DEAD',
            'message' => 'All ' . count($mirrors) . ' mirrors returned non-media',
            'severity' => 'warning',
            'provider' => self::PROVIDER_ID,
        ];
        return null;
    }

    /**
     * @return list<string>
     */
    private static function extractMirrors(string $html): array
    {
        $urls = [];
        // Prefer explicit value= CDN fields (direct file URLs).
        if (preg_match_all("#value='(https://[^']+\.(?:mp4|mkv)[^']*)'#i", $html, $m)) {
            foreach ($m[1] as $u) {
                $urls[] = html_entity_decode($u, ENT_QUOTES | ENT_HTML5);
            }
        }
        // Also rebuild from dlink.php?id=res/...&sn=HOST
        if (preg_match_all("#href=['\"]dlink\.php\?id=([^'\"]+)['\"]#i", $html, $m)) {
            foreach ($m[1] as $q) {
                $q = html_entity_decode($q, ENT_QUOTES | ENT_HTML5);
                if (!preg_match('#^(res/.+\.(?:mp4|mkv)(?:\?[^&]*)?)&sn=([^&]+)#i', $q, $mm)) {
                    continue;
                }
                $path = $mm[1];
                $sn = $mm[2];
                if ($sn === '') {
                    continue;
                }
                $urls[] = 'https://' . $sn . '/' . $path;
            }
        }
        // Unique preserve order
        $seen = [];
        $out = [];
        foreach ($urls as $u) {
            if (!preg_match('#^https?://#i', $u) || isset($seen[$u])) {
                continue;
            }
            $seen[$u] = true;
            $out[] = $u;
        }
        return $out;
    }

    /**
     * Probe CDN mirrors in parallel; return the first that serves media bytes.
     *
     * @param list<string> $urls
     * @return array{url:string,size:?int}|null
     */
    private static function probeCdnFirst(array $urls): ?array
    {
        $urls = array_values(array_slice($urls, 0, 4));
        if ($urls === []) {
            return null;
        }
        if (count($urls) === 1 || !function_exists('curl_multi_init')) {
            foreach ($urls as $u) {
                $p = self::probeCdn($u);
                if ($p !== null) {
                    return ['url' => $u, 'size' => $p['size']];
                }
            }
            return null;
        }

        $mh = curl_multi_init();
        $map = [];
        foreach ($urls as $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: ' . self::UA,
                    'Referer: ' . self::SITE . '/',
                    'Range: bytes=0-1023',
                    'Accept: */*',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ]);
            curl_multi_add_handle($mh, $ch);
            $map[(int) $ch] = [$ch, $u];
        }

        $winner = null;
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            while ($info = curl_multi_info_read($mh)) {
                if ($winner !== null) {
                    continue;
                }
                $ch = $info['handle'];
                $id = (int) $ch;
                if (!isset($map[$id])) {
                    continue;
                }
                $u = $map[$id][1];
                $body = curl_multi_getcontent($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (is_string($body) && self::looksLikeMedia($body, $code)) {
                    $winner = ['url' => $u, 'size' => null];
                }
            }
            if ($running && $winner === null) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running > 0 && $status === CURLM_OK && $winner === null);

        foreach ($map as [$ch]) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $winner;
    }

    private static function looksLikeMedia(string $body, int $code): bool
    {
        if ($body === '' || ($code !== 200 && $code !== 206)) {
            return false;
        }
        if (str_contains($body, 'ftyp')
            || str_starts_with($body, "\x1A\x45\xDF\xA3")
            || (strlen($body) >= 8 && $body[4] === 'f' && str_contains(substr($body, 4, 8), 'ftyp'))) {
            return true;
        }
        return $code === 206 && strlen($body) >= 512;
    }

    /** @return array{size:?int}|null */
    private static function probeCdn(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return ['size' => null];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::UA,
                'Referer: ' . self::SITE . '/',
                'Range: bytes=0-1023',
                'Accept: */*',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || !self::looksLikeMedia($body, $code)) {
            return null;
        }
        return ['size' => null];
    }

    private static function cacheDir(): string
    {
        $dir = sys_get_temp_dir() . '/vuflix-fzmovies';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function pageCacheKey(string $titlePlain, ?int $year): string
    {
        return self::cacheDir() . '/page-' . sha1(strtolower($titlePlain) . '|' . ($year ?? 0)) . '.json';
    }

    /** @return array{url:string,cdn?:string,quality?:string}|null */
    private static function pageCacheGet(string $titlePlain, ?int $year): ?array
    {
        $path = self::pageCacheKey($titlePlain, $year);
        if (!is_file($path)) {
            return null;
        }
        $mtime = (int) @filemtime($path);
        if ($mtime < 1 || (time() - $mtime) > self::PAGE_CACHE_TTL) {
            return null;
        }
        $raw = @file_get_contents($path);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json)) {
            return null;
        }
        $url = trim((string) ($json['url'] ?? ''));
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        return $json;
    }

    private static function pageCachePut(
        string $titlePlain,
        ?int $year,
        string $url,
        ?string $cdn = null,
        ?string $quality = null
    ): void {
        if (!preg_match('#^https?://#i', $url)) {
            return;
        }
        $prev = self::pageCacheGet($titlePlain, $year) ?? [];
        $payload = [
            'url' => $url,
            't' => time(),
            'cdn' => $cdn ?? ($prev['cdn'] ?? null),
            'quality' => $quality ?? ($prev['quality'] ?? null),
        ];
        @file_put_contents(
            self::pageCacheKey($titlePlain, $year),
            json_encode($payload, JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private static function cdnCacheKey(string $key): string
    {
        return self::cacheDir() . '/cdn-' . sha1($key) . '.json';
    }

    private static function cdnCacheGet(string $key): ?string
    {
        $path = self::cdnCacheKey($key);
        if (!is_file($path)) {
            return null;
        }
        $mtime = (int) @filemtime($path);
        if ($mtime < 1 || (time() - $mtime) > self::CDN_CACHE_TTL) {
            return null;
        }
        $raw = @file_get_contents($path);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        $url = is_array($json) ? trim((string) ($json['url'] ?? '')) : '';
        return preg_match('#^https?://#i', $url) ? $url : null;
    }

    private static function cdnCachePut(string $key, string $url): void
    {
        if (!preg_match('#^https?://#i', $url)) {
            return;
        }
        @file_put_contents(
            self::cdnCacheKey($key),
            json_encode(['url' => $url, 't' => time()], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private static function qualityFromLabel(string $label): string
    {
        if (preg_match('/\b(2160p|4k|uhd)\b/i', $label)) {
            return '2160p';
        }
        if (preg_match('/\b1080p?\b/i', $label)) {
            return '1080p';
        }
        if (preg_match('/\b720p?\b/i', $label)) {
            return '720p';
        }
        if (preg_match('/\b480p?\b/i', $label)) {
            return '480p';
        }
        if (preg_match('/\b360p?\b/i', $label)) {
            return '360p';
        }
        if (preg_match('/\bhigh\b/i', $label)) {
            return '720p';
        }
        if (preg_match('/\blow\b/i', $label)) {
            return '480p';
        }
        return 'Auto';
    }

    private static function qualityRank(string $q): int
    {
        if (preg_match('/\b(2160p?|4k|uhd)\b/i', $q)) {
            return 2160;
        }
        if (preg_match('/\b1080p?\b/i', $q)) {
            return 1080;
        }
        if (preg_match('/\b720p?\b/i', $q)) {
            return 720;
        }
        if (preg_match('/\b480p?\b/i', $q)) {
            return 480;
        }
        if (preg_match('/\b360p?\b/i', $q)) {
            return 360;
        }
        return 0;
    }

    private static function slugScore(string $href, string $title, ?int $year): int
    {
        // movie-The Dark Knight--hmp4.htm → the dark knight
        $slug = $href;
        $slug = preg_replace('#^movie-#i', '', $slug) ?: $slug;
        $slug = preg_replace('#--hmp4\.htm$#i', '', $slug) ?: $slug;
        $slug = html_entity_decode($slug, ENT_QUOTES | ENT_HTML5);
        $slugNorm = self::norm($slug);
        $titleNorm = self::norm($title);
        if ($slugNorm === '' || $titleNorm === '') {
            return 0;
        }

        $score = 0;
        if ($slugNorm === $titleNorm) {
            $score += 120;
        } elseif (str_starts_with($slugNorm, $titleNorm . ' ')) {
            // "dune part two" vs "dune"
            $score += 85;
        } elseif ($titleNorm !== '' && str_contains($slugNorm, $titleNorm)) {
            $score += 70;
        } else {
            $a = array_values(array_filter(explode(' ', $titleNorm)));
            $b = array_values(array_filter(explode(' ', $slugNorm)));
            if ($a === []) {
                return 0;
            }
            $hit = count(array_intersect($a, $b));
            $score += (int) round(90 * ($hit / count($a)));
            if ($hit < max(2, count($a) - 1)) {
                return min($score, 50);
            }
            $stop = ['the', 'a', 'an', 'of', 'and'];
            $extra = array_values(array_filter(
                array_diff($b, $a, $stop),
                static fn($t) => !preg_match('/^\d{4}$/', $t)
            ));
            $score -= 15 * count($extra);
        }

        // Penalize longer spin-offs when looking for exact title.
        $titleToks = array_values(array_filter(
            explode(' ', $titleNorm),
            static fn($t) => $t !== '' && !in_array($t, ['the', 'a', 'an', 'of', 'and'], true)
        ));
        $slugToks = array_filter(explode(' ', $slugNorm));
        $missing = array_diff($titleToks, $slugToks);
        if ($missing !== []) {
            $score -= 25 * count($missing);
        }

        if ($year !== null) {
            $yearStr = (string) $year;
            if (str_contains($slugNorm, $yearStr) || str_contains($slug, $yearStr)) {
                $score += 40;
            } elseif (preg_match('/\b(19\d{2}|20\d{2})\b/', $slugNorm, $ym) && $ym[1] !== $yearStr) {
                // Wrong year in slug (e.g. Superman 2025 vs want 1978).
                $score -= 80;
            } else {
                // Bare slug with no year — weaker when TMDB year is known
                // (pageYearMatches will still gate the final pick).
                $score -= 10;
            }
        }

        // Soft-penalize Hindi/dubbed when English title requested.
        if (preg_match('/\[hindi\]|hindi|dual audio|dubbed/i', $slug)) {
            $score -= 20;
        }

        return $score;
    }

    private static function norm(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?: '';
        return trim(preg_replace('/\s+/', ' ', $s) ?: '');
    }

    /** @return array{title:string,year:?int,imdb:?string}|null */
    private static function tmdbMeta(string $type, int $tmdbId): ?array
    {
        try {
            if (!class_exists('Tmdb')) {
                return null;
            }
            $tmdb = $GLOBALS['tmdb'] ?? null;
            if (!$tmdb instanceof Tmdb) {
                $tmdb = new Tmdb();
            }
            $details = $tmdb->details($type, $tmdbId);
            if (!is_array($details)) {
                return null;
            }
            $title = trim((string) ($details['title'] ?? $details['name'] ?? ''));
            if ($title === '') {
                return null;
            }
            $date = (string) ($details['release_date'] ?? $details['first_air_date'] ?? '');
            $year = preg_match('/^(\d{4})/', $date, $m) ? (int) $m[1] : null;
            $imdb = trim((string) ($details['external_ids']['imdb_id'] ?? ''));
            return [
                'title' => $title,
                'year' => $year,
                'imdb' => preg_match('/^tt\d+$/', $imdb) ? $imdb : null,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @param array<string,string> $headers */
    private static function mint(string $direct, array $headers): string
    {
        if (class_exists('VidmolySources') && method_exists('VidmolySources', 'signedProxyUrl')) {
            // Progressive file — no playlist rewrite.
            return VidmolySources::signedProxyUrl($direct, $headers, false);
        }
        if (class_exists('StremifySources') && method_exists('StremifySources', 'signedProxyUrl')) {
            return StremifySources::signedProxyUrl($direct, $headers);
        }
        return $direct;
    }

    private static function absUrl(string $href): string
    {
        $href = trim($href);
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        return self::SITE . '/' . ltrim($href, '/');
    }

    /**
     * @return array{status:int,url:string,body:string}|null
     */
    private static function httpGet(string $url, string &$cookie, string $referer): ?array
    {
        return self::httpRequest($url, null, $cookie, $referer);
    }

    /**
     * @return array{status:int,url:string,body:string}|null
     */
    private static function httpPost(string $url, string $body, string &$cookie, string $referer): ?array
    {
        return self::httpRequest($url, $body, $cookie, $referer);
    }

    /**
     * @return array{status:int,url:string,body:string}|null
     */
    private static function httpRequest(string $url, ?string $postBody, string &$cookie, string $referer): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $headers = [
            'User-Agent: ' . self::UA,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Referer: ' . $referer,
        ];
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }
        if ($postBody !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $ch = curl_init($url);
        $responseHeaders = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$responseHeaders) {
                $responseHeaders .= $line;
                return strlen($line);
            },
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_ENCODING => '',
        ]);
        if ($postBody !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if (!is_string($body)) {
            return null;
        }
        // Merge Set-Cookie into jar string (name=value only).
        if (preg_match_all('/^Set-Cookie:\s*([^;=\s]+)=([^;]*)/im', $responseHeaders, $cm, PREG_SET_ORDER)) {
            $map = [];
            if ($cookie !== '') {
                foreach (explode('; ', $cookie) as $part) {
                    [$n, $v] = array_pad(explode('=', $part, 2), 2, '');
                    if ($n !== '') {
                        $map[$n] = $v;
                    }
                }
            }
            foreach ($cm as $row) {
                $map[$row[1]] = $row[2];
            }
            $parts = [];
            foreach ($map as $n => $v) {
                $parts[] = $n . '=' . $v;
            }
            $cookie = implode('; ', $parts);
        }
        return ['status' => $status, 'url' => $final !== '' ? $final : $url, 'body' => $body];
    }
}
