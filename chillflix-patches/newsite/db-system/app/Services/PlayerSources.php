<?php
declare(strict_types=1);

/**
 * Thin, clean sources client for newsite /player.
 * Pulls VAPlayer + Huhu from the main Chillflix API and normalizes the payload.
 * Also fetches external subtitle lists (VDRK + OpenSubtitles).
 */
final class PlayerSources
{
    /** @return array{ok:bool,sources?:list<array<string,mixed>>,subtitles?:list<array<string,mixed>>,error?:string,meta?:array<string,mixed>} */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
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

        $origin = rtrim((string) config('player_main_api', config('main_origin', 'https://www.chillflix.lol')), '/');
        $merged = [];
        $subtitles = [];
        $diagnostics = [];
        $playbackToken = null;

        foreach ($providers as $provider) {
            $provider = strtolower(trim((string) $provider));
            if ($provider === '') {
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
            $payload = self::httpGetJson($url, $origin);
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
                        $langUrl = self::withQueryParam($streamUrl, 'lang', $hl['code']);
                        $langKey = $pid . '|' . $hl['code'] . '|' . $langUrl;
                        if (isset($merged[$langKey])) {
                            continue;
                        }
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
                                    'switchUrl' => self::withQueryParam($streamUrl, 'lang', 'de'),
                                ],
                                [
                                    'id' => 'huhu-en',
                                    'language' => 'en',
                                    'label' => 'English',
                                    'switchUrl' => self::withQueryParam($streamUrl, 'lang', 'en'),
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

        $sources = array_values($merged);
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
     * External subtitle catalogue (VDRK VTT + OpenSubtitles).
     * @return array{ok:bool,subtitles:list<array<string,mixed>>,error?:string}
     */
    public static function fetchSubtitles(string $type, int $tmdbId, ?string $imdbId = null, int $season = 1, int $episode = 1): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        if ($tmdbId < 1) {
            return ['ok' => false, 'subtitles' => [], 'error' => 'Invalid tmdbId'];
        }
        $subs = [];

        // VDRK — ready-to-use VTT
        $vdrkUrl = $type === 'tv'
            ? "https://sub.vdrk.site/v1/tv/{$tmdbId}/" . max(1, $season) . '/' . max(1, $episode)
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

        // OpenSubtitles (needs imdb)
        $imdb = $imdbId ? trim($imdbId) : '';
        if ($imdb !== '' && str_starts_with($imdb, 'tt')) {
            $path = 'https://rest.opensubtitles.org/search/';
            if ($type === 'tv') {
                $path .= 'episode-' . max(1, $episode) . '/imdbid-' . substr($imdb, 2) . '/season-' . max(1, $season);
            } else {
                $path .= 'imdbid-' . substr($imdb, 2);
            }
            $os = self::httpGetJsonRaw($path, [
                'X-User-Agent: VLSub 0.10.2',
                'Accept: application/json',
            ]);
            if (is_array($os)) {
                foreach ($os as $row) {
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
                }
            }
        }

        $list = array_values($subs);
        // Prefer common languages first
        usort($list, static function (array $a, array $b): int {
            $prio = ['en' => 0, 'de' => 1, 'es' => 2, 'fr' => 3, 'it' => 4, 'pt' => 5, 'nl' => 6];
            $pa = $prio[$a['language'] ?? ''] ?? 50;
            $pb = $prio[$b['language'] ?? ''] ?? 50;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcasecmp((string) $a['label'], (string) $b['label']);
        });

        return [
            'ok' => true,
            'subtitles' => $list,
            'count' => count($list),
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
        $raw = self::httpGetRaw($url, [
            'User-Agent: ChillflixNewsitePlayer/1.0',
            'Accept: text/vtt, text/plain, */*',
        ]);
        if ($raw === null || $raw === '') {
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Caption fetch failed']);
            exit;
        }
        $body = $raw;
        if (!str_starts_with(ltrim($body), 'WEBVTT')) {
            $body = self::srtToVtt($body);
        }
        header('Content-Type: text/vtt; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Access-Control-Allow-Origin: *');
        echo $body;
        exit;
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
    private static function httpGetJsonRaw(string $url, array $headers = [], ?string $origin = null): ?array
    {
        $raw = self::httpGetRaw($url, $headers, $origin);
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @param list<string> $headers */
    private static function httpGetRaw(string $url, array $headers = [], ?string $origin = null): ?string
    {
        if (!$headers) {
            $headers = ['Accept: */*', 'User-Agent: ChillflixNewsitePlayer/1.0'];
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 18,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (($raw === false || $raw === '') && $origin && str_contains($url, 'chillflix.lol')) {
            $loop = preg_replace('@^https?://[^/]+@', 'http://127.0.0.1:3000', $url) ?? $url;
            $hdrs = $headers;
            array_unshift($hdrs, 'Host: www.chillflix.lol');
            $ctx2 = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 18,
                    'header' => implode("\r\n", $hdrs),
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($loop, false, $ctx2);
        }
        if ($raw === false || $raw === '') {
            return null;
        }
        return $raw;
    }
}
