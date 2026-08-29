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

    /** Max quality variants exposed to the player. */
    private const MAX_QUALITIES = 4;

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

        // Prefer higher quality first.
        usort($qualities, static fn($a, $b) => self::qualityRank($b['quality']) <=> self::qualityRank($a['quality']));
        $qualities = array_slice($qualities, 0, self::MAX_QUALITIES);

        $resolved = [];
        foreach ($qualities as $q) {
            $cdn = self::resolveQuality($q['href'], $page['url'], $cookie, $diagnostics);
            if ($cdn === null) {
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
        // Warm session cookie.
        self::httpGet(self::SITE . '/', $cookie, self::SITE . '/');

        $title = $meta['title'];
        $year = $meta['year'];

        // Fast path: exact slug movie-{Title}--hmp4.htm (spaces allowed in path).
        $directPath = 'movie-' . $title . '--hmp4.htm';
        $directUrl = self::SITE . '/' . str_replace(' ', '%20', $directPath);
        $res = self::httpGet($directUrl, $cookie, self::SITE . '/');
        if ($res !== null && $res['status'] >= 200 && $res['status'] < 300 && str_contains($res['body'], 'download1.php')) {
            $diagnostics[] = [
                'code' => 'FZMOVIES_DIRECT',
                'message' => 'Hit direct page ' . $directPath,
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            return ['url' => $res['url'], 'html' => $res['body']];
        }

        // Search fallback.
        $post = http_build_query([
            'searchname' => $title,
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

        $best = null;
        $bestScore = -1;
        foreach ($hits as $href) {
            $score = self::slugScore($href, $title, $year);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $href;
            }
        }
        if ($best === null || $bestScore < 70) {
            $diagnostics[] = [
                'code' => 'FZMOVIES_NO_MATCH',
                'message' => 'Best search score ' . $bestScore . ' for ' . $title,
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }

        $pageUrl = self::SITE . '/' . str_replace(' ', '%20', ltrim($best, '/'));
        $res = self::httpGet($pageUrl, $cookie, self::SITE . '/');
        if ($res === null || $res['status'] < 200 || $res['status'] >= 300 || !str_contains($res['body'], 'download1.php')) {
            $diagnostics[] = [
                'code' => 'FZMOVIES_PAGE',
                'message' => 'Movie page fetch failed for ' . $best,
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $diagnostics[] = [
            'code' => 'FZMOVIES_MATCH',
            'message' => 'Matched ' . $best . ' score=' . $bestScore,
            'severity' => 'info',
            'provider' => self::PROVIDER_ID,
        ];
        return ['url' => $res['url'], 'html' => $res['body']];
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

        foreach ($mirrors as $mirror) {
            $probe = self::probeCdn($mirror);
            if ($probe === null) {
                continue;
            }
            return [
                'url' => $mirror,
                'mirror' => (string) (parse_url($mirror, PHP_URL_HOST) ?: ''),
                'size' => $probe['size'],
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
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
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
        if (!is_string($body) || $body === '') {
            return null;
        }
        if ($code !== 200 && $code !== 206) {
            return null;
        }
        // MP4/ISOBMFF or Matroska magic
        $ok = str_contains($body, 'ftyp')
            || str_starts_with($body, "\x1A\x45\xDF\xA3")
            || (strlen($body) >= 8 && $body[4] === 'f' && str_contains(substr($body, 4, 8), 'ftyp'));
        if (!$ok && strlen($body) >= 12) {
            // Some servers wrap; accept octet-stream with enough bytes on 206.
            $ok = $code === 206 && strlen($body) >= 512;
        }
        if (!$ok) {
            return null;
        }
        $size = null;
        // Content-Range not easily available without HEADERFUNCTION; skip size.
        return ['size' => $size];
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
            if (str_contains($slugNorm, (string) $year) || str_contains($slug, (string) $year)) {
                $score += 15;
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
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 35,
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
