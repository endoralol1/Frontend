<?php
declare(strict_types=1);

/**
 * MegaSource — Cinemove's "Mega" fast source.
 *
 * Public MegaSource Stremio addon runs pluggable scrapers; their default
 * scraper targets https://v1.watchplay.shop (IMDb → player API → HLS).
 * We scrape WatchPlay directly (no Wasmer sandbox), then mint v-relay URLs.
 * CDN (*.hclod.qzz.io) is reachable from the VPS — no Worker required.
 */
final class MegaSourceSources
{
    public const PROVIDER_ID = 'megasource';
    public const PROVIDER_NAME = 'MegaSource';
    private const BASE = 'https://v1.watchplay.shop';
    private const UA =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

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

        $imdb = self::imdbFor($type, $tmdbId);
        if ($imdb === null) {
            return [
                'ok' => false,
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'MEGASOURCE_NO_IMDB',
                    'message' => 'No IMDb id for TMDB ' . $tmdbId,
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ]],
                'error' => 'No IMDb id',
            ];
        }

        $info = $type === 'tv'
            ? self::series($tmdbId, max(1, $season), max(1, $episode), $diagnostics)
            : self::movie($imdb, $diagnostics);

        if ($info === null || empty($info['url'])) {
            return [
                'ok' => false,
                'sources' => [],
                'diagnostics' => $diagnostics ?: [[
                    'code' => 'MEGASOURCE_EMPTY',
                    'message' => 'No WatchPlay stream for ' . $imdb,
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ]],
                'error' => 'No MegaSource streams',
            ];
        }

        $headers = [
            'User-Agent' => self::UA,
            'Referer' => self::BASE . '/',
            'Origin' => self::BASE,
            'Accept' => '*/*',
        ];
        $streamUrl = (string) $info['url'];
        $isHls = (bool) preg_match('/\.m3u8(\?|$)/i', $streamUrl);

        return [
            'ok' => true,
            'sources' => [[
                'url' => $streamUrl,
                'type' => $isHls ? 'hls' : 'file',
                'quality' => 'Auto',
                'provider' => self::PROVIDER_ID,
                'providerName' => self::PROVIDER_NAME,
                'label' => 'MegaSource',
                'language' => 'pt', // WatchPlay default pack is often Dublado; still playable
                'hasEnglish' => true, // don't auto-skip — many titles still have eng audio/subs
                'headers' => $headers,
            ]],
            'diagnostics' => $diagnostics,
            'error' => null,
            'meta' => ['imdb' => $imdb, 'watchplay' => true],
        ];
    }

    private static function imdbFor(string $type, int $tmdbId): ?string
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
            $imdb = trim((string) ($details['external_ids']['imdb_id'] ?? ''));
            if ($imdb !== '' && preg_match('/^tt\d+$/', $imdb)) {
                return $imdb;
            }
        } catch (Throwable $e) {
            // ignore
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{url:string}|null
     */
    private static function movie(string $imdb, array &$diagnostics): ?array
    {
        $pageUrl = self::BASE . '/movie/' . rawurlencode($imdb);
        $html = self::httpGet($pageUrl, [
            'User-Agent: ' . self::UA,
            'sec-fetch-dest: iframe',
            'Accept: text/html,*/*',
        ]);
        if ($html === null) {
            $diagnostics[] = [
                'code' => 'MEGASOURCE_PAGE',
                'message' => 'WatchPlay movie page failed',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }

        $videoId = self::pickVideoId($html);
        if ($videoId === null) {
            $diagnostics[] = [
                'code' => 'MEGASOURCE_EMPTY',
                'message' => 'No WatchPlay player id for ' . $imdb,
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        return self::getPlayer($videoId, $pageUrl, $diagnostics);
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{url:string}|null
     */
    private static function series(int $tmdbId, int $season, int $episode, array &$diagnostics): ?array
    {
        // WatchPlay TV pages are keyed by TMDB id.
        $pageUrl = self::BASE . '/tvshow/' . $tmdbId . '/' . $season . '/' . $episode;
        $html = self::httpGet($pageUrl, [
            'User-Agent: ' . self::UA,
            'sec-fetch-dest: iframe',
            'Accept: text/html,*/*',
        ]);
        if ($html === null) {
            return null;
        }

        $curS = null;
        $curE = null;
        if (preg_match("/CURRENT_SEASON\\s*=\\s*'(\\d+)'/", $html, $m)) {
            $curS = $m[1];
        }
        if (preg_match("/CURRENT_EPISODE\\s*=\\s*'(\\d+)'/", $html, $m)) {
            $curE = $m[1];
        }
        if ($curS === null) {
            $curS = (string) $season;
        }
        if ($curE === null) {
            $curE = (string) $episode;
        }

        $contentId = null;
        if (preg_match(
            '/data-contentid="(\\d+)"[^>]*data-season="' . preg_quote($curS, '/') . '"[^>]*data-episode="' . preg_quote($curE, '/') . '"/',
            $html,
            $m
        )) {
            $contentId = $m[1];
        } elseif (preg_match(
            '/data-season="' . preg_quote($curS, '/') . '"[^>]*data-episode="' . preg_quote($curE, '/') . '"[^>]*data-contentid="(\\d+)"/',
            $html,
            $m
        )) {
            $contentId = $m[1];
        }
        if ($contentId === null) {
            $diagnostics[] = [
                'code' => 'MEGASOURCE_EMPTY',
                'message' => 'No WatchPlay episode content id',
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }

        $opts = self::apiPost(['action' => 'getOptions', 'contentid' => $contentId], $pageUrl);
        $videoId = null;
        foreach (($opts['data']['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            if (!empty($option['ID'])) {
                $videoId = (string) $option['ID'];
                break;
            }
        }
        if ($videoId === null) {
            return null;
        }
        return self::getPlayer($videoId, $pageUrl, $diagnostics);
    }

    private static function pickVideoId(string $html): ?string
    {
        // Prefer Dublado/Português block, else first data-id.
        if (preg_match_all(
            '/<div class="players_select_container">(.*?)<\\/div>\\s*<\\/div>\\s*<\\/div>/si',
            $html,
            $blocks
        )) {
            foreach ($blocks[1] as $block) {
                if (stripos($block, 'Dublado') !== false && preg_match('/data-id="(\\d+)"/', $block, $m)) {
                    return $m[1];
                }
            }
            foreach ($blocks[1] as $block) {
                if (preg_match('/data-id="(\\d+)"/', $block, $m)) {
                    return $m[1];
                }
            }
        }
        if (preg_match('/data-id="(\\d+)"/', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{url:string}|null
     */
    private static function getPlayer(string $videoId, string $referer, array &$diagnostics): ?array
    {
        $json = self::apiPost(['action' => 'getPlayer', 'video_id' => $videoId], $referer);
        $url = trim((string) ($json['data']['video_url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $diagnostics[] = [
                'code' => 'MEGASOURCE_PLAYER',
                'message' => 'getPlayer returned no video_url',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $diagnostics[] = [
            'code' => 'MEGASOURCE_OK',
            'message' => 'WatchPlay player ok',
            'severity' => 'info',
            'provider' => self::PROVIDER_ID,
        ];
        return ['url' => $url];
    }

    /** @param array<string,string> $fields */
    private static function apiPost(array $fields, string $referer): array
    {
        if (!function_exists('curl_init')) {
            return [];
        }
        $ch = curl_init(self::BASE . '/api');
        if ($ch === false) {
            return [];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::UA,
                'Origin: ' . self::BASE,
                'Referer: ' . $referer,
                'X-Requested-With: XMLHttpRequest',
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json,*/*',
            ],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            return [];
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : [];
    }

    /** @param list<string> $headers */
    private static function httpGet(string $url, array $headers): ?string
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
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $status >= 400) {
            return null;
        }
        return $body;
    }
}
