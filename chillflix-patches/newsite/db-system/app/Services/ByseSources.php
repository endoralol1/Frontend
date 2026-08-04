<?php
declare(strict_types=1);

/**
 * Byse / gn1r5n.org resolver (HiMovies "UpCloud", and many mislabeled "Vidmoly" buttons).
 *
 * Flow (HTTP + Node PoW helper, no browser):
 *   0123movie.space/mv|{vmf}/{imdb}/{tmdb}/ → gn1r5n.org/e/{code}
 *   → embed/captcha PoW → verify → embed/playback (AES-GCM) → HLS master.m3u8
 */
final class ByseSources
{
    public const PROVIDER_ID = 'upcloud';
    public const PROVIDER_NAME = 'UpCloud';

    private const WRAPPER_HOST = 'https://0123movie.space';
    private const SITE_REFERER = 'https://himovies.watch/';

    /**
     * @return array{ok:bool,sources:list<array<string,mixed>>,error?:string,diagnostics?:list<array<string,mixed>>,meta?:array<string,mixed>}
     */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        if ($tmdbId < 1) {
            return ['ok' => false, 'sources' => [], 'error' => 'Invalid tmdbId'];
        }

        $diagnostics = [];
        $resolved = self::resolve($type, $tmdbId, $season, $episode, $diagnostics);
        if (empty($resolved['ok']) || empty($resolved['sources'])) {
            return [
                'ok' => false,
                'sources' => [],
                'error' => (string) ($resolved['error'] ?? 'Byse/UpCloud resolve failed'),
                'diagnostics' => $diagnostics,
                'meta' => $resolved['meta'] ?? [],
            ];
        }

        $sources = [];
        foreach ($resolved['sources'] as $src) {
            if (!is_array($src) || empty($src['url'])) {
                continue;
            }
            $direct = (string) $src['url'];
            $quality = (string) ($src['quality'] ?? 'Auto');
            $base = (string) ($resolved['base'] ?? 'https://gn1r5n.org');
            $headers = [
                'Referer' => rtrim($base, '/') . '/',
                'Origin' => rtrim($base, '/'),
                'Accept' => '*/*',
            ];
            $playUrl = class_exists('VidmolySources')
                ? VidmolySources::signedProxyUrl($direct, $headers, true)
                : (class_exists('StremifySources')
                    ? StremifySources::signedProxyUrl($direct, $headers)
                    : $direct);

            $sources[] = [
                'url' => $playUrl,
                'type' => 'hls',
                'quality' => $quality,
                'provider' => self::PROVIDER_ID,
                'providerName' => self::PROVIDER_NAME,
                'label' => 'UpCloud' . ($quality !== '' && strcasecmp($quality, 'Auto') !== 0 ? (' · ' . $quality) : ''),
                'language' => '',
                'audioTracks' => [],
                'meta' => [
                    'via' => 'byse',
                    'host' => 'byse',
                    'code' => $resolved['code'] ?? null,
                    'direct' => $direct,
                    'title' => $resolved['title'] ?? null,
                    'proxied' => $playUrl !== $direct,
                ],
            ];
        }

        return [
            'ok' => $sources !== [],
            'sources' => $sources,
            'diagnostics' => $diagnostics,
            'meta' => [
                'code' => $resolved['code'] ?? null,
                'base' => $resolved['base'] ?? null,
                'title' => $resolved['title'] ?? null,
            ],
        ];
    }

    /**
     * Resolve from an already-known Byse embed URL or file code.
     *
     * @param list<array<string,mixed>> $diagnostics
     * @return array{ok:bool,sources?:list<array<string,mixed>>,error?:string,code?:string,base?:string,title?:?string,meta?:array<string,mixed>}
     */
    public static function resolveFromEmbed(string $embedUrl, array &$diagnostics = []): array
    {
        if (!preg_match('#https?://([^/]+)/e/([a-zA-Z0-9]+)#', $embedUrl, $m)) {
            return ['ok' => false, 'error' => 'Not a Byse embed URL'];
        }
        $base = 'https://' . $m[1];
        $code = $m[2];
        return self::runNode(['--code', $code, '--referer', self::SITE_REFERER], $diagnostics, $base, $code);
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{ok:bool,sources?:list<array<string,mixed>>,error?:string,code?:string,base?:string,title?:?string,meta?:array<string,mixed>}
     */
    private static function resolve(string $type, int $tmdbId, int $season, int $episode, array &$diagnostics): array
    {
        if ($type === 'tv') {
            // HiMovies TV UpCloud wrapper: /pl/{tmdb}/{s}/{e}/
            $wrapper = self::WRAPPER_HOST . '/pl/' . $tmdbId . '/' . max(1, $season) . '/' . max(1, $episode) . '/';
            return self::runNode(['--wrapper', $wrapper, '--referer', self::SITE_REFERER], $diagnostics);
        }

        $imdb = self::imdbIdFor('movie', $tmdbId);
        if ($imdb === null) {
            $diagnostics[] = [
                'code' => 'UPCLOUD_NO_IMDB',
                'message' => 'IMDb id required for UpCloud/Byse movies',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return ['ok' => false, 'error' => 'IMDb id required'];
        }

        // Prefer /mv/ (UpCloud). If that fails, try /vmf/ (often same Byse target).
        foreach (['mv', 'vmf'] as $kind) {
            $wrapper = self::WRAPPER_HOST . '/' . $kind . '/' . rawurlencode($imdb) . '/' . $tmdbId . '/';
            $out = self::runNode(['--wrapper', $wrapper, '--referer', self::SITE_REFERER], $diagnostics);
            if (!empty($out['ok'])) {
                $out['meta'] = ['wrapper' => $wrapper, 'kind' => $kind];
                return $out;
            }
        }
        return ['ok' => false, 'error' => 'Byse wrappers failed for this title'];
    }

    /**
     * @param list<string> $args
     * @param list<array<string,mixed>> $diagnostics
     * @return array{ok:bool,sources?:list<array<string,mixed>>,error?:string,code?:string,base?:string,title?:?string,meta?:array<string,mixed>}
     */
    private static function runNode(array $args, array &$diagnostics, ?string $base = null, ?string $code = null): array
    {
        $script = self::resolverScript();
        if ($script === null) {
            $diagnostics[] = [
                'code' => 'BYSE_HELPER_MISSING',
                'message' => 'byse-resolve.mjs not found or node unavailable',
                'severity' => 'error',
                'provider' => self::PROVIDER_ID,
            ];
            return ['ok' => false, 'error' => 'Byse helper missing'];
        }

        $cmd = array_merge(['node', $script], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, dirname($script), [
            'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ]);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Failed to start Byse helper'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $codeExit = proc_close($proc);

        $json = json_decode(trim($stdout), true);
        if (!is_array($json)) {
            $diagnostics[] = [
                'code' => 'BYSE_HELPER_BAD_OUTPUT',
                'message' => 'Byse helper returned non-JSON (exit ' . $codeExit . ')',
                'severity' => 'error',
                'provider' => self::PROVIDER_ID,
            ];
            return ['ok' => false, 'error' => 'Byse helper bad output', 'meta' => ['stderr' => substr($stderr, 0, 300)]];
        }
        if (empty($json['ok'])) {
            $diagnostics[] = [
                'code' => 'BYSE_RESOLVE_FAILED',
                'message' => (string) ($json['error'] ?? 'Byse resolve failed'),
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return [
                'ok' => false,
                'error' => (string) ($json['error'] ?? 'Byse resolve failed'),
                'meta' => $json,
            ];
        }

        return [
            'ok' => true,
            'sources' => is_array($json['sources'] ?? null) ? $json['sources'] : [],
            'code' => (string) ($json['code'] ?? $code ?? ''),
            'base' => (string) ($json['base'] ?? $base ?? 'https://gn1r5n.org'),
            'title' => isset($json['title']) ? (string) $json['title'] : null,
            'meta' => $json,
        ];
    }

    private static function resolverScript(): ?string
    {
        $candidates = [
            dirname(__DIR__, 2) . '/bin/byse-resolve.mjs',
            '/var/www/chillflix-newsite/bin/byse-resolve.mjs',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                // ensure node exists
                $which = trim((string) shell_exec('command -v node 2>/dev/null'));
                if ($which === '') {
                    return null;
                }
                return $path;
            }
        }
        return null;
    }

    private static function imdbIdFor(string $type, int $tmdbId): ?string
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
}
