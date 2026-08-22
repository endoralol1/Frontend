<?php
declare(strict_types=1);

/**
 * OpStream-style "instant" streams via api.speedracelight.com.
 *
 * Opstream.fun does not scrape embeds — it calls SpeedRace:
 *   1) GET /seed?mediaId={tmdb}
 *   2) GET /cdn/sources-with-title?...&enc=2&seed=...
 *   3) XOR-decrypt (magic mvm1) → {sources:[{quality,url}]}
 * CDN hosts: moon.peakstorm.top /vd/... m3u8 (CORS blocks browser Origin
 * from other sites — mint v-relay like OpStream's /api/stream/direct).
 */
final class OpStreamSources
{
    public const PROVIDER_ID = 'opstream';
    public const PROVIDER_NAME = 'OpStream';

    private const API = 'https://api.speedracelight.com';
    private const DB = 'https://db.speedracelight.com';
    private const UA =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /** @var list<string> */
    private const QUALITY_RANK = ['4k', '2160', '1080', '720', '480', '360'];

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

        $meta = self::meta($type, $tmdbId, max(1, $season), max(1, $episode), $diagnostics);
        if ($meta === null) {
            return [
                'ok' => false,
                'sources' => [],
                'diagnostics' => $diagnostics,
                'error' => 'No metadata for SpeedRace',
            ];
        }

        // SpeedRace rate-limits aggressively and seeds are single-use / short-TTL.
        // Retry seed → encrypted fetch → decrypt a few times with backoff.
        $plain = null;
        $lastErr = 'SpeedRace sources empty';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($attempt > 0) {
                usleep(400000 * $attempt); // 0.4s, 0.8s, 1.2s, 1.6s
            }
            $seed = self::seed($tmdbId, $diagnostics, $attempt > 0);
            if ($seed === null || $seed === '') {
                $lastErr = 'No SpeedRace seed';
                continue;
            }
            $enc = self::fetchEncrypted($type, $tmdbId, $meta, $seed, max(1, $season), max(1, $episode), $diagnostics);
            if ($enc === null || $enc === '') {
                $lastErr = 'SpeedRace sources empty';
                continue;
            }
            try {
                $plain = self::decrypt($enc, $seed, $tmdbId);
                break;
            } catch (Throwable $e) {
                $lastErr = 'SpeedRace decrypt failed';
                $diagnostics[] = [
                    'code' => 'OPSTREAM_DECRYPT',
                    'message' => $e->getMessage() . ' (try ' . ($attempt + 1) . ')',
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ];
                $plain = null;
            }
        }
        if ($plain === null || $plain === '') {
            return [
                'ok' => false,
                'sources' => [],
                'diagnostics' => $diagnostics,
                'error' => $lastErr,
            ];
        }

        $data = json_decode($plain, true);

        $raw = is_array($data) ? ($data['sources'] ?? []) : [];
        $links = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '' || !preg_match('/\.m3u8(\?|$)/i', $url)) {
                continue;
            }
            if (!self::urlAllowed($url)) {
                continue;
            }
            $q = (string) ($row['quality'] ?? 'Auto');
            $links[] = [
                'url' => $url,
                'quality' => $q !== '' ? $q : 'Auto',
                'type' => 'hls',
            ];
        }

        usort($links, static function (array $a, array $b): int {
            return OpStreamSources::qualityRank($b['quality']) <=> OpStreamSources::qualityRank($a['quality']);
        });

        if ($links === []) {
            $diagnostics[] = [
                'code' => 'OPSTREAM_EMPTY',
                'message' => 'No playable m3u8 from SpeedRace',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return [
                'ok' => false,
                'sources' => [],
                'diagnostics' => $diagnostics,
                'error' => 'No OpStream streams',
            ];
        }

        // Prefer 1080p for first play (4K segments can stall phones); keep all in qualities.
        $best = $links[0];
        foreach ($links as $link) {
            if (str_contains(strtolower($link['quality']), '1080')) {
                $best = $link;
                break;
            }
        }
        $diagnostics[] = [
            'code' => 'OPSTREAM_OK',
            'message' => 'SpeedRace ' . count($links) . ' qualities',
            'severity' => 'info',
            'provider' => self::PROVIDER_ID,
        ];

        return [
            'ok' => true,
            'sources' => [[
                'url' => $best['url'],
                'type' => 'hls',
                'quality' => $best['quality'],
                'provider' => self::PROVIDER_ID,
                'providerName' => self::PROVIDER_NAME,
                'label' => self::PROVIDER_NAME . ' · ' . $best['quality'],
                'language' => 'en',
                'hasEnglish' => true,
                'headers' => [
                    'User-Agent' => self::UA,
                    // No Origin — peakstorm 403s browser Origin from other sites.
                    'Accept' => '*/*',
                ],
                'qualities' => $links,
                'meta' => [
                    'backend' => 'speedracelight',
                    'host' => (string) (parse_url($best['url'], PHP_URL_HOST) ?: ''),
                ],
            ]],
            'diagnostics' => $diagnostics,
        ];
    }

    public static function qualityRank(string $q): int
    {
        $t = strtolower($q);
        foreach (self::QUALITY_RANK as $i => $token) {
            if (str_contains($t, $token)) {
                return count(self::QUALITY_RANK) - $i;
            }
        }
        return 0;
    }

    private static function urlAllowed(string $url): bool
    {
        // Mirrors OpStream client allowlist: https://host/(vd|r2)/...
        return (bool) preg_match(
            '#^https://(?!\d+\.\d+\.\d+\.\d+/|localhost)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+/(?:vd|r2)/#i',
            $url
        );
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{name:string,year:string,imdb:string}|null
     */
    private static function meta(string $type, int $tmdbId, int $season, int $episode, array &$diagnostics): ?array
    {
        $path = $type === 'tv'
            ? "tv/{$tmdbId}/season/{$season}/episode/{$episode}"
            : "movie/{$tmdbId}";
        $json = self::httpJson(self::DB . '/3/' . $path . '?append_to_response=external_ids');
        if (!is_array($json)) {
            // Fallback to local Tmdb if their db mirror fails.
            try {
                if (class_exists('Tmdb')) {
                    $tmdb = $GLOBALS['tmdb'] ?? new Tmdb();
                    if ($tmdb instanceof Tmdb) {
                        $json = $tmdb->details($type, $tmdbId);
                    }
                }
            } catch (Throwable $e) {
                $json = null;
            }
        }
        if (!is_array($json)) {
            $diagnostics[] = [
                'code' => 'OPSTREAM_META',
                'message' => 'Metadata fetch failed',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $ext = is_array($json['external_ids'] ?? null) ? $json['external_ids'] : [];
        $imdb = trim((string) ($json['imdb_id'] ?? $ext['imdb_id'] ?? ''));
        $date = (string) ($json['release_date'] ?? $json['first_air_date'] ?? $json['air_date'] ?? '');
        $name = trim((string) ($json['title'] ?? $json['name'] ?? ''));
        if ($name === '') {
            $diagnostics[] = [
                'code' => 'OPSTREAM_META',
                'message' => 'No title for TMDB ' . $tmdbId,
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        return [
            'name' => $name,
            'year' => substr($date, 0, 4),
            'imdb' => $imdb,
        ];
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function seed(int $tmdbId, array &$diagnostics, bool $force = false): ?string
    {
        static $cache = [];
        $key = (string) $tmdbId;
        if (!$force && isset($cache[$key]) && $cache[$key]['exp'] > time()) {
            return $cache[$key]['seed'];
        }
        $res = self::httpRaw(self::API . '/seed?mediaId=' . $tmdbId);
        if ($res !== null && $res[0] === 429) {
            $diagnostics[] = [
                'code' => 'OPSTREAM_RATE',
                'message' => 'SpeedRace seed rate-limited',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $json = null;
        if ($res !== null && $res[0] >= 200 && $res[0] < 300) {
            $decoded = json_decode($res[1], true);
            $json = is_array($decoded) ? $decoded : null;
        }
        $seed = is_array($json) ? trim((string) ($json['seed'] ?? '')) : '';
        if ($seed === '') {
            $diagnostics[] = [
                'code' => 'OPSTREAM_SEED',
                'message' => 'No seed from SpeedRace',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $ttlMs = is_array($json) ? (int) ($json['ttlMs'] ?? 25000) : 25000;
        $ttlMs = max(5000, min(25000, $ttlMs));
        $cache[$key] = ['seed' => $seed, 'exp' => time() + (int) floor($ttlMs / 1000) - 2];
        return $seed;
    }

    /**
     * @param array{name:string,year:string,imdb:string} $meta
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function fetchEncrypted(
        string $type,
        int $tmdbId,
        array $meta,
        string $seed,
        int $season,
        int $episode,
        array &$diagnostics
    ): ?string {
        // Match OpStream JS: encodeURIComponent(name) then URLSearchParams (double-encode title).
        // Use RFC3986 so spaces become %20 (not +) — SpeedRace rejects +.
        $qs = [
            'title' => rawurlencode($meta['name']),
            'mediaType' => $type === 'tv' ? 'tv' : 'movie',
            'year' => $meta['year'],
            'tmdbId' => (string) $tmdbId,
            'enc' => '2',
            'seed' => $seed,
        ];
        if ($meta['imdb'] !== '') {
            $qs['imdbId'] = $meta['imdb'];
        }
        if ($type === 'tv') {
            $qs['seasonId'] = (string) $season;
            $qs['episodeId'] = (string) $episode;
        }
        $url = self::API . '/cdn/sources-with-title?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
        $res = self::httpRaw($url);
        if ($res === null) {
            $diagnostics[] = [
                'code' => 'OPSTREAM_HTTP',
                'message' => 'sources-with-title failed',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        [$code, $body] = $res;
        if ($code === 401) {
            $diagnostics[] = [
                'code' => 'OPSTREAM_SEED_ROTATED',
                'message' => 'SpeedRace seed invalid/rotated',
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        if ($code === 429) {
            $diagnostics[] = [
                'code' => 'OPSTREAM_RATE',
                'message' => 'SpeedRace sources rate-limited',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        if ($code < 200 || $code >= 300 || $body === '') {
            $diagnostics[] = [
                'code' => 'OPSTREAM_HTTP',
                'message' => 'sources HTTP ' . $code . ' ' . substr($body, 0, 80),
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        return $body;
    }

    /** @throws RuntimeException */
    private static function decrypt(string $enc, string $seed, int $tmdbId): string
    {
        $s = trim($enc);
        if ($s !== '' && ($s[0] === '{' || $s[0] === '[')) {
            return $s;
        }
        $b64 = strtr($s, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('bad b64');
        }
        $a = array_values(unpack('C*', $raw) ?: []);
        $n = count($a);
        $state = self::prngInit($seed, $tmdbId);
        $key = [];
        $idx = 0;
        $k = 0;
        while ($k < $n) {
            $t = self::prngNext($state, $idx++);
            $key[$k++] = $t & 255;
            if ($k < $n) {
                $key[$k++] = ($t >> 8) & 255;
            }
            if ($k < $n) {
                $key[$k++] = ($t >> 16) & 255;
            }
            if ($k < $n) {
                $key[$k++] = ($t >> 24) & 255;
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $a[$i] ^= $key[$i];
        }
        // magic mvm1
        if ($n < 4 || $a[0] !== 109 || $a[1] !== 118 || $a[2] !== 109 || $a[3] !== 49) {
            throw new RuntimeException('bad seed / magic mismatch');
        }
        $out = '';
        for ($i = 4; $i < $n; $i++) {
            $out .= chr($a[$i]);
        }
        return $out;
    }

    /** @return array{S:array<int,int>,acc:int} */
    private static function prngInit(string $seed, int $tmdbId): array
    {
        $r = 0x811c9dc5;
        $len = strlen($seed);
        for ($i = 0; $i < $len; $i++) {
            $r = self::umul32($r ^ ord($seed[$i]), 0x1000193);
        }
        $r = self::mix($r);
        $s = self::mix(self::u32($r ^ self::mix(self::u32(($tmdbId & 0xffffffff) ^ 0x9e3779b9))));
        $S = [];
        for ($e = 0; $e < 8; $e++) {
            $t = $s % 61;
            $s = self::rotl(self::u32($s + 0x9e3779b9), 7 + (7 & $e));
            $S[$t] = self::u32($s ^ self::mix($s));
            $s = self::mix(self::u32($s + $t));
        }
        return ['S' => $S, 'acc' => self::mix(self::u32(0xa5a5a5a5 ^ $s))];
    }

    /** @param array{S:array<int,int>,acc:int} $state */
    private static function prngNext(array &$state, int $n): int
    {
        $S = &$state['S'];
        $s = $state['acc'];
        $a = $s % 61;
        $nFlag = array_key_exists($a, $S) ? 1 : 0;
        $i = self::u32(($S[$a] ?? 0) ^ self::umul32(0x9e3779b9, $n + 1));
        $mask = $nFlag ? 0xffffffff : 0;
        $l = self::u32(($s ^ $i) | ($s & $i & $mask));
        $l = self::u32(self::rotl(self::u32($l + $s), 31 & $a) ^ self::rotl($s, 31 & self::u32($a * 7)));
        $d = self::mix(self::u32($l + 0x9e3779b9));
        $S[$a] = $d;
        $state['acc'] = $d;
        return $d;
    }

    private static function mix(int $e): int
    {
        $e = self::u32($e);
        $e = self::u32($e ^ ($e >> 16));
        $e = self::umul32($e, 0x85ebca6b);
        $e = self::u32($e ^ ($e >> 13));
        $e = self::umul32($e, 0xc2b2ae35);
        return self::u32($e ^ ($e >> 16));
    }

    private static function rotl(int $e, int $t): int
    {
        $e = self::u32($e);
        $t &= 31;
        if ($t === 0) {
            return $e;
        }
        return self::u32(($e << $t) | ($e >> (32 - $t)));
    }

    /**
     * uint32 multiply without promoting to float (PHP int*int can overflow to float).
     */
    private static function umul32(int $a, int $b): int
    {
        $a = self::u32($a);
        $b = self::u32($b);
        $ah = ($a >> 16) & 0xffff;
        $al = $a & 0xffff;
        $bh = ($b >> 16) & 0xffff;
        $bl = $b & 0xffff;
        $hi = (($ah * $bl) + ($al * $bh)) & 0xffff;
        return self::u32(($hi << 16) + ($al * $bl));
    }

    /** @param int|float $n */
    private static function u32(int|float $n): int
    {
        if (is_float($n)) {
            $n = fmod($n, 4294967296.0);
            if ($n < 0.0) {
                $n += 4294967296.0;
            }
            return (int) $n;
        }
        // Keep non-negative uint32 on 64-bit PHP.
        return $n & 0xffffffff;
    }

    /** @return array<string,mixed>|null */
    private static function httpJson(string $url): ?array
    {
        $res = self::httpRaw($url);
        if ($res === null || $res[0] < 200 || $res[0] >= 300) {
            return null;
        }
        $data = json_decode($res[1], true);
        return is_array($data) ? $data : null;
    }

    /** @return array{0:int,1:string}|null */
    private static function httpRaw(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::UA,
                'Accept: */*',
                'Origin: https://opstream.fun',
                'Referer: https://opstream.fun/',
            ],
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
