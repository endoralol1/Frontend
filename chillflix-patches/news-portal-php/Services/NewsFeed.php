<?php
declare(strict_types=1);

/**
 * Aggregates public news RSS feeds with images + readable summaries.
 * Enriches missing media via Open Graph when needed.
 */
final class NewsFeed
{
    /** @var list<array{code:string,label:string,native:string,lang:string,hl:string,gl:string,ceid:string}> */
    public const COUNTRIES = [
        ['code' => 'GLOBAL', 'label' => 'World', 'native' => 'World', 'lang' => 'en', 'hl' => 'en-US', 'gl' => 'US', 'ceid' => 'US:en'],
        ['code' => 'US', 'label' => 'United States', 'native' => 'United States', 'lang' => 'en', 'hl' => 'en-US', 'gl' => 'US', 'ceid' => 'US:en'],
        ['code' => 'GB', 'label' => 'United Kingdom', 'native' => 'United Kingdom', 'lang' => 'en', 'hl' => 'en-GB', 'gl' => 'GB', 'ceid' => 'GB:en'],
        ['code' => 'HR', 'label' => 'Croatia', 'native' => 'Hrvatska', 'lang' => 'hr', 'hl' => 'hr', 'gl' => 'HR', 'ceid' => 'HR:hr'],
        ['code' => 'DE', 'label' => 'Germany', 'native' => 'Deutschland', 'lang' => 'de', 'hl' => 'de', 'gl' => 'DE', 'ceid' => 'DE:de'],
        ['code' => 'FR', 'label' => 'France', 'native' => 'France', 'lang' => 'fr', 'hl' => 'fr', 'gl' => 'FR', 'ceid' => 'FR:fr'],
        ['code' => 'ES', 'label' => 'Spain', 'native' => 'España', 'lang' => 'es', 'hl' => 'es', 'gl' => 'ES', 'ceid' => 'ES:es'],
        ['code' => 'IT', 'label' => 'Italy', 'native' => 'Italia', 'lang' => 'it', 'hl' => 'it', 'gl' => 'IT', 'ceid' => 'IT:it'],
        ['code' => 'BR', 'label' => 'Brazil', 'native' => 'Brasil', 'lang' => 'pt', 'hl' => 'pt-BR', 'gl' => 'BR', 'ceid' => 'BR:pt-419'],
        ['code' => 'IN', 'label' => 'India', 'native' => 'India', 'lang' => 'en', 'hl' => 'en-IN', 'gl' => 'IN', 'ceid' => 'IN:en'],
        ['code' => 'JP', 'label' => 'Japan', 'native' => '日本', 'lang' => 'ja', 'hl' => 'ja', 'gl' => 'JP', 'ceid' => 'JP:ja'],
        ['code' => 'TR', 'label' => 'Turkey', 'native' => 'Türkiye', 'lang' => 'tr', 'hl' => 'tr', 'gl' => 'TR', 'ceid' => 'TR:tr'],
    ];

    /** @var list<array{id:string,label:string}> */
    public const CATEGORIES = [
        ['id' => 'top', 'label' => 'News'],
        ['id' => 'world', 'label' => 'World'],
        ['id' => 'business', 'label' => 'Business'],
        ['id' => 'technology', 'label' => 'Sci/Tech'],
        ['id' => 'sports', 'label' => 'Sport'],
        ['id' => 'entertainment', 'label' => 'Show'],
        ['id' => 'health', 'label' => 'Life'],
    ];

    public static function country(?string $code): array
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            $code = 'GLOBAL';
        }
        foreach (self::COUNTRIES as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }
        return self::COUNTRIES[0];
    }

    public static function category(?string $id): array
    {
        $id = strtolower(trim((string) $id));
        foreach (self::CATEGORIES as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return self::CATEGORIES[0];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function feed(?string $countryCode = null, ?string $categoryId = null, int $limit = 36): array
    {
        $country = self::country($countryCode);
        $category = self::category($categoryId);
        $limit = max(6, min(60, $limit));
        $cacheKey = 'news_v2_' . $country['code'] . '_' . $category['id'];
        $cached = self::cacheGet($cacheKey, 300);
        if (is_array($cached) && $cached !== []) {
            return array_slice($cached, 0, $limit);
        }

        $items = [];
        $seen = [];
        foreach (self::sourceList($country, $category['id']) as $entry) {
            try {
                $xml = self::httpGet($entry['url']);
                foreach (self::parseRss($xml, $country, $category['label'], $entry['source']) as $article) {
                    $id = (string) $article['id'];
                    if (isset($seen[$id]) || isset($seen[$article['url']])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $seen[(string) $article['url']] = true;
                    $items[] = $article;
                }
            } catch (Throwable $e) {
                error_log('[NewsFeed] ' . $e->getMessage());
            }
        }

            // Prefer items that already have images, then enrich the rest (cap enrich calls).
        usort($items, static function ($a, $b) {
            $ai = !empty($a['image']) ? 0 : 1;
            $bi = !empty($b['image']) ? 0 : 1;
            if ($ai !== $bi) {
                return $ai <=> $bi;
            }
            return 0;
        });

        $enriched = [];
        $enrichBudget = 18;
        foreach ($items as $article) {
            $needsEnrich = empty($article['image'])
                || self::isWeakSummary((string) ($article['summary'] ?? ''))
                || (is_string($article['image'] ?? null) && str_contains((string) $article['image'], '/240/'));
            if ($enrichBudget > 0 && $needsEnrich) {
                $article = self::enrichFromPage($article);
                $enrichBudget--;
            }
            $article['image'] = self::upgradeImageUrl($article['image'] ?? null);
            $enriched[] = $article;
            if (count($enriched) >= $limit) {
                break;
            }
        }

        self::cacheSet($cacheKey, $enriched);
        return $enriched;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(string $id, ?string $countryCode = null): ?array
    {
        $id = preg_replace('/[^a-f0-9]/', '', strtolower($id)) ?? '';
        if ($id === '') {
            return null;
        }
        foreach (self::CATEGORIES as $cat) {
            foreach (self::feed($countryCode, $cat['id'], 48) as $article) {
                if (($article['id'] ?? '') === $id) {
                    return self::enrichFromPage($article, true);
                }
            }
        }
        return null;
    }

    public static function articleUrl(array $article, string $countryCode): string
    {
        return url('/news/article/' . rawurlencode((string) $article['id']) . '?country=' . rawurlencode($countryCode));
    }

    private static function upgradeImageUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        // Prefer larger BBC stills when RSS only gives 240px thumbs.
        if (preg_match('#(/ace/(?:standard|branded_[a-z]+)/)240/#', $url)) {
            $url = preg_replace('#(/ace/(?:standard|branded_[a-z]+)/)240/#', '${1}976/', $url) ?? $url;
        }
        return $url;
    }

    public static function placeholder(string $seed): string
    {
        $hue = 0;
        foreach (str_split($seed) as $ch) {
            $hue += ord($ch);
        }
        $hue %= 360;
        $hue2 = ($hue + 40) % 360;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675" viewBox="0 0 1200 675">'
            . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
            . '<stop stop-color="hsl(' . $hue . ',55%,35%)"/>'
            . '<stop offset="1" stop-color="hsl(' . $hue2 . ',45%,18%)"/>'
            . '</linearGradient></defs>'
            . '<rect width="1200" height="675" fill="url(#g)"/>'
            . '<text x="60" y="600" fill="rgba(255,255,255,.75)" font-family="Arial" font-size="42" font-weight="700">DAILY24</text>'
            . '</svg>';
        return 'data:image/svg+xml,' . rawurlencode($svg);
    }

    /**
     * @return list<array{url:string,source:string}>
     */
    private static function sourceList(array $country, string $categoryId): array
    {
        $sources = [];

        // Image-rich English feeds for GLOBAL / US / GB
        if (in_array($country['code'], ['GLOBAL', 'US', 'GB'], true)) {
            foreach (self::englishFeeds($categoryId) as $feed) {
                $sources[] = $feed;
            }
        }

        // Local / language edition via Google News
        $sources[] = [
            'url' => self::googleNewsUrl($country, $categoryId),
            'source' => 'Google News',
        ];

        // Country extras
        if ($country['code'] === 'HR') {
            $sources[] = ['url' => 'https://www.index.hr/rss', 'source' => 'Index.hr'];
            $sources[] = ['url' => 'https://www.tportal.hr/rss', 'source' => 'tportal'];
        }
        if ($country['code'] === 'DE') {
            $sources[] = ['url' => 'https://www.spiegel.de/international/index.rss', 'source' => 'Spiegel'];
            $sources[] = ['url' => 'https://www.tagesschau.de/xml/rss2/', 'source' => 'tagesschau'];
        }
        if ($country['code'] === 'FR') {
            $sources[] = ['url' => 'https://www.france24.com/en/rss', 'source' => 'France 24'];
        }

        return $sources;
    }

    /**
     * @return list<array{url:string,source:string}>
     */
    private static function englishFeeds(string $categoryId): array
    {
        return match ($categoryId) {
            'technology' => [
                ['url' => 'https://feeds.bbci.co.uk/news/technology/rss.xml', 'source' => 'BBC News'],
                ['url' => 'https://www.theguardian.com/technology/rss', 'source' => 'The Guardian'],
                ['url' => 'https://feeds.npr.org/1019/rss.xml', 'source' => 'NPR'],
            ],
            'business' => [
                ['url' => 'https://feeds.bbci.co.uk/news/business/rss.xml', 'source' => 'BBC News'],
                ['url' => 'https://www.theguardian.com/business/rss', 'source' => 'The Guardian'],
                ['url' => 'https://feeds.npr.org/1006/rss.xml', 'source' => 'NPR'],
            ],
            'sports' => [
                ['url' => 'https://feeds.bbci.co.uk/sport/rss.xml', 'source' => 'BBC Sport'],
                ['url' => 'https://www.theguardian.com/sport/rss', 'source' => 'The Guardian'],
            ],
            'entertainment' => [
                ['url' => 'https://feeds.bbci.co.uk/news/entertainment_and_arts/rss.xml', 'source' => 'BBC News'],
                ['url' => 'https://www.theguardian.com/culture/rss', 'source' => 'The Guardian'],
            ],
            'health' => [
                ['url' => 'https://feeds.bbci.co.uk/news/health/rss.xml', 'source' => 'BBC News'],
                ['url' => 'https://www.theguardian.com/society/health/rss', 'source' => 'The Guardian'],
                ['url' => 'https://feeds.npr.org/1128/rss.xml', 'source' => 'NPR'],
            ],
            'world' => [
                ['url' => 'https://feeds.bbci.co.uk/news/world/rss.xml', 'source' => 'BBC News'],
                ['url' => 'https://www.theguardian.com/world/rss', 'source' => 'The Guardian'],
                ['url' => 'https://feeds.npr.org/1004/rss.xml', 'source' => 'NPR'],
                ['url' => 'https://rss.cnn.com/rss/edition_world.rss', 'source' => 'CNN'],
            ],
            default => [ // top
                ['url' => 'https://feeds.bbci.co.uk/news/world/rss.xml', 'source' => 'BBC News'],
                ['url' => 'https://feeds.bbci.co.uk/news/rss.xml', 'source' => 'BBC News'],
                ['url' => 'https://www.theguardian.com/world/rss', 'source' => 'The Guardian'],
                ['url' => 'https://feeds.npr.org/1001/rss.xml', 'source' => 'NPR'],
                ['url' => 'https://rss.cnn.com/rss/edition.rss', 'source' => 'CNN'],
            ],
        };
    }

    private static function googleNewsUrl(array $country, string $categoryId): string
    {
        $q = 'hl=' . rawurlencode($country['hl'])
            . '&gl=' . rawurlencode($country['gl'])
            . '&ceid=' . rawurlencode($country['ceid']);
        if ($categoryId === 'top') {
            return 'https://news.google.com/rss?' . $q;
        }
        $map = [
            'world' => 'WORLD',
            'business' => 'BUSINESS',
            'technology' => 'TECHNOLOGY',
            'sports' => 'SPORTS',
            'entertainment' => 'ENTERTAINMENT',
            'health' => 'HEALTH',
        ];
        $topic = $map[$categoryId] ?? 'WORLD';
        return 'https://news.google.com/rss/headlines/section/topic/' . $topic . '?' . $q;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function parseRss(string $xml, array $country, string $categoryLabel, string $defaultSource): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            return [];
        }

        $channel = $doc->channel ?? $doc;
        $items = [];
        foreach ($channel->item ?? [] as $item) {
            $title = self::plain((string) ($item->title ?? ''));
            $link = trim((string) ($item->link ?? ''));
            if ($link === '') {
                $link = trim((string) ($item->guid ?? ''));
            }
            if ($title === '' || $link === '') {
                continue;
            }

            // Prefer content:encoded for richer text when present
            $contentEncoded = '';
            $namespaces = $item->getNamespaces(true);
            if (isset($namespaces['content'])) {
                $content = $item->children($namespaces['content']);
                $contentEncoded = (string) ($content->encoded ?? '');
            }

            $descHtml = (string) ($item->description ?? '');
            $summary = self::cleanSummary($descHtml, $title, $defaultSource);
            if ($summary === '' && $contentEncoded !== '') {
                $summary = self::plain($contentEncoded);
            }
            if (mb_strlen($summary) > 420) {
                $summary = rtrim(mb_substr($summary, 0, 417)) . '…';
            }

            $image = self::rssImage($item, $descHtml . $contentEncoded);
            $sourceName = trim((string) ($item->source ?? ''));
            if ($sourceName === '') {
                $sourceName = $defaultSource;
            }
            // Strip trailing publisher from Google titles: "Headline - BBC"
            if (str_contains($title, ' - ') && $defaultSource === 'Google News') {
                $parts = preg_split('/\s+-\s+/', $title);
                if (is_array($parts) && count($parts) >= 2) {
                    $maybeSource = trim((string) array_pop($parts));
                    if ($maybeSource !== '' && mb_strlen($maybeSource) < 40) {
                        $sourceName = $maybeSource;
                        $title = trim(implode(' - ', $parts));
                    }
                }
            }

            $sourceUrl = null;
            if (isset($item->source['url'])) {
                $sourceUrl = (string) $item->source['url'];
            }
            $published = trim((string) ($item->pubDate ?? '')) ?: null;

            $bodyHtml = $contentEncoded !== '' ? $contentEncoded : '';
            $bodyText = $bodyHtml !== '' ? self::plain($bodyHtml) : $summary;

            $items[] = [
                'id' => substr(sha1($link), 0, 16),
                'title' => $title,
                'summary' => $summary,
                'body' => $bodyText,
                'url' => $link,
                'image' => self::upgradeImageUrl($image),
                'publishedAt' => $published,
                'sourceName' => $sourceName,
                'sourceUrl' => $sourceUrl,
                'category' => mb_strtoupper(mb_substr($categoryLabel, 0, 42)),
                'country' => $country['code'],
                'lang' => $country['lang'],
            ];
        }
        return $items;
    }

    private static function cleanSummary(string $descHtml, string $title, string $defaultSource): string
    {
        // Google News packs an <ol> of related links — take first link text as weak summary fallback
        if (stripos($descHtml, '<ol') !== false || stripos($descHtml, '<li') !== false) {
            if (preg_match('/<a[^>]*>(.*?)<\/a>/is', $descHtml, $m)) {
                $text = self::plain($m[1]);
                if ($text !== '' && mb_strtolower($text) !== mb_strtolower($title)) {
                    return $text;
                }
            }
            // Fall through — OG enrich will fill later
            return '';
        }

        $text = self::plain($descHtml);
        // Remove duplicated title prefix
        if ($title !== '' && str_starts_with(mb_strtolower($text), mb_strtolower($title))) {
            $text = trim(mb_substr($text, mb_strlen($title)));
        }
        // Remove glued source at end
        $text = preg_replace('/\s+' . preg_quote($defaultSource, '/') . '\s*$/i', '', $text) ?? $text;
        return trim($text);
    }

    private static function isWeakSummary(string $summary): bool
    {
        return mb_strlen(trim($summary)) < 40;
    }

    /**
     * Fetch OG/Twitter meta from the article page for image + description.
     *
     * @param array<string,mixed> $article
     * @return array<string,mixed>
     */
    private static function enrichFromPage(array $article, bool $deep = false): array
    {
        $url = (string) ($article['url'] ?? '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $article;
        }

        $cacheKey = 'og_' . substr(sha1($url), 0, 20);
        $cached = self::cacheGet($cacheKey, 3600);
        if (!is_array($cached)) {
            try {
                $fetched = self::httpGetWithFinalUrl($url);
                $cached = self::parseOpenGraph($fetched['body'], $fetched['finalUrl']);
                $cached['finalUrl'] = $fetched['finalUrl'];
                self::cacheSet($cacheKey, $cached);
            } catch (Throwable $e) {
                error_log('[NewsFeed enrich] ' . $e->getMessage());
                $cached = [];
            }
        }

        if (!empty($cached['image']) && empty($article['image'])) {
            $article['image'] = $cached['image'];
        }
        if (!empty($cached['description']) && self::isWeakSummary((string) ($article['summary'] ?? ''))) {
            $article['summary'] = $cached['description'];
        }
        if ($deep) {
            if (!empty($cached['description'])) {
                $article['summary'] = $cached['description'];
                if (empty($article['body']) || self::isWeakSummary((string) $article['body'])) {
                    $article['body'] = $cached['description'];
                }
            }
            if (!empty($cached['image'])) {
                $article['image'] = $cached['image'];
            }
            if (!empty($cached['finalUrl']) && str_contains((string) $article['url'], 'news.google.com')) {
                $article['url'] = $cached['finalUrl'];
            }
            if (!empty($cached['siteName']) && ($article['sourceName'] === 'Google News' || $article['sourceName'] === '')) {
                $article['sourceName'] = $cached['siteName'];
            }
        }

        return $article;
    }

    /**
     * @return array{body:string,finalUrl:string}
     */
    private static function httpGetWithFinalUrl(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Daily24NewsBot/1.1; +https://vuflix.co/news)',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.8',
            ],
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            throw new RuntimeException('page fetch failed ' . $code . ' ' . $err);
        }
        // Cap HTML size for parsing
        if (strlen($body) > 800000) {
            $body = substr($body, 0, 800000);
        }
        return ['body' => $body, 'finalUrl' => $final !== '' ? $final : $url];
    }

    /**
     * @return array{image?:string,description?:string,siteName?:string}
     */
    private static function parseOpenGraph(string $html, string $baseUrl): array
    {
        $out = [];
        $get = static function (string $property) use ($html): ?string {
            $patterns = [
                '/<meta[^>]+property=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']+)["\']/i',
                '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']' . preg_quote($property, '/') . '["\']/i',
                '/<meta[^>]+name=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']+)["\']/i',
                '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']' . preg_quote($property, '/') . '["\']/i',
            ];
            foreach ($patterns as $re) {
                if (preg_match($re, $html, $m)) {
                    return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
            return null;
        };

        $image = $get('og:image') ?? $get('og:image:secure_url') ?? $get('twitter:image') ?? $get('twitter:image:src');
        $desc = $get('og:description') ?? $get('twitter:description') ?? $get('description');
        $site = $get('og:site_name');

        if ($image) {
            if (str_starts_with($image, '//')) {
                $image = 'https:' . $image;
            } elseif (str_starts_with($image, '/')) {
                $parts = parse_url($baseUrl);
                if (!empty($parts['scheme']) && !empty($parts['host'])) {
                    $image = $parts['scheme'] . '://' . $parts['host'] . $image;
                }
            }
            $out['image'] = $image;
        }
        if ($desc) {
            $out['description'] = trim(self::plain($desc));
        }
        if ($site) {
            $out['siteName'] = trim($site);
        }
        return $out;
    }

    private static function rssImage(SimpleXMLElement $item, string $htmlBlob): ?string
    {
        $namespaces = $item->getNamespaces(true);
        if (isset($namespaces['media'])) {
            $media = $item->children($namespaces['media']);
            foreach (['content', 'thumbnail'] as $node) {
                if (isset($media->{$node})) {
                    foreach ($media->{$node} as $el) {
                        $url = (string) ($el->attributes()['url'] ?? '');
                        if ($url !== '') {
                            return $url;
                        }
                    }
                }
            }
        }
        if (isset($item->enclosure['url'])) {
            $type = strtolower((string) ($item->enclosure['type'] ?? ''));
            $url = (string) $item->enclosure['url'];
            if ($url !== '' && ($type === '' || str_starts_with($type, 'image/'))) {
                return $url;
            }
        }
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $htmlBlob, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private static function httpGet(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Daily24NewsBot/1.1; +https://vuflix.co/news)',
            CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/xml, text/xml, */*'],
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            throw new RuntimeException('RSS fetch failed ' . $code . ' ' . $err . ' for ' . $url);
        }
        return (string) $body;
    }

    private static function cacheDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache/news';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function cacheGet(string $key, int $ttl): ?array
    {
        $file = self::cacheDir() . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $key) . '.json';
        if (!is_file($file)) {
            return null;
        }
        if (filemtime($file) + $ttl < time()) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function cacheSet(string $key, array $data): void
    {
        $file = self::cacheDir() . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $key) . '.json';
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
