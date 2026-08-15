<?php
declare(strict_types=1);

/**
 * Vuflix-only scrapers via Nxsha Sources Proxy (fantastic-flow / Main·Premium).
 *
 * Backend: https://fantastic-flow-production-4da0.up.railway.app
 * Scrapers: castle, awsind, nitro, rive-primevids  (provider path "all" = combined)
 *
 * Language policy: English preferred as default URL/language; other langs exposed as audioTracks.
 */
final class NxshaSources
{
    public const BASE = 'https://fantastic-flow-production-4da0.up.railway.app';

    public const PROVIDER_MAP = [
        'castle' => 'castle',
        'awsind' => 'awsind',
        'nitro' => 'nitro',
        'riveprime' => 'rive-primevids',
        'nxsha' => 'all',
    ];

    public const LABELS = [
        'castle' => 'Castle',
        'awsind' => 'AwsInd',
        'nitro' => 'Nitro',
        'riveprime' => 'Rive Prime',
        'nxsha' => 'Nxsha',
        'rive-primevids' => 'Rive Prime',
        'ipcloud' => 'IP Cloud',
    ];

    public static function hostAllowed(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        return $host === 'vuflix.co' || $host === 'localhost' || $host === '127.0.0.1';
    }

    public static function supports(string $providerId): bool
    {
        return isset(self::PROVIDER_MAP[strtolower(trim($providerId))]);
    }

    /**
     * @return array{ok:bool,sources:list<array<string,mixed>>,error?:string,diagnostics?:list<array<string,mixed>>,meta?:array<string,mixed>}
     */
    public static function fetch(string $providerId, string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        $providerId = strtolower(trim($providerId));
        $diagnostics = [];

        if (!self::hostAllowed()) {
            return [
                'ok' => false,
                'error' => 'Nxsha scrapers are Vuflix-only',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'NXSHA_HOST_BLOCKED',
                    'message' => 'Nxsha scrapers are Vuflix-only',
                    'severity' => 'info',
                    'provider' => $providerId,
                ]],
            ];
        }

        if (!isset(self::PROVIDER_MAP[$providerId]) || $tmdbId < 1) {
            return ['ok' => false, 'error' => 'Invalid provider/TMDB', 'sources' => [], 'diagnostics' => []];
        }

        $apiProvider = self::PROVIDER_MAP[$providerId];
        $type = $type === 'tv' ? 'tv' : 'movie';
        if ($type === 'tv') {
            $url = sprintf(
                '%s/tv/%d/%d/%d/%s',
                self::BASE,
                $tmdbId,
                max(1, $season),
                max(1, $episode),
                rawurlencode($apiProvider)
            );
        } else {
            $url = sprintf('%s/movie/%d/%s', self::BASE, $tmdbId, rawurlencode($apiProvider));
        }

        $payload = self::httpGetJson($url);
        if (!is_array($payload)) {
            return [
                'ok' => false,
                'error' => 'Nxsha fetch failed',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'NXSHA_HTTP',
                    'message' => 'Nxsha fetch failed',
                    'severity' => 'warning',
                    'provider' => $providerId,
                ]],
            ];
        }

        // Railway returns 404 JSON {"status":"error","message":"Application not found"} when the
        // fantastic-flow service is undeployed — treat as backend down, not an empty title scrape.
        $railMsg = strtolower((string) ($payload['message'] ?? ''));
        $railStatus = strtolower((string) ($payload['status'] ?? ''));
        if (($railStatus === 'error' || isset($payload['request_id'])) && (
            str_contains($railMsg, 'application not found')
            || ((int) ($payload['code'] ?? 0) === 404 && empty($payload['sources']))
        )) {
            return [
                'ok' => false,
                'error' => 'Castle/Nxsha backend is down (Railway app not found)',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'NXSHA_BACKEND_DOWN',
                    'message' => 'Castle backend offline: ' . self::BASE . ' returned Application not found',
                    'severity' => 'error',
                    'provider' => $providerId,
                ]],
            ];
        }

        $rawSources = [];
        if ($apiProvider === 'all') {
            foreach ($payload as $key => $block) {
                if (!is_string($key) || str_starts_with($key, '_') || !is_array($block)) {
                    continue;
                }
                foreach ($block['sources'] ?? [] as $src) {
                    if (is_array($src)) {
                        if (empty($src['provider'])) {
                            $src['provider'] = $key;
                        }
                        $rawSources[] = $src;
                    }
                }
            }
        } else {
            if (!empty($payload['error']) && empty($payload['sources'])) {
                $msg = is_string($payload['error']) ? $payload['error'] : 'Nxsha empty';
                return [
                    'ok' => false,
                    'error' => $msg,
                    'sources' => [],
                    'diagnostics' => [[
                        'code' => 'NXSHA_API',
                        'message' => $msg,
                        'severity' => 'warning',
                        'provider' => $providerId,
                    ]],
                ];
            }
            foreach ($payload['sources'] ?? [] as $src) {
                if (is_array($src)) {
                    $rawSources[] = $src;
                }
            }
        }

        $normalized = [];
        foreach ($rawSources as $src) {
            $streamUrl = trim((string) ($src['url'] ?? $src['org_uri'] ?? ''));
            if ($streamUrl === '' || !preg_match('#^https?://#i', $streamUrl) || !empty($src['isEmbed'])) {
                continue;
            }
            $sub = strtolower((string) ($src['provider'] ?? $providerId));
            $adminId = $sub === 'rive-primevids' ? 'riveprime' : $sub;
            $qualityRaw = trim((string) ($src['quality'] ?? $src['label'] ?? 'Auto'));
            $langs = self::extractLanguages($qualityRaw);
            $resolution = self::extractResolution($qualityRaw) ?: 'Auto';
            // Prefer English as declared language when listed; never default to Hindi just because it is first.
            $lang = self::preferredLanguage($langs, $qualityRaw);
            $labelName = self::LABELS[$adminId] ?? self::LABELS[$sub] ?? ucfirst($sub);
            if (strcasecmp($qualityRaw, 'ipcloud') === 0) {
                $labelName = 'IP Cloud';
                $resolution = 'Auto';
            }

            $normalized[] = [
                'url' => $streamUrl,
                'type' => self::guessType($streamUrl, (string) ($src['type'] ?? '')),
                'quality' => $resolution,
                'qualityRaw' => $qualityRaw,
                'provider' => $providerId === 'nxsha' ? $adminId : $providerId,
                'providerName' => $labelName,
                'language' => $lang,
                'languages' => $langs,
                'apiProvider' => $sub,
                'sourceId' => (string) ($src['id'] ?? ''),
            ];
        }

        if ($normalized === []) {
            return [
                'ok' => false,
                'error' => 'No Nxsha streams',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'NXSHA_EMPTY',
                    'message' => 'No Nxsha streams for ' . $providerId,
                    'severity' => 'warning',
                    'provider' => $providerId,
                ]],
            ];
        }

        $sources = self::collapseToPlayerSources($providerId, $normalized);

        return [
            'ok' => $sources !== [],
            'sources' => $sources,
            'error' => $sources === [] ? 'No Nxsha streams' : null,
            'diagnostics' => $diagnostics,
            'meta' => [
                'backend' => self::BASE,
                'apiProvider' => $apiProvider,
                'count' => count($sources),
            ],
        ];
    }

    /**
     * Collapse resolution/language variants into one (or few) player sources.
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private static function collapseToPlayerSources(string $providerId, array $items): array
    {
        // awsind / language-split: one card, English default, switchable audio URLs
        $langSplit = true;
        foreach ($items as $it) {
            $q = strtolower((string) ($it['qualityRaw'] ?? ''));
            // If quality is mostly a language name, treat as language-split.
            if (self::extractResolution((string) ($it['qualityRaw'] ?? '')) !== '' && count($it['languages'] ?? []) > 1) {
                $langSplit = false;
            }
            if (preg_match('/\b\d{3,4}p\b/i', (string) ($it['qualityRaw'] ?? ''))) {
                $langSplit = false;
            }
        }
        // Castle style: same langs on every item, different resolutions → qualities pack
        $allMultiLang = true;
        foreach ($items as $it) {
            if (count($it['languages'] ?? []) < 2) {
                $allMultiLang = false;
                break;
            }
        }

        if ($providerId === 'awsind' || ($langSplit && !$allMultiLang && count($items) > 1 && self::looksLikeLanguagePack($items))) {
            return [self::packLanguageSources($providerId, $items)];
        }

        if ($providerId === 'castle' || $allMultiLang || count($items) > 1 && self::sameProviderDifferentRes($items)) {
            return [self::packQualitySources($providerId, $items)];
        }

        // nitro / rive / single
        $out = [];
        foreach ($items as $it) {
            $langs = $it['languages'] ?: array_values(array_filter([$it['language']]));
            $hasEnglish = self::hasEnglish($langs) || self::isEnglishCode((string) $it['language']);
            $play = self::proxyPlayUrl((string) $it['url'], (string) ($it['apiProvider'] ?? ''));
            $out[] = [
                'url' => $play,
                'type' => $it['type'],
                'quality' => $it['quality'],
                'provider' => $it['provider'],
                'providerName' => $it['providerName'],
                'label' => $it['providerName'] . ' · ' . ($hasEnglish ? 'English' : self::displayLang((string) $it['language'])) . ($it['quality'] !== 'Auto' ? (' · ' . $it['quality']) : ''),
                'language' => $hasEnglish ? 'en' : self::langCode((string) $it['language']),
                'hasEnglish' => $hasEnglish,
                'audioTracks' => self::tracksFromLangList($langs, $play, false),
                'meta' => [
                    'backend' => 'nxsha',
                    'apiProvider' => $it['apiProvider'],
                    'languages' => $langs,
                ],
            ];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $items */
    private static function looksLikeLanguagePack(array $items): bool
    {
        $langish = 0;
        foreach ($items as $it) {
            $raw = strtolower(trim((string) ($it['qualityRaw'] ?? $it['language'] ?? '')));
            if (preg_match('/^(english|hindi|tamil|telugu|spanish|portuguese|original|ipcloud)$/i', $raw)) {
                $langish++;
            }
        }
        return $langish >= max(1, (int) floor(count($items) * 0.6));
    }

    /** @param list<array<string,mixed>> $items */
    private static function sameProviderDifferentRes(array $items): bool
    {
        $res = [];
        foreach ($items as $it) {
            $r = self::extractResolution((string) ($it['qualityRaw'] ?? $it['quality'] ?? ''));
            if ($r !== '') {
                $res[$r] = true;
            }
        }
        return count($res) > 1;
    }

    /** @param list<array<string,mixed>> $items */
    private static function packQualitySources(string $providerId, array $items): array
    {
        $name = (string) ($items[0]['providerName'] ?? self::LABELS[$providerId] ?? ucfirst($providerId));
        $langs = [];
        foreach ($items as $it) {
            foreach ($it['languages'] ?? [] as $l) {
                $langs[strtolower((string) $l)] = self::displayLang((string) $l);
            }
        }
        $langList = array_values($langs);
        if ($langList === []) {
            $langList = ['English'];
        }
        $hasEnglish = self::hasEnglish($langList);

        $qualities = [];
        foreach ($items as $it) {
            $qualities[] = [
                'quality' => $it['quality'] !== 'Auto' ? $it['quality'] : (self::extractResolution((string) $it['qualityRaw']) ?: 'Auto'),
                'url' => $it['url'],
                'type' => $it['type'],
            ];
        }
        usort($qualities, static fn($a, $b) => self::qualityRank($b['quality']) <=> self::qualityRank($a['quality']));
        $rawQualities = $qualities;

        // Castle MPEG-TS embeds hin/eng/tam/tel. Remux via lang-proxy so English is the only/default audio.
        if (class_exists('StreamLangProxy') && $hasEnglish) {
            $qualities = [];
            foreach ($rawQualities as $q) {
                $qualities[] = [
                    'quality' => $q['quality'],
                    'url' => StreamLangProxy::mintTsPlaylist((string) $q['url'], 'eng'),
                    'type' => $q['type'],
                ];
            }
        }
        $best = $qualities[0];
        $rawBest = (string) ($rawQualities[0]['url'] ?? $best['url']);

        $audioTracks = [];
        if (class_exists('StreamLangProxy') && $hasEnglish) {
            foreach (['en' => 'eng', 'hi' => 'hin', 'ta' => 'tam', 'te' => 'tel'] as $code => $iso) {
                $label = self::displayLang($code);
                $present = $code === 'en';
                foreach ($langList as $ln) {
                    if (self::langCode($ln) === $code) {
                        $present = true;
                        break;
                    }
                }
                if (!$present) {
                    continue;
                }
                $switch = StreamLangProxy::mintTsPlaylist($rawBest, $iso);
                $audioTracks[] = [
                    'id' => 'lang-' . $code,
                    'label' => $label,
                    'name' => $label,
                    'language' => $code,
                    'lang' => $code,
                    'switchUrl' => $switch,
                    'url' => $switch,
                ];
            }
        } else {
            $audioTracks[] = [
                'id' => 'lang-en',
                'label' => $hasEnglish ? 'English' : self::displayLang($langList[0] ?? 'English'),
                'name' => $hasEnglish ? 'English' : self::displayLang($langList[0] ?? 'English'),
                'language' => $hasEnglish ? 'en' : self::langCode($langList[0] ?? 'en'),
                'lang' => $hasEnglish ? 'en' : self::langCode($langList[0] ?? 'en'),
                'switchUrl' => '',
                'url' => (string) $best['url'],
            ];
        }

        return [
            'url' => $best['url'],
            'type' => $best['type'],
            'quality' => $best['quality'],
            'provider' => $providerId === 'nxsha' ? ($items[0]['provider'] ?? $providerId) : $providerId,
            'providerName' => $name,
            'label' => $name . ' · ' . ($hasEnglish ? 'English' : self::displayLang($langList[0])) . ' · ' . $best['quality'],
            'language' => $hasEnglish ? 'en' : self::langCode($langList[0]),
            'hasEnglish' => $hasEnglish,
            'qualities' => $qualities,
            'audioTracks' => $audioTracks,
            'meta' => [
                'backend' => 'nxsha',
                'languages' => $langList,
                'note' => 'Castle multi-audio TS remuxed via lang-proxy',
            ],
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private static function packLanguageSources(string $providerId, array $items): array
    {
        $name = (string) ($items[0]['providerName'] ?? self::LABELS[$providerId] ?? ucfirst($providerId));
        // Sort English first
        usort($items, static function (array $a, array $b): int {
            return self::langPreferScore((string) $b['language']) <=> self::langPreferScore((string) $a['language']);
        });
        $tracks = [];
        foreach ($items as $it) {
            $label = self::displayLang((string) ($it['language'] ?: $it['qualityRaw'] ?: 'Audio'));
            if (strcasecmp((string) $it['qualityRaw'], 'ipcloud') === 0) {
                $label = 'IP Cloud';
            }
            $tracks[] = [
                'id' => 'lang-' . self::langCode($label),
                'label' => $label,
                'name' => $label,
                'language' => self::langCode($label),
                'lang' => self::langCode($label),
                'switchUrl' => (string) $it['url'],
                'url' => (string) $it['url'],
            ];
        }
        $best = $items[0];
        $hasEnglish = false;
        foreach ($tracks as $t) {
            if (self::isEnglishCode((string) $t['language']) || strcasecmp((string) $t['label'], 'English') === 0) {
                $hasEnglish = true;
                break;
            }
        }
        // Pick English track as default URL when present
        foreach ($tracks as $t) {
            if (strcasecmp((string) $t['label'], 'English') === 0 || self::isEnglishCode((string) $t['language'])) {
                $bestUrl = (string) $t['switchUrl'];
                $bestLang = 'en';
                break;
            }
        }
        $bestUrl = $bestUrl ?? (string) $best['url'];
        $bestLang = $bestLang ?? self::langCode((string) $best['language']);

        $apiProv = (string) ($best['apiProvider'] ?? $providerId);
        foreach ($tracks as &$tr) {
            if (!empty($tr['switchUrl'])) {
                $tr['switchUrl'] = self::proxyPlayUrl((string) $tr['switchUrl'], $apiProv);
                $tr['url'] = $tr['switchUrl'];
            }
        }
        unset($tr);
        $bestUrl = self::proxyPlayUrl($bestUrl, $apiProv);

        return [
            'url' => $bestUrl,
            'type' => $best['type'],
            'quality' => 'Auto',
            'provider' => $providerId === 'nxsha' ? ($best['provider'] ?? $providerId) : $providerId,
            'providerName' => $name,
            'label' => $name . ' · ' . ($hasEnglish ? 'English' : self::displayLang((string) $best['language'])),
            'language' => $hasEnglish ? 'en' : $bestLang,
            'hasEnglish' => $hasEnglish,
            'audioTracks' => $tracks,
            'meta' => [
                'backend' => 'nxsha',
                'languages' => array_values(array_map(static fn($t) => $t['label'], $tracks)),
            ],
        ];
    }

    /** @param list<string> $langs */
    private static function tracksFromLangList(array $langs, string $url, bool $switchable): array
    {
        $langs = self::orderLangsEnglishFirst($langs);
        $tracks = [];
        foreach ($langs as $l) {
            $label = self::displayLang($l);
            $tracks[] = [
                'id' => 'lang-' . self::langCode($label),
                'label' => $label,
                'name' => $label,
                'language' => self::langCode($label),
                'lang' => self::langCode($label),
                'switchUrl' => $switchable ? $url : '',
                'url' => $url,
            ];
        }
        if ($tracks === []) {
            $tracks[] = [
                'id' => 'lang-en',
                'label' => 'English',
                'name' => 'English',
                'language' => 'en',
                'lang' => 'en',
                'switchUrl' => '',
                'url' => $url,
            ];
        }
        return $tracks;
    }

    /** @param list<string> $langs */
    private static function orderLangsEnglishFirst(array $langs): array
    {
        $uniq = [];
        foreach ($langs as $l) {
            $d = self::displayLang($l);
            $uniq[strtolower($d)] = $d;
        }
        $list = array_values($uniq);
        usort($list, static fn($a, $b) => self::langPreferScore($b) <=> self::langPreferScore($a));
        return $list;
    }

    /** @return list<string> */
    private static function extractLanguages(string $text): array
    {
        $found = [];
        if (preg_match_all('/\b(English|Hindi|Tamil|Telugu|Spanish|Portuguese|German|French|Japanese|Korean|Chinese|Arabic|Original|Malayalam|Kannada|Bengali)\b/i', $text, $m)) {
            foreach ($m[1] as $l) {
                $found[strtolower($l)] = self::displayLang($l);
            }
        }
        // Bare language quality like "English"
        if ($found === [] && preg_match('/^(english|hindi|tamil|telugu|spanish|portuguese|original|ipcloud)$/i', trim($text))) {
            $found[strtolower(trim($text))] = self::displayLang(trim($text));
        }
        return array_values($found);
    }

    private static function extractResolution(string $text): string
    {
        if (preg_match('/\b(2160p|1080p|720p|480p|360p|4k|uhd)\b/i', $text, $m)) {
            $r = strtoupper($m[1]);
            if ($r === '4K' || $r === 'UHD') {
                return '4K';
            }
            return strtolower($m[1]);
        }
        return '';
    }

    /** @param list<string> $langs */
    private static function preferredLanguage(array $langs, string $raw): string
    {
        foreach ($langs as $l) {
            if (self::isEnglishCode(self::langCode($l)) || strcasecmp($l, 'English') === 0) {
                return 'en';
            }
        }
        if (stripos($raw, 'english') !== false) {
            return 'en';
        }
        if ($langs !== []) {
            return self::langCode($langs[0]);
        }
        return 'en';
    }

    /** @param list<string> $langs */
    private static function hasEnglish(array $langs): bool
    {
        foreach ($langs as $l) {
            if (strcasecmp($l, 'English') === 0 || self::isEnglishCode(self::langCode($l))) {
                return true;
            }
        }
        return false;
    }

    private static function langPreferScore(string $lang): int
    {
        $c = self::langCode($lang);
        if (self::isEnglishCode($c) || strcasecmp($lang, 'English') === 0) {
            return 100;
        }
        if (strcasecmp($lang, 'Original') === 0 || $c === 'original') {
            return 60;
        }
        return 10;
    }

    private static function isEnglishCode(string $code): bool
    {
        $code = strtolower(trim($code));
        return $code === 'en' || $code === 'eng' || $code === 'english';
    }

    private static function langCode(string $lang): string
    {
        $l = strtolower(trim($lang));
        if ($l === '') {
            return '';
        }
        if (str_starts_with($l, 'en')) {
            return 'en';
        }
        if ($l === 'hindi' || str_starts_with($l, 'hi')) {
            return 'hi';
        }
        if ($l === 'tamil' || $l === 'ta' || str_contains($l, 'tamil')) {
            return 'ta';
        }
        if ($l === 'te' || str_contains($l, 'telugu')) {
            return 'te';
        }
        if ($l === 'es' || str_contains($l, 'spanish')) {
            return 'es';
        }
        if ($l === 'pt' || str_contains($l, 'portuguese')) {
            return 'pt';
        }
        if ($l === 'de' || str_contains($l, 'german')) {
            return 'de';
        }
        if ($l === 'fr' || str_contains($l, 'french')) {
            return 'fr';
        }
        if (str_contains($l, 'original')) {
            return 'original';
        }
        if ($l === 'ip cloud' || str_contains($l, 'ipcloud')) {
            return 'ipcloud';
        }
        return substr($l, 0, 8);
    }

    private static function displayLang(string $lang): string
    {
        $c = self::langCode($lang);
        return match ($c) {
            'en' => 'English',
            'hi' => 'Hindi',
            'ta' => 'Tamil',
            'te' => 'Telugu',
            'es' => 'Spanish',
            'pt' => 'Portuguese',
            'de' => 'German',
            'fr' => 'French',
            'original' => 'Original',
            'ipcloud' => 'IP Cloud',
            default => $lang !== '' ? ucfirst($lang) : 'English',
        };
    }


    /** Wrap CDN playlists that require Referer (AwsInd etc.). */
    private static function proxyPlayUrl(string $url, string $apiProvider = ''): string
    {
        if ($url === '' || !class_exists('StreamLangProxy')) {
            return $url;
        }
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $needsRef = str_contains($host, 'hanna') || str_contains($host, 'rasta') || str_contains($host, 'arch-400')
            || $apiProvider === 'awsind' || str_contains($host, 'hakunaymatata');
        if (!$needsRef) {
            // Still help Nitro/Rive if they ever open — try referer; otherwise leave raw.
            if (str_contains($host, 'itsnitrox') || str_contains($host, '1shows')) {
                return StreamLangProxy::mintHlsHeaders($url, [
                    'Referer' => 'https://1shows.app/',
                    'Origin' => 'https://1shows.app',
                ], 'eng');
            }
            return $url;
        }
        $ref = 'https://' . ($host !== '' ? $host : 'hanna427def.com') . '/';
        return StreamLangProxy::mintHlsHeaders($url, [
            'Referer' => $ref,
            'Origin' => rtrim($ref, '/'),
        ], 'eng');
    }

    private static function qualityRank(string $q): int
    {
        if (preg_match('/\b(2160|4k|uhd)\b/i', $q)) return 2160;
        if (preg_match('/\b1080\b/i', $q)) return 1080;
        if (preg_match('/\b720\b/i', $q)) return 720;
        if (preg_match('/\b480\b/i', $q)) return 480;
        if (preg_match('/\b360\b/i', $q)) return 360;
        return 0;
    }

    private static function guessType(string $url, string $hint): string
    {
        $hint = strtolower(trim($hint));
        if ($hint === 'hls' || $hint === 'm3u8') return 'hls';
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        if (str_contains($path, '.m3u8') || str_contains(strtolower($url), 'm3u8')) return 'hls';
        if (str_contains($path, '.mp4')) return 'file';
        return $hint !== '' ? $hint : 'hls';
    }

    private static function httpGetJson(string $url): ?array
    {
        $raw = self::httpGetRaw($url);
        if ($raw === null || $raw === '') return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function httpGetRaw(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: VuflixNxsha/1.1',
                ],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($body) || $body === '') return null;
            if ($code >= 200 && $code < 500) return $body;
            return null;
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 45,
                'header' => "Accept: application/json\r\nUser-Agent: VuflixNxsha/1.1\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : null;
    }
}
