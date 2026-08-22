<?php
declare(strict_types=1);

/**
 * YesMovies (ww2.yesmovies.ag) → ployan.me direct HLS.
 *
 * No browser. Flow (RE'd from their player):
 *   1) Map TMDB title → YesMovies mid (sitemap slug index, cached)
 *   2) For TV, pick episode id from the season page
 *   3) PBKDF2("player") + AES-GCM → GET https://ployan.me/get/{salt}-{iv}-{ct}
 *   4) Prefer mode=direct → https://ployan.me/hls/{info}/master.m3u8
 *   5) Mint through v-relay (segments on *.voxzer.org)
 *
 * Server 1 is usually direct; servers 2/5 are embed-mode (skip for now).
 */
final class YesMoviesSources
{
    public const PROVIDER_ID = 'yesmovies';
    public const PROVIDER_NAME = 'YesMovies';

    private const SITE = 'https://ww2.yesmovies.ag';
    private const PLAYER = 'https://ployan.me';
    private const UA =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const SITEMAP_TTL = 21600; // 6h
    private const INDEX_TTL = 21600;

    /**
     * @return array{ok:bool,sources?:list<array<string,mixed>>,diagnostics?:list<array<string,mixed>>,error?:string}
     */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        $diagnostics = [];
        if ($tmdbId < 1) {
            return ['ok' => false, 'error' => 'Invalid TMDB id', 'sources' => [], 'diagnostics' => []];
        }

        $meta = self::tmdbMeta($type, $tmdbId);
        if ($meta === null) {
            return [
                'ok' => false,
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'YESMOVIES_NO_TMDB',
                    'message' => 'TMDB details missing',
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ]],
                'error' => 'No TMDB metadata',
            ];
        }

        $hit = self::findMid($type, $meta, max(1, $season), $diagnostics);
        if ($hit === null) {
            return [
                'ok' => false,
                'sources' => [],
                'diagnostics' => $diagnostics,
                'error' => 'Title not in YesMovies index',
            ];
        }

        $mid = $hit['mid'];
        $pageUrl = $hit['url'];
        $eid = '1';
        if ($type === 'tv') {
            $eid = self::episodeIdFromPage($pageUrl, max(1, $episode), $diagnostics);
            if ($eid === null) {
                return [
                    'ok' => false,
                    'sources' => [],
                    'diagnostics' => $diagnostics,
                    'error' => 'Episode not listed on YesMovies',
                ];
            }
        }

        // Prefer server 1 (direct HLS). Fall back to other direct servers if needed.
        foreach (['1', '2', '5'] as $sv) {
            $resolved = self::resolveDirect($mid, $eid, $sv, $diagnostics);
            if ($resolved === null) {
                continue;
            }
            $playUrl = self::mint($resolved['hls'], [
                'Referer' => self::SITE . '/',
                'Origin' => self::SITE,
                'Accept' => '*/*',
                'User-Agent' => self::UA,
            ]);
            $diagnostics[] = [
                'code' => 'YESMOVIES_OK',
                'message' => 'ployan direct sv' . $sv . ' mid=' . $mid . ' eid=' . $eid,
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            return [
                'ok' => true,
                'sources' => [[
                    'url' => $playUrl,
                    'type' => 'hls',
                    'quality' => '1080p',
                    'provider' => self::PROVIDER_ID,
                    'providerName' => self::PROVIDER_NAME,
                    'label' => self::PROVIDER_NAME . ' · 1080p',
                    'language' => 'en',
                    'hasEnglish' => true,
                    'meta' => [
                        'backend' => 'yesmovies-ployan',
                        'mid' => $mid,
                        'eid' => $eid,
                        'sv' => $sv,
                        'mode' => $resolved['mode'],
                        'page' => $pageUrl,
                        'direct' => $resolved['hls'],
                    ],
                ]],
                'diagnostics' => $diagnostics,
            ];
        }

        return [
            'ok' => false,
            'sources' => [],
            'diagnostics' => $diagnostics,
            'error' => 'YesMovies ployan resolve failed',
        ];
    }

    /**
     * @param array{title:string,year:?int,imdb:?string} $meta
     * @param list<array<string,mixed>> $diagnostics
     * @return array{mid:string,url:string}|null
     */
    private static function findMid(string $type, array $meta, int $season, array &$diagnostics): ?array
    {
        $index = self::sitemapIndex($diagnostics);
        if ($index === []) {
            return null;
        }

        $title = self::norm($meta['title']);
        $year = $meta['year'];

        if ($type === 'tv') {
            $want = $title . ' season ' . $season;
            $candidates = [];
            foreach ($index as $row) {
                $slug = (string) ($row['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $seasonOk = str_contains($slug, 'season-' . $season)
                    || str_contains($slug, 'season' . $season)
                    || (bool) preg_match('/season[- ]?' . $season . '(?:-|\.|$)/', $slug);
                if (!$seasonOk) {
                    continue;
                }
                $score = self::slugScore($slug, $want, $title, null);
                if ($score >= 60) {
                    $candidates[] = [$score, $row];
                }
            }
            usort($candidates, static fn($a, $b) => $b[0] <=> $a[0]);
            if ($candidates !== []) {
                return ['mid' => (string) $candidates[0][1]['mid'], 'url' => (string) $candidates[0][1]['url']];
            }
            $diagnostics[] = [
                'code' => 'YESMOVIES_NO_SEASON',
                'message' => 'No season ' . $season . ' slug for ' . $meta['title'],
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }

        $candidates = [];
        foreach ($index as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '' || str_contains($slug, 'season-') || str_contains($slug, '-season')) {
                continue;
            }
            $score = self::slugScore($slug, $title, $title, $year);
            if ($score >= 70) {
                $candidates[] = [$score, $row];
            }
        }
        usort($candidates, static fn($a, $b) => $b[0] <=> $a[0]);
        if ($candidates === []) {
            $diagnostics[] = [
                'code' => 'YESMOVIES_NO_MATCH',
                'message' => 'No sitemap match for ' . $meta['title'],
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        return ['mid' => (string) $candidates[0][1]['mid'], 'url' => (string) $candidates[0][1]['url']];
    }

    private static function slugScore(string $slug, string $wantNorm, string $titleNorm, ?int $year): int
    {
        $slugNorm = self::norm(str_replace('-', ' ', $slug));
        $score = 0;
        if ($slugNorm === $wantNorm) {
            $score += 120;
        } elseif (str_starts_with($slugNorm, $wantNorm . ' ')) {
            $score += 100;
        } elseif ($slugNorm === $titleNorm || str_starts_with($slugNorm, $titleNorm . ' ')) {
            $score += 90;
        } else {
            $a = array_values(array_filter(explode(' ', $titleNorm)));
            $b = array_values(array_filter(explode(' ', $slugNorm)));
            if ($a === []) {
                return 0;
            }
            $hit = count(array_intersect($a, $b));
            $score += (int) round(90 * ($hit / count($a)));
            // Require almost all title tokens (avoid Across↔Into collisions).
            if ($hit < max(2, count($a) - 1)) {
                return min($score, 50);
            }
            // Penalize extra distinctive tokens in slug not in title (into vs across).
            $stop = ['the', 'a', 'an', 'of', 'and', 'season', 'movie', 'film'];
            $extra = array_diff($b, $a, $stop);
            // year-like tokens ok
            $extra = array_values(array_filter($extra, static fn($t) => !preg_match('/^\d{4}$/', $t)));
            $score -= 15 * count($extra);
        }
        // Bonus when every non-stop title token is present.
        $titleToks = array_values(array_filter(
            explode(' ', $titleNorm),
            static fn($t) => $t !== '' && !in_array($t, ['the', 'a', 'an', 'of', 'and'], true)
        ));
        $slugToks = array_filter(explode(' ', $slugNorm));
        $missing = array_diff($titleToks, $slugToks);
        if ($missing !== []) {
            $score -= 25 * count($missing);
        }
        if ($year !== null && str_contains($slug, (string) $year)) {
            $score += 10;
        }
        return $score;
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return list<array{slug:string,mid:string,url:string}>
     */
    private static function sitemapIndex(array &$diagnostics): array
    {
        $cachePath = self::cacheDir() . '/yesmovies-sitemap-index.json';
        if (is_file($cachePath)) {
            $mtime = (int) @filemtime($cachePath);
            if ($mtime > 0 && (time() - $mtime) < self::INDEX_TTL) {
                $raw = @file_get_contents($cachePath);
                $json = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($json) && $json !== [] && isset($json[0]['slug'])) {
                    /** @var list<array{slug:string,mid:string,url:string}> $json */
                    return $json;
                }
            }
        }

        $xml = self::httpRaw(self::SITE . '/sitemap.xml');
        if ($xml === null || $xml[0] < 200 || $xml[0] >= 300) {
            $diagnostics[] = [
                'code' => 'YESMOVIES_SITEMAP',
                'message' => 'sitemap fetch failed',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return [];
        }
        $body = $xml[1];
        $index = [];
        $seen = [];
        if (preg_match_all('#https://ww2\.yesmovies\.ag/movie/([a-z0-9-]+)-(\d+)\.html#i', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $slug = strtolower((string) $row[1]);
                $mid = (string) $row[2];
                $url = (string) $row[0];
                if ($slug === '' || isset($seen[$slug])) {
                    continue;
                }
                $seen[$slug] = true;
                $index[] = [
                    'slug' => $slug,
                    'mid' => $mid,
                    'url' => $url,
                ];
            }
        }
        if ($index === []) {
            $diagnostics[] = [
                'code' => 'YESMOVIES_SITEMAP_EMPTY',
                'message' => 'No movie URLs in sitemap',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return [];
        }
        @file_put_contents($cachePath, json_encode($index, JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($cachePath, 0664);
        $diagnostics[] = [
            'code' => 'YESMOVIES_SITEMAP_OK',
            'message' => 'Indexed ' . count($index) . ' titles',
            'severity' => 'info',
            'provider' => self::PROVIDER_ID,
        ];
        return $index;
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function episodeIdFromPage(string $pageUrl, int $episode, array &$diagnostics): ?string
    {
        $res = self::httpRaw($pageUrl);
        if ($res === null || $res[0] < 200 || $res[0] >= 300) {
            $diagnostics[] = [
                'code' => 'YESMOVIES_PAGE',
                'message' => 'Season page fetch failed',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $html = $res[1];
        // Prefer server-1 episode list: data-id is 1-based episode number on YesMovies.
        if (preg_match(
            '#id=episodes-sv-1[^>]*>(.*?)</ul>#si',
            $html,
            $block
        )) {
            if (preg_match_all(
                '#data-index="?(\d+)"?\s+data-server="?1"?\s+data-id="?(\d+)"?#i',
                $block[1],
                $eps,
                PREG_SET_ORDER
            )) {
                foreach ($eps as $ep) {
                    $idx = (int) $ep[1]; // 0-based
                    $id = $ep[2];
                    if ($idx + 1 === $episode || (int) $id === $episode) {
                        return $id;
                    }
                }
            }
        }
        // Fallback: any server list
        if (preg_match_all(
            '#class="[^"]*ep-item[^"]*"[^>]*data-index="?(\d+)"?\s+data-server="?(\d+)"?\s+data-id="?(\d+)"?#i',
            $html,
            $eps,
            PREG_SET_ORDER
        )) {
            foreach ($eps as $ep) {
                if ((int) $ep[2] !== 1) {
                    continue;
                }
                if (((int) $ep[1]) + 1 === $episode || (int) $ep[3] === $episode) {
                    return $ep[3];
                }
            }
        }
        $diagnostics[] = [
            'code' => 'YESMOVIES_NO_EP',
            'message' => 'Episode ' . $episode . ' not on page',
            'severity' => 'warning',
            'provider' => self::PROVIDER_ID,
        ];
        return null;
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{hls:string,mode:string}|null
     */
    private static function resolveDirect(string $mid, string $eid, string $sv, array &$diagnostics): ?array
    {
        $tsx = (string) time();
        $plain = $mid . '+' . $eid . '+' . $sv . '+' . $tsx;
        $salt = random_bytes(8);
        $iv = random_bytes(12);
        $key = hash_pbkdf2('sha256', 'player', $salt, 1000, 32, true);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false || $tag === '') {
            $diagnostics[] = [
                'code' => 'YESMOVIES_CRYPTO',
                'message' => 'openssl_encrypt failed',
                'severity' => 'error',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $path = bin2hex($salt) . '-' . bin2hex($iv) . '-' . bin2hex($ct . $tag);
        $url = self::PLAYER . '/get/' . $path;
        $res = self::httpRaw($url, [
            'Referer: ' . self::PLAYER . '/watch/?v' . $sv . $eid,
            'Origin: ' . self::PLAYER,
            'Accept: application/json, */*',
        ]);
        if ($res === null || $res[0] < 200 || $res[0] >= 300) {
            $diagnostics[] = [
                'code' => 'YESMOVIES_GET',
                'message' => 'ployan /get HTTP ' . ($res[0] ?? 0) . ' sv=' . $sv,
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $json = json_decode($res[1], true);
        if (!is_array($json) || (int) ($json['code'] ?? 0) !== 200 || empty($json['info'])) {
            $diagnostics[] = [
                'code' => 'YESMOVIES_GET_BAD',
                'message' => 'ployan /get bad payload sv=' . $sv,
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $mode = (string) ($json['mode'] ?? '');
        if ($mode !== 'direct') {
            $diagnostics[] = [
                'code' => 'YESMOVIES_EMBED_MODE',
                'message' => 'sv' . $sv . ' mode=' . $mode . ' (skip)',
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $info = preg_replace('/[^a-f0-9-]/i', '', (string) $json['info']) ?? '';
        if ($info === '') {
            return null;
        }
        $hls = self::PLAYER . '/hls/' . $info . '/master.m3u8';
        // Probe — some titles 502 on direct.
        $probe = self::httpRaw($hls, [
            'Referer: ' . self::SITE . '/',
            'Accept: application/vnd.apple.mpegurl, */*',
        ], 12);
        if ($probe === null || $probe[0] < 200 || $probe[0] >= 300 || !str_contains($probe[1], '#EXTM3U')) {
            $diagnostics[] = [
                'code' => 'YESMOVIES_HLS',
                'message' => 'master.m3u8 HTTP ' . ($probe[0] ?? 0) . ' sv=' . $sv,
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        return ['hls' => $hls, 'mode' => $mode];
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
            return VidmolySources::signedProxyUrl($direct, $headers, true);
        }
        if (class_exists('StremifySources') && method_exists('StremifySources', 'signedProxyUrl')) {
            return StremifySources::signedProxyUrl($direct, $headers);
        }
        return $direct;
    }

    private static function norm(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    private static function cacheDir(): string
    {
        $dir = sys_get_temp_dir() . '/vuflix-yesmovies';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * @param list<string> $extraHeaders
     * @return array{0:int,1:string}|null
     */
    private static function httpRaw(string $url, array $extraHeaders = [], int $timeout = 25): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $headers = array_merge([
            'User-Agent: ' . self::UA,
            'Accept: */*',
            'Accept-Language: en-US,en;q=0.9',
        ], $extraHeaders);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            return null;
        }
        return [$code, $body];
    }
}
