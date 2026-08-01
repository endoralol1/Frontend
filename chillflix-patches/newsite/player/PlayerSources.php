<?php
declare(strict_types=1);

/**
 * Thin, clean sources client for newsite /player.
 * Pulls VAPlayer + Huhu from the main Chillflix API and normalizes the payload.
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
                $label = trim($name . ($quality !== '' ? (' · ' . $quality) : ''));
                $merged[$key] = [
                    'id' => substr(sha1($key), 0, 12),
                    'provider' => $pid,
                    'providerName' => $name,
                    'label' => $label !== '' ? $label : $pid,
                    'quality' => $quality,
                    'type' => (string) ($src['type'] ?? 'hls'),
                    'url' => $streamUrl,
                ];
            }
            foreach ($payload['subtitles'] ?? [] as $sub) {
                if (!is_array($sub) || empty($sub['src'])) {
                    continue;
                }
                $sid = (string) ($sub['id'] ?? sha1((string) $sub['src']));
                $subtitles[$sid] = [
                    'id' => $sid,
                    'label' => (string) ($sub['label'] ?? 'Subtitle'),
                    'language' => (string) ($sub['language'] ?? ''),
                    'kind' => (string) ($sub['kind'] ?? 'subtitles'),
                    'src' => self::absolutizeUrl((string) $sub['src'], $origin),
                ];
            }
        }

        $sources = array_values($merged);
        if (!$sources) {
            return [
                'ok' => false,
                'error' => 'No playable sources from VAPlayer/Huhu right now.',
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
            'sources' => $sources,
            'subtitles' => array_values($subtitles),
            'playbackToken' => $playbackToken,
            'diagnostics' => array_values($diagnostics),
            'fetchedAt' => gmdate('c'),
        ];
    }

    private static function forceHttps(string $url): string
    {
        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }
        return $url;
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

    /** @return array<string,mixed>|null */
    private static function httpGetJson(string $url, string $origin): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 18,
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'User-Agent: ChillflixNewsitePlayer/1.0',
                    'Origin: ' . $origin,
                    'Referer: ' . $origin . '/',
                ]),
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            // retry via loopback Host header if public DNS is awkward from VPS
            $loop = preg_replace('@^https?://[^/]+@', 'http://127.0.0.1:3000', $url) ?? $url;
            $ctx2 = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 18,
                    'header' => implode("\r\n", [
                        'Accept: application/json',
                        'Host: www.chillflix.lol',
                        'User-Agent: ChillflixNewsitePlayer/1.0',
                        'Origin: ' . $origin,
                        'Referer: ' . $origin . '/',
                    ]),
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($loop, false, $ctx2);
        }
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
