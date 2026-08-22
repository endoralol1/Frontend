<?php
declare(strict_types=1);

/**
 * Moonflix — Cinemove's "Moon" fast source.
 *
 * Aliases on Cinemove: hdghar / vixsrc / vixcloud / streamingcommunity.
 * Primary: HDGharTV via HdgharSources (fast, works).
 * Optional: Worker /vixsrc when MOONFLIX_TRY_VIXSRC=1 (currently CF-blocked, slow).
 */
final class MoonflixSources
{
    public const PROVIDER_ID = 'moonflix';
    public const PROVIDER_NAME = 'Moonflix';

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

        // HDGharTV first — VixSrc via Worker is CF-blocked and only burns race time.
        if (class_exists('HdgharSources')) {
            $hg = HdgharSources::fetch($type, $tmdbId, $season, $episode);
            foreach ($hg['diagnostics'] ?? [] as $d) {
                if (is_array($d)) {
                    $d['provider'] = self::PROVIDER_ID;
                    $diagnostics[] = $d;
                }
            }
            $sources = [];
            foreach ($hg['sources'] ?? [] as $src) {
                if (!is_array($src) || empty($src['url'])) {
                    continue;
                }
                $src['provider'] = self::PROVIDER_ID;
                $src['providerName'] = self::PROVIDER_NAME;
                $label = (string) ($src['label'] ?? '');
                $src['label'] = $label !== ''
                    ? preg_replace('/^HDGHAR/i', 'Moonflix', $label) ?: ('Moonflix · ' . ($src['quality'] ?? 'Auto'))
                    : ('Moonflix · ' . ($src['quality'] ?? 'Auto'));
                $sources[] = $src;
            }
            if ($sources !== []) {
                return [
                    'ok' => true,
                    'sources' => $sources,
                    'diagnostics' => $diagnostics,
                    'error' => null,
                    'meta' => ['backend' => 'hdghartv'],
                ];
            }
        } else {
            $diagnostics[] = [
                'code' => 'MOONFLIX_HDGHAR_MISSING',
                'message' => 'HdgharSources not loaded',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
        }

        $tryVix = getenv('MOONFLIX_TRY_VIXSRC');
        if ($tryVix === '1' || $tryVix === 'true') {
            $vix = self::viaWorkerVixsrc($type, $tmdbId, max(1, $season), max(1, $episode), $diagnostics);
            if ($vix !== null) {
                return [
                    'ok' => true,
                    'sources' => [$vix],
                    'diagnostics' => $diagnostics,
                    'error' => null,
                    'meta' => ['backend' => 'vixsrc-worker'],
                ];
            }
        }

        return [
            'ok' => false,
            'sources' => [],
            'diagnostics' => $diagnostics ?: [[
                'code' => 'MOONFLIX_EMPTY',
                'message' => 'No Moonflix streams',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ]],
            'error' => 'No Moonflix streams',
        ];
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array<string,mixed>|null
     */
    private static function viaWorkerVixsrc(
        string $type,
        int $tmdbId,
        int $season,
        int $episode,
        array &$diagnostics
    ): ?array {
        if (!class_exists('WorkerRelayService')) {
            return null;
        }
        $worker = WorkerRelayService::pickWorker();
        if ($worker === null || empty($worker['url']) || empty($worker['secret'])) {
            $diagnostics[] = [
                'code' => 'MOONFLIX_NO_WORKER',
                'message' => 'No Worker relay configured for VixSrc',
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }

        $qs = http_build_query([
            'type' => $type,
            'tmdb' => $tmdbId,
            'season' => $season,
            'episode' => $episode,
            'key' => (string) $worker['secret'],
        ]);
        $url = rtrim((string) $worker['url'], '/') . '/vixsrc?' . $qs;
        $raw = self::httpGet($url, [
            'Accept: application/json',
            'x-yoru-key: ' . (string) $worker['secret'],
            'User-Agent: VuflixMoonflix/1.0',
        ]);
        if ($raw === null) {
            $diagnostics[] = [
                'code' => 'MOONFLIX_VIXSRC_HTTP',
                'message' => 'Worker /vixsrc request failed',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['ok'])) {
            $err = is_array($json) ? (string) ($json['error'] ?? 'vixsrc failed') : 'bad JSON';
            $diagnostics[] = [
                'code' => 'MOONFLIX_VIXSRC_FAIL',
                'message' => $err,
                'severity' => !empty($json['cfBlocked']) ? 'warning' : 'info',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }

        $streamUrl = trim((string) ($json['url'] ?? $json['stream'] ?? ''));
        if ($streamUrl === '' || !preg_match('#^https?://#i', $streamUrl)) {
            return null;
        }

        $quality = trim((string) ($json['quality'] ?? 'Auto')) ?: 'Auto';
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer' => 'https://vixsrc.to/',
            'Origin' => 'https://vixsrc.to',
        ];
        if (is_array($json['headers'] ?? null)) {
            foreach ($json['headers'] as $hk => $hv) {
                $hk = trim((string) $hk);
                $hv = trim((string) $hv);
                if ($hk !== '' && $hv !== '') {
                    $headers[$hk] = $hv;
                }
            }
        }

        // Prefer English audio when lang-proxy is available.
        if (class_exists('StreamLangProxy') && preg_match('/\.m3u8(\?|$)/i', $streamUrl)) {
            $streamUrl = StreamLangProxy::mintMasterPrefer($streamUrl, 'eng');
        }

        $diagnostics[] = [
            'code' => 'MOONFLIX_OK',
            'message' => 'VixSrc via Worker',
            'severity' => 'info',
            'provider' => self::PROVIDER_ID,
        ];

        return [
            'url' => $streamUrl,
            'type' => 'hls',
            'quality' => $quality,
            'provider' => self::PROVIDER_ID,
            'providerName' => self::PROVIDER_NAME,
            'label' => 'Moonflix · ' . $quality,
            'language' => 'en',
            'hasEnglish' => true,
            'preferAudio' => 'en',
            'headers' => $headers,
            'meta' => ['backend' => 'vixsrc-worker'],
        ];
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
            CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            return null;
        }
        // Workers may return 502 with JSON {ok:false,cfBlocked:true} — still parse.
        if ($status >= 500 && $status !== 502) {
            return null;
        }
        return $body;
    }
}
