<?php
declare(strict_types=1);

/**
 * Aggregates public news RSS feeds (Google News country editions + BBC World).
 * Headlines/summaries only — full article stays with the publisher (credited).
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
        $cacheKey = 'news_' . $country['code'] . '_' . $category['id'];
        $cached = self::cacheGet($cacheKey, 300);
        if (is_array($cached)) {
            return array_slice($cached, 0, $limit);
        }

        $urls = [
            ['url' => self::googleNewsUrl($country, $category['id']), 'source' => 'Google News'],
        ];
        if ($country['code'] === 'GLOBAL') {
            $bbc = self::bbcUrl($category['id']);
            if ($bbc !== null) {
                $urls[] = ['url' => $bbc, 'source' => 'BBC News'];
            }
        }

        $items = [];
        $seen = [];
        foreach ($urls as $entry) {
            try {
                $xml = self::httpGet($entry['url']);
                foreach (self::parseRss($xml, $country, $category['label'], $entry['source']) as $article) {
                    $id = (string) $article['id'];
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $items[] = $article;
                }
            } catch (Throwable $e) {
                error_log('[NewsFeed] ' . $e->getMessage());
            }
        }

        self::cacheSet($cacheKey, $items);
        return array_slice($items, 0, $limit);
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
                    return $article;
                }
            }
        }
        return null;
    }

    public static function articleUrl(array $article, string $countryCode): string
    {
        return url('/news/article/' . rawurlencode((string) $article['id']) . '?country=' . rawurlencode($countryCode));
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

    private static function bbcUrl(string $categoryId): ?string
    {
        return match ($categoryId) {
            'top', 'world' => 'https://feeds.bbci.co.uk/news/world/rss.xml',
            'technology' => 'https://feeds.bbci.co.uk/news/technology/rss.xml',
            'business' => 'https://feeds.bbci.co.uk/news/business/rss.xml',
            'sports' => 'https://feeds.bbci.co.uk/sport/rss.xml',
            'entertainment' => 'https://feeds.bbci.co.uk/news/entertainment_and_arts/rss.xml',
            'health' => 'https://feeds.bbci.co.uk/news/health/rss.xml',
            default => null,
        };
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
            CURLOPT_USERAGENT => 'Daily24NewsBot/1.0 (+https://vuflix.co/news)',
            CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/xml, text/xml, */*'],
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

            $descHtml = (string) ($item->description ?? '');
            $summary = self::plain($descHtml);
            if (mb_strlen($summary) > 320) {
                $summary = rtrim(mb_substr($summary, 0, 317)) . '…';
            }

            $image = self::rssImage($item, $descHtml);
            $sourceName = trim((string) ($item->source ?? ''));
            if ($sourceName === '') {
                $sourceName = $defaultSource;
            }
            $sourceUrl = null;
            if (isset($item->source['url'])) {
                $sourceUrl = (string) $item->source['url'];
            }
            $published = trim((string) ($item->pubDate ?? '')) ?: null;

            $items[] = [
                'id' => substr(sha1($link), 0, 16),
                'title' => $title,
                'summary' => $summary,
                'url' => $link,
                'image' => $image,
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

    private static function rssImage(SimpleXMLElement $item, string $descHtml): ?string
    {
        $namespaces = $item->getNamespaces(true);
        if (isset($namespaces['media'])) {
            $media = $item->children($namespaces['media']);
            if (isset($media->content)) {
                $url = (string) ($media->content->attributes()['url'] ?? '');
                if ($url !== '') {
                    return $url;
                }
            }
            if (isset($media->thumbnail)) {
                $url = (string) ($media->thumbnail->attributes()['url'] ?? '');
                if ($url !== '') {
                    return $url;
                }
            }
        }
        if (isset($item->enclosure['url'])) {
            $url = (string) $item->enclosure['url'];
            if ($url !== '') {
                return $url;
            }
        }
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $descHtml, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
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
