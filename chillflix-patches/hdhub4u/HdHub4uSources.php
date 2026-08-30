<?php
declare(strict_types=1);

/**
 * HDHub4u — progressive MKV/MP4 via HubDrive → HubCloud → FSL CDN → v-relay.
 *
 * Domain map: hdhub4u.bi is only a gateway. Live catalog is resolved from the
 * host JSON APIs (currently new5.hdhub4u.cl). Site search is bot-blocked, so we
 * resolve pages by slug candidates + IMDb id match on the post.
 *
 * Movies only for now (series pack pages need episode unpacking).
 */
final class HdHub4uSources
{
    public const PROVIDER_ID = 'hdhub4u';
    public const PROVIDER_NAME = 'HDHub4u';

    private const GATEWAY = 'https://hdhub4u.bi';
    private const UA =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const PAGE_CACHE_TTL = 21600; // 6h
    private const CDN_CACHE_TTL = 1200; // 20m (FSL tokens expire)
    private const DOMAIN_CACHE_TTL = 3600;
    private const MAX_QUALITIES = 2;

    /** @var list<string> */
    private const HOST_APIS = [
        'https://h4.suncdn.org/host/',
        'https://points.topapii.com/host/',
        'https://ml.theapii.org/host/',
        'https://dns.pingora.fyi/v2/host',
        'https://cdn.hub4u.cloud/host/',
    ];

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
                'error' => 'HDHub4u is Vuflix-only for now',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'HDHUB4U_HOST_BLOCKED',
                    'message' => 'HDHub4u is Vuflix-only for now',
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
                'error' => 'HDHub4u TV not wired yet',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'HDHUB4U_TV_UNSUPPORTED',
                    'message' => 'Series packs need episode unpacking — movies only for now',
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
                    'code' => 'HDHUB4U_NO_TMDB',
                    'message' => 'TMDB details missing',
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ]],
            ];
        }

        $cookie = '';
        $site = self::resolveSite($cookie, $diagnostics);
        if ($site === null) {
            return [
                'ok' => false,
                'error' => 'Could not resolve HDHub4u live domain',
                'sources' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $page = self::findMoviePage($site, $meta, $cookie, $diagnostics);
        if ($page === null) {
            return [
                'ok' => false,
                'error' => 'Title not found on HDHub4u',
                'sources' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $packs = self::parseQualityPacks($page['html']);
        if ($packs === []) {
            $diagnostics[] = [
                'code' => 'HDHUB4U_NO_PACKS',
                'message' => 'No HubDrive/HubCDN quality buttons on ' . $page['url'],
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return [
                'ok' => false,
                'error' => 'No HDHub4u quality options',
                'sources' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        usort($packs, static fn($a, $b) => self::qualityRank($b['quality']) <=> self::qualityRank($a['quality']));
        $packs = array_slice($packs, 0, max(self::MAX_QUALITIES, 3));

        $resolved = [];
        foreach ($packs as $pack) {
            $cdn = null;
            $cacheKey = $page['url'] . '|' . $pack['quality'] . '|' . $pack['href'];
            $cachedCdn = self::cdnCacheGet($cacheKey);
            if ($cachedCdn !== null) {
                $probe = self::probeCdn($cachedCdn);
                if ($probe !== null) {
                    $cdn = $cachedCdn;
                }
            }
            if ($cdn === null) {
                $cdn = self::resolvePackToCdn($pack['href'], $page['url'], $cookie, $diagnostics);
                if ($cdn !== null) {
                    self::cdnCacheSet($cacheKey, $cdn);
                }
            }
            if ($cdn === null) {
                continue;
            }
            $playUrl = self::mint($cdn, [
                'Referer' => 'https://hubcloud.cx/',
                'Origin' => 'https://hubcloud.cx',
                'Accept' => '*/*',
                'User-Agent' => self::UA,
            ]);
            $resolved[] = [
                'quality' => $pack['quality'],
                'url' => $playUrl,
                'type' => 'file',
                'direct' => $cdn,
                'pack' => $pack['href'],
            ];
            if (count($resolved) >= self::MAX_QUALITIES) {
                break;
            }
        }

        if ($resolved === []) {
            return [
                'ok' => false,
                'error' => 'HDHub4u mirrors failed to resolve',
                'sources' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $best = $resolved[0];
        $qualities = [];
        foreach ($resolved as $row) {
            $qualities[] = [
                'quality' => $row['quality'],
                'url' => $row['url'],
                'type' => 'file',
            ];
        }

        $diagnostics[] = [
            'code' => 'HDHUB4U_OK',
            'message' => 'Resolved ' . count($resolved) . ' qualities via HubCloud/FSL',
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
                'language' => 'hi',
                'hasEnglish' => true,
                'qualities' => $qualities,
                'meta' => [
                    'backend' => 'hdhub4u-hubcloud',
                    'page' => $page['url'],
                    'site' => $site,
                    'title' => $meta['title'],
                    'year' => $meta['year'],
                    'imdb' => $meta['imdb'],
                    'direct' => $best['direct'],
                    'variants' => array_column($resolved, 'quality'),
                ],
            ]],
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function resolveSite(string &$cookie, array &$diagnostics): ?string
    {
        $cached = self::cacheGet('domain', self::DOMAIN_CACHE_TTL);
        if (is_string($cached) && preg_match('#^https://[a-z0-9.-]+#i', $cached)) {
            return rtrim($cached, '/');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $v = (int) $now->format('Y') * 1000000
            + (int) $now->format('m') * 10000
            + (int) $now->format('d') * 100
            + (int) $now->format('H')
            + 1;

        foreach (self::HOST_APIS as $api) {
            $url = $api . (str_contains($api, '?') ? '&' : '?') . 'v=' . $v;
            $res = self::httpGet($url, $cookie, self::GATEWAY . '/');
            if ($res === null || $res['status'] < 200 || $res['status'] >= 300) {
                continue;
            }
            $data = json_decode($res['body'], true);
            if (!is_array($data)) {
                continue;
            }
            $click = '';
            if (!empty($data['c']) && is_string($data['c'])) {
                $decoded = base64_decode($data['c'], true);
                if (is_string($decoded) && preg_match('#^https?://#i', $decoded)) {
                    $click = $decoded;
                }
            }
            if ($click !== '') {
                $parts = parse_url($click);
                if (is_array($parts) && !empty($parts['host'])) {
                    $site = 'https://' . strtolower((string) $parts['host']);
                    // Warm homepage (cookies / anti-bot).
                    self::httpGet($site . '/', $cookie, self::GATEWAY . '/');
                    self::cacheSet('domain', $site);
                    $diagnostics[] = [
                        'code' => 'HDHUB4U_DOMAIN',
                        'message' => 'Live catalog ' . $site,
                        'severity' => 'info',
                        'provider' => self::PROVIDER_ID,
                    ];
                    return $site;
                }
            }
        }

        // Fallbacks observed during probing.
        foreach (['https://new5.hdhub4u.cl', 'https://hdhub4u.cl'] as $fallback) {
            $res = self::httpGet($fallback . '/', $cookie, self::GATEWAY . '/');
            if ($res !== null && $res['status'] === 200 && str_contains($res['body'], 'wp-content')) {
                self::cacheSet('domain', $fallback);
                $diagnostics[] = [
                    'code' => 'HDHUB4U_DOMAIN_FALLBACK',
                    'message' => 'Using fallback ' . $fallback,
                    'severity' => 'info',
                    'provider' => self::PROVIDER_ID,
                ];
                return $fallback;
            }
        }

        $diagnostics[] = [
            'code' => 'HDHUB4U_DOMAIN_FAIL',
            'message' => 'Host JSON APIs and fallbacks failed',
            'severity' => 'error',
            'provider' => self::PROVIDER_ID,
        ];
        return null;
    }

    /**
     * @param array{title:string,year:?int,imdb:?string} $meta
     * @param list<array<string,mixed>> $diagnostics
     * @return array{url:string,html:string}|null
     */
    private static function findMoviePage(string $site, array $meta, string &$cookie, array &$diagnostics): ?array
    {
        $cacheKey = 'page|' . md5($meta['title'] . '|' . (string) ($meta['year'] ?? '') . '|' . (string) ($meta['imdb'] ?? ''));
        $cached = self::cacheGet($cacheKey, self::PAGE_CACHE_TTL);
        if (is_array($cached) && !empty($cached['url'])) {
            $res = self::httpGet((string) $cached['url'], $cookie, $site . '/');
            if ($res !== null && $res['status'] === 200 && self::pageMatches($res['body'], $meta)) {
                return ['url' => $res['url'], 'html' => $res['body']];
            }
        }

        $slugBase = self::slugify($meta['title']);
        $year = $meta['year'];
        $candidates = [];
        if ($year !== null && $year > 1900) {
            foreach ([
                '%s-%d-hindi-webrip-full-movie',
                '%s-%d-webrip-hindi-full-movie',
                '%s-%d-hindi-bluray-full-movie',
                '%s-%d-bluray-hindi-full-movie',
                '%s-%d-hindi-org-webrip-full-movie',
                '%s-%d-english-webrip-full-movie',
                '%s-%d-full-movie',
            ] as $fmt) {
                $candidates[] = sprintf($fmt, $slugBase, $year);
            }
        }
        $candidates[] = $slugBase . '-full-movie';
        $candidates = array_values(array_unique($candidates));

        foreach ($candidates as $slug) {
            $url = $site . '/' . $slug . '/';
            $res = self::httpGet($url, $cookie, $site . '/');
            if ($res === null || $res['status'] !== 200) {
                continue;
            }
            if (stripos($res['body'], 'Page not found') !== false) {
                continue;
            }
            if (!self::pageMatches($res['body'], $meta)) {
                continue;
            }
            self::cacheSet($cacheKey, ['url' => $res['url']]);
            $diagnostics[] = [
                'code' => 'HDHUB4U_PAGE',
                'message' => 'Matched ' . $res['url'],
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            return ['url' => $res['url'], 'html' => $res['body']];
        }

        // Homepage scan fallback (recent titles only).
        $home = self::httpGet($site . '/', $cookie, self::GATEWAY . '/');
        if ($home !== null && $home['status'] === 200) {
            $hit = self::scanListingForMeta($home['body'], $site, $meta, $cookie);
            if ($hit !== null) {
                self::cacheSet($cacheKey, ['url' => $hit['url']]);
                $diagnostics[] = [
                    'code' => 'HDHUB4U_PAGE_HOME',
                    'message' => 'Matched via homepage ' . $hit['url'],
                    'severity' => 'info',
                    'provider' => self::PROVIDER_ID,
                ];
                return $hit;
            }
        }

        $diagnostics[] = [
            'code' => 'HDHUB4U_NOT_FOUND',
            'message' => 'No slug/home match for ' . $meta['title'] . ' (' . (string) ($meta['year'] ?? '?') . ')',
            'severity' => 'warning',
            'provider' => self::PROVIDER_ID,
        ];
        return null;
    }

    /**
     * @param array{title:string,year:?int,imdb:?string} $meta
     * @return array{url:string,html:string}|null
     */
    private static function scanListingForMeta(string $html, string $site, array $meta, string &$cookie): ?array
    {
        if (!preg_match_all('#href=["\'](' . preg_quote($site, '#') . '/[^"\']+)["\']#i', $html, $m)) {
            return null;
        }
        $needle = self::slugify($meta['title']);
        $year = $meta['year'];
        $seen = [];
        foreach ($m[1] as $href) {
            if (isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;
            if (!preg_match('#/\d{4}[-/]|full-movie|webrip|bluray#i', $href)) {
                continue;
            }
            if ($needle !== '' && stripos($href, $needle) === false) {
                continue;
            }
            if ($year !== null && !str_contains($href, (string) $year)) {
                continue;
            }
            $res = self::httpGet($href, $cookie, $site . '/');
            if ($res === null || $res['status'] !== 200) {
                continue;
            }
            if (self::pageMatches($res['body'], $meta)) {
                return ['url' => $res['url'], 'html' => $res['body']];
            }
        }
        return null;
    }

    /**
     * @param array{title:string,year:?int,imdb:?string} $meta
     */
    private static function pageMatches(string $html, array $meta): bool
    {
        if (($meta['imdb'] ?? null) !== null && $meta['imdb'] !== '') {
            if (stripos($html, $meta['imdb']) !== false) {
                return true;
            }
        }
        $title = strtolower($meta['title']);
        $title = preg_replace('/[^a-z0-9]+/i', ' ', $title) ?? $title;
        $title = trim($title);
        if ($title === '') {
            return false;
        }
        $plain = strtolower(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;
        if (!str_contains($plain, $title)) {
            return false;
        }
        if ($meta['year'] !== null && !str_contains($plain, (string) $meta['year'])) {
            return false;
        }
        return true;
    }

    /**
     * @return list<array{quality:string,href:string,label:string}>
     */
    private static function parseQualityPacks(string $html): array
    {
        $packs = [];
        if (!preg_match_all('#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($m as $row) {
            $href = html_entity_decode(trim($row[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = trim(html_entity_decode(strip_tags($row[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $label = preg_replace('/\s+/', ' ', $label) ?? $label;
            if ($href === '' || $label === '') {
                continue;
            }
            $host = strtolower((string) (parse_url($href, PHP_URL_HOST) ?: ''));
            if ($host === '') {
                continue;
            }
            // Prefer HubDrive (HubCloud path). Skip greenmount cloakers / 4k sister site.
            $okHost = str_contains($host, 'hubdrive')
                || str_contains($host, 'hubcdn')
                || str_contains($host, 'hubcloud');
            if (!$okHost) {
                continue;
            }
            if (!preg_match('/\b(2160p|4k|1080p|720p|480p)\b/i', $label, $qm)) {
                continue;
            }
            $qraw = strtoupper($qm[1]);
            $quality = $qraw === '4K' ? '2160p' : strtolower($qraw);
            if (stripos($label, '10Bit') !== false || stripos($label, 'HEVC') !== false || stripos($label, 'x265') !== false) {
                $quality .= ' HEVC';
            }
            $packs[] = [
                'quality' => $quality,
                'href' => $href,
                'label' => $label,
            ];
        }

        // Dedupe by quality keeping first (higher in page = often preferred encode).
        $out = [];
        $seenQ = [];
        foreach ($packs as $p) {
            $key = strtolower($p['quality']);
            if (isset($seenQ[$key])) {
                continue;
            }
            // Prefer hubdrive over hubcdn for same quality.
            $seenQ[$key] = true;
            $out[] = $p;
        }
        // Re-build preferring hubdrive when duplicates existed: filter packs first.
        $hubdriveFirst = [];
        $rest = [];
        foreach ($packs as $p) {
            $host = strtolower((string) (parse_url($p['href'], PHP_URL_HOST) ?: ''));
            if (str_contains($host, 'hubdrive') || str_contains($host, 'hubcloud')) {
                $hubdriveFirst[] = $p;
            } else {
                $rest[] = $p;
            }
        }
        $ordered = array_merge($hubdriveFirst, $rest);
        $out = [];
        $seenQ = [];
        foreach ($ordered as $p) {
            $key = strtolower($p['quality']);
            if (isset($seenQ[$key])) {
                continue;
            }
            $seenQ[$key] = true;
            $out[] = $p;
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function resolvePackToCdn(string $packUrl, string $pageUrl, string &$cookie, array &$diagnostics): ?string
    {
        $host = strtolower((string) (parse_url($packUrl, PHP_URL_HOST) ?: ''));
        if (str_contains($host, 'hubcloud') && preg_match('#/drive/#i', $packUrl)) {
            return self::resolveHubCloudDrive($packUrl, $pageUrl, $cookie, $diagnostics);
        }

        $res = self::httpGet($packUrl, $cookie, $pageUrl);
        if ($res === null || $res['status'] !== 200) {
            $diagnostics[] = [
                'code' => 'HDHUB4U_PACK_FAIL',
                'message' => 'Pack fetch failed ' . $packUrl,
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }

        // HubDrive → HubCloud Server button.
        if (preg_match('#href=["\'](https://hubcloud\.[^"\']+/drive/[^"\']+)["\']#i', $res['body'], $m)) {
            return self::resolveHubCloudDrive(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $res['url'], $cookie, $diagnostics);
        }

        // HubCDN obfuscated redirect — try reurl base64 if present.
        if (preg_match('#var\s+reurl\s*=\s*["\']([^"\']+)["\']#i', $res['body'], $m)) {
            $reurl = $m[1];
            $parts = parse_url($reurl);
            $qs = [];
            if (!empty($parts['query'])) {
                parse_str((string) $parts['query'], $qs);
            }
            if (!empty($qs['r']) && is_string($qs['r'])) {
                $inner = base64_decode($qs['r'], true);
                if (is_string($inner) && preg_match('#^https?://#i', $inner)) {
                    // Often points at hubcdn dl gateway → still cloaked; skip unless direct media.
                    if (preg_match('#\.(mp4|mkv)(\?|$)#i', $inner)) {
                        return $inner;
                    }
                }
            }
        }

        $diagnostics[] = [
            'code' => 'HDHUB4U_PACK_NO_HUBCLOUD',
            'message' => 'No HubCloud link on ' . $packUrl,
            'severity' => 'warning',
            'provider' => self::PROVIDER_ID,
        ];
        return null;
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function resolveHubCloudDrive(string $driveUrl, string $referer, string &$cookie, array &$diagnostics): ?string
    {
        $res = self::httpGet($driveUrl, $cookie, $referer);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        $php = null;
        if (preg_match('#https?://[^"\'\s]+hubcloud\.php[^"\'\s]*#i', $res['body'], $m)) {
            $php = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('#id=["\']download["\'][^>]*href=["\']([^"\']+)["\']#i', $res['body'], $m)) {
            $php = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('#var\s+url\s*=\s*[\'"]([^\'"]+)[\'"]#i', $res['body'], $m)) {
            $php = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($php === null || $php === '') {
            $diagnostics[] = [
                'code' => 'HDHUB4U_NO_PHP',
                'message' => 'No hubcloud.php on drive page',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }

        $res2 = self::httpGet($php, $cookie, $driveUrl);
        if ($res2 === null || $res2['status'] !== 200) {
            return null;
        }

        $candidates = [];
        if (preg_match_all('#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $res2['body'], $mm, PREG_SET_ORDER)) {
            foreach ($mm as $row) {
                $href = html_entity_decode(trim($row[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = strtolower(trim(html_entity_decode(strip_tags($row[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($href === '' || !preg_match('#^https?://#i', $href)) {
                    continue;
                }
                $score = 0;
                if (str_contains($label, 'fsl') && !str_contains($label, 'fslv2')) {
                    $score = 100; // R2 signed URLs — best Range support
                } elseif (str_contains($label, 'fslv2')) {
                    $score = 90;
                } elseif (str_contains($label, 'download file')) {
                    $score = 80;
                } elseif (str_contains($label, '10gbps')) {
                    $score = 50;
                } elseif (str_contains($label, 's3')) {
                    $score = 40;
                } else {
                    continue;
                }
                $candidates[] = ['score' => $score, 'url' => $href, 'label' => $label];
            }
        }
        usort($candidates, static fn($a, $b) => $b['score'] <=> $a['score']);

        foreach ($candidates as $c) {
            $probe = self::probeCdn($c['url']);
            if ($probe === null) {
                continue;
            }
            $diagnostics[] = [
                'code' => 'HDHUB4U_CDN',
                'message' => 'Using ' . $c['label'] . ' (' . $probe['status'] . ')',
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            return $c['url'];
        }

        $diagnostics[] = [
            'code' => 'HDHUB4U_CDN_FAIL',
            'message' => 'No playable FSL mirror on hubcloud.php',
            'severity' => 'warning',
            'provider' => self::PROVIDER_ID,
        ];
        return null;
    }

    /** @return array{status:int,size:?int}|null */
    private static function probeCdn(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::UA,
                'Range: bytes=0-1',
                'Accept: */*',
                'Referer: https://hubcloud.cx/',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $clen = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        if (!is_string($body)) {
            return null;
        }
        if ($status !== 200 && $status !== 206) {
            return null;
        }
        // Reject HTML interstitial.
        $trim = ltrim($body);
        if (str_starts_with($trim, '<!') || str_starts_with(strtolower($trim), '<html')) {
            return null;
        }
        return [
            'status' => $status,
            'size' => is_numeric($clen) && (float) $clen > 0 ? (int) $clen : null,
        ];
    }

    private static function qualityRank(string $quality): int
    {
        $q = strtolower($quality);
        // Prefer 1080p for first play — 2160p packs are multi‑GB and slow to start.
        $rank = 0;
        if (str_contains($q, '1080')) {
            $rank = 1080;
        } elseif (str_contains($q, '720')) {
            $rank = 720;
        } elseif (str_contains($q, '2160') || str_contains($q, '4k')) {
            $rank = 600;
        } elseif (str_contains($q, '480')) {
            $rank = 480;
        }
        if (str_contains($q, 'hevc')) {
            $rank += 5;
        }
        return $rank;
    }

    private static function slugify(string $title): string
    {
        $s = strtolower($title);
        $s = preg_replace('/&/', ' and ', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9]+/i', '-', $s) ?? $s;
        return trim($s, '-');
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
            return VidmolySources::signedProxyUrl($direct, $headers, false);
        }
        if (class_exists('StremifySources') && method_exists('StremifySources', 'signedProxyUrl')) {
            return StremifySources::signedProxyUrl($direct, $headers);
        }
        return $direct;
    }

    /** @return array{status:int,url:string,body:string}|null */
    private static function httpGet(string $url, string &$cookie, string $referer): ?array
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
        $ch = curl_init($url);
        $responseHeaders = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 22,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$responseHeaders) {
                $responseHeaders .= $line;
                return strlen($line);
            },
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if (!is_string($body)) {
            return null;
        }
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

    private static function cacheDir(): string
    {
        $dir = sys_get_temp_dir() . '/vuflix-hdhub4u';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private static function cacheGet(string $key, int $ttl): mixed
    {
        $path = self::cacheDir() . '/' . sha1($key) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['t'], $data['v'])) {
            return null;
        }
        if ((time() - (int) $data['t']) > $ttl) {
            return null;
        }
        return $data['v'];
    }

    private static function cacheSet(string $key, mixed $value): void
    {
        $path = self::cacheDir() . '/' . sha1($key) . '.json';
        @file_put_contents($path, json_encode(['t' => time(), 'v' => $value], JSON_UNESCAPED_SLASHES));
    }

    private static function cdnCacheGet(string $key): ?string
    {
        $v = self::cacheGet('cdn|' . $key, self::CDN_CACHE_TTL);
        return is_string($v) && preg_match('#^https?://#i', $v) ? $v : null;
    }

    private static function cdnCacheSet(string $key, string $url): void
    {
        self::cacheSet('cdn|' . $key, $url);
    }
}
