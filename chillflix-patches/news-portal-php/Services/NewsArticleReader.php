<?php
declare(strict_types=1);

/**
 * Fetches publisher HTML and extracts a readable full article:
 * hero image + ordered body blocks (paragraphs / images).
 */
final class NewsArticleReader
{
    /**
     * @param array<string,mixed> $article
     * @return array<string,mixed>
     */
    public static function hydrate(array $article): array
    {
        $url = (string) ($article['url'] ?? '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $article;
        }

        $cacheKey = 'full_' . substr(sha1($url), 0, 24);
        $cached = self::cacheGet($cacheKey, 1800);
        if (!is_array($cached)) {
            try {
                $fetched = self::httpGet($url);
                $cached = self::extract($fetched['body'], $fetched['finalUrl']);
                $cached['finalUrl'] = $fetched['finalUrl'];
                self::cacheSet($cacheKey, $cached);
            } catch (Throwable $e) {
                error_log('[NewsArticleReader] ' . $e->getMessage());
                $cached = [];
            }
        }

        if (!empty($cached['finalUrl']) && str_contains($url, 'news.google.com')) {
            $article['url'] = $cached['finalUrl'];
        }
        if (!empty($cached['title'])) {
            $article['title'] = $cached['title'];
        }
        if (!empty($cached['image'])) {
            $article['image'] = $cached['image'];
        }
        if (!empty($cached['description'])) {
            $article['summary'] = $cached['description'];
        }
        if (!empty($cached['siteName']) && in_array((string) ($article['sourceName'] ?? ''), ['Google News', ''], true)) {
            $article['sourceName'] = $cached['siteName'];
        }
        if (!empty($cached['blocks']) && is_array($cached['blocks'])) {
            $article['blocks'] = $cached['blocks'];
            $texts = [];
            foreach ($cached['blocks'] as $block) {
                if (($block['type'] ?? '') === 'p' && !empty($block['text'])) {
                    $texts[] = (string) $block['text'];
                }
            }
            if ($texts !== []) {
                $article['body'] = implode("\n\n", $texts);
            }
        }

        return $article;
    }

    /**
     * @return array{title?:string,description?:string,image?:string,siteName?:string,blocks?:list<array<string,mixed>>}
     */
    private static function extract(string $html, string $baseUrl): array
    {
        $out = [];
        $meta = self::meta($html, $baseUrl);
        $out = array_merge($out, $meta);

        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $blocks = [];

        if (str_contains($host, 'bbc.')) {
            $blocks = self::extractBbc($html, $baseUrl);
        } elseif (str_contains($host, 'theguardian.')) {
            $blocks = self::extractGuardian($html, $baseUrl);
        } elseif (str_contains($host, 'cnn.')) {
            $blocks = self::extractGeneric($html, $baseUrl, ['div.article__content', 'article', 'main']);
        } else {
            $blocks = self::extractGeneric($html, $baseUrl, ['article', 'main', '[itemprop=articleBody]', '.article-body', '.entry-content']);
        }

        // JSON-LD articleBody fallback / supplement
        if (count(array_filter($blocks, static fn ($b) => ($b['type'] ?? '') === 'p')) < 2) {
            $ld = self::extractJsonLd($html);
            if (!empty($ld['blocks'])) {
                $blocks = array_merge($blocks, $ld['blocks']);
            }
            if (empty($out['image']) && !empty($ld['image'])) {
                $out['image'] = $ld['image'];
            }
            if (empty($out['description']) && !empty($ld['description'])) {
                $out['description'] = $ld['description'];
            }
        }

        // Deduplicate consecutive identical paragraphs
        $clean = [];
        $seenText = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'p') {
                $t = trim((string) ($block['text'] ?? ''));
                if ($t === '' || mb_strlen($t) < 25) {
                    continue;
                }
                $key = mb_strtolower($t);
                if (isset($seenText[$key])) {
                    continue;
                }
                $seenText[$key] = true;
                $clean[] = ['type' => 'p', 'text' => $t];
            } elseif (($block['type'] ?? '') === 'img' && !empty($block['src'])) {
                $clean[] = $block;
            } elseif (($block['type'] ?? '') === 'h2' && !empty($block['text'])) {
                $clean[] = $block;
            }
        }

        if ($clean !== []) {
            $out['blocks'] = array_slice($clean, 0, 80);
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function extractBbc(string $html, string $baseUrl): array
    {
        $blocks = [];
        $dom = self::dom($html);
        if (!$dom) {
            return self::extractGeneric($html, $baseUrl, ['article', 'main']);
        }
        $xp = new DOMXPath($dom);

        // Prefer main article region
        $nodes = $xp->query('//*[@data-component="text-block" or @data-component="image-block" or @data-component="heading" or @data-component="subheadline-block"]');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                /** @var DOMElement $node */
                $comp = $node->getAttribute('data-component');
                if ($comp === 'image-block') {
                    $img = $xp->query('.//img', $node)->item(0);
                    if ($img instanceof DOMElement) {
                        $src = self::absUrl(self::imgSrc($img), $baseUrl);
                        if ($src) {
                            $blocks[] = ['type' => 'img', 'src' => $src, 'alt' => $img->getAttribute('alt')];
                        }
                    }
                    continue;
                }
                if ($comp === 'heading' || $comp === 'subheadline-block') {
                    $text = self::nodeText($node);
                    if ($text !== '') {
                        $blocks[] = ['type' => 'h2', 'text' => $text];
                    }
                    continue;
                }
                $text = self::nodeText($node);
                if ($text !== '') {
                    $blocks[] = ['type' => 'p', 'text' => $text];
                }
            }
        }

        // Live blog posts
        if (count($blocks) < 3) {
            $posts = $xp->query('//*[@data-testid="live-post"]|//*[@data-component="posted-item"]|//article');
            if ($posts !== false) {
                foreach ($posts as $post) {
                    $ps = $xp->query('.//p', $post);
                    if ($ps === false) {
                        continue;
                    }
                    foreach ($ps as $p) {
                        $text = self::nodeText($p);
                        if (mb_strlen($text) >= 40) {
                            $blocks[] = ['type' => 'p', 'text' => $text];
                        }
                    }
                    $imgs = $xp->query('.//img', $post);
                    if ($imgs !== false) {
                        foreach ($imgs as $img) {
                            if (!$img instanceof DOMElement) {
                                continue;
                            }
                            $src = self::absUrl(self::imgSrc($img), $baseUrl);
                            if ($src && !str_contains($src, 'grey-placeholder') && !str_contains($src, 'spacer')) {
                                $blocks[] = ['type' => 'img', 'src' => $src, 'alt' => $img->getAttribute('alt')];
                            }
                        }
                    }
                    if (count($blocks) > 40) {
                        break;
                    }
                }
            }
        }

        if ($blocks === []) {
            return self::extractGeneric($html, $baseUrl, ['article', 'main']);
        }
        return $blocks;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function extractGuardian(string $html, string $baseUrl): array
    {
        return self::extractGeneric($html, $baseUrl, ['div.article-body-commercial-selector', 'div[itemprop=articleBody]', 'article']);
    }

    /**
     * @param list<string> $selectors
     * @return list<array<string,mixed>>
     */
    private static function extractGeneric(string $html, string $baseUrl, array $selectors): array
    {
        $dom = self::dom($html);
        if (!$dom) {
            return [];
        }
        $xp = new DOMXPath($dom);
        $root = null;
        foreach ($selectors as $css) {
            $xpath = self::cssToXPath($css);
            $nodes = $xp->query($xpath);
            if ($nodes !== false && $nodes->length > 0) {
                $root = $nodes->item(0);
                break;
            }
        }
        if (!$root) {
            $root = $dom->documentElement;
        }

        $blocks = [];
        $walk = $xp->query('.//p|.//h2|.//img', $root);
        if ($walk === false) {
            return [];
        }
        foreach ($walk as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if ($tag === 'img') {
                $src = self::absUrl(self::imgSrc($node), $baseUrl);
                if ($src && self::looksLikeContentImage($src, $node)) {
                    $blocks[] = ['type' => 'img', 'src' => $src, 'alt' => $node->getAttribute('alt')];
                }
                continue;
            }
            $text = self::nodeText($node);
            if ($text === '' || mb_strlen($text) < 30) {
                continue;
            }
            // Skip nav/footer junk
            if (preg_match('/cookie|subscribe|newsletter|sign in|advertisement/i', $text)) {
                continue;
            }
            $blocks[] = ['type' => $tag === 'h2' ? 'h2' : 'p', 'text' => $text];
        }
        return $blocks;
    }

    /**
     * @return array{blocks?:list<array<string,mixed>>,image?:string,description?:string}
     */
    private static function extractJsonLd(string $html): array
    {
        $out = ['blocks' => []];
        if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return $out;
        }
        foreach ($matches[1] as $json) {
            $data = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!is_array($data)) {
                continue;
            }
            $items = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $type = $item['@type'] ?? '';
                $types = is_array($type) ? $type : [$type];
                if (!array_intersect($types, ['NewsArticle', 'Article', 'ReportageNewsArticle', 'LiveBlogPosting', 'BlogPosting'])) {
                    continue;
                }
                if (!empty($item['image'])) {
                    $img = $item['image'];
                    if (is_array($img)) {
                        $img = $img['url'] ?? ($img[0]['url'] ?? ($img[0] ?? null));
                    }
                    if (is_string($img) && $img !== '') {
                        $out['image'] = $img;
                    }
                }
                if (!empty($item['description']) && is_string($item['description'])) {
                    $out['description'] = trim($item['description']);
                }
                if (!empty($item['articleBody']) && is_string($item['articleBody'])) {
                    foreach (preg_split('/\n+/', trim($item['articleBody'])) ?: [] as $para) {
                        $para = trim($para);
                        if (mb_strlen($para) >= 40) {
                            $out['blocks'][] = ['type' => 'p', 'text' => $para];
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @return array{title?:string,description?:string,image?:string,siteName?:string}
     */
    private static function meta(string $html, string $baseUrl): array
    {
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

        $out = [];
        $title = $get('og:title') ?? $get('twitter:title');
        if (!$title && preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($title) {
            $out['title'] = trim($title);
        }
        $desc = $get('og:description') ?? $get('twitter:description') ?? $get('description');
        if ($desc) {
            $out['description'] = trim(html_entity_decode(strip_tags($desc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $image = $get('og:image') ?? $get('og:image:secure_url') ?? $get('twitter:image') ?? $get('twitter:image:src');
        if ($image) {
            $out['image'] = self::absUrl($image, $baseUrl);
        }
        $site = $get('og:site_name');
        if ($site) {
            $out['siteName'] = trim($site);
        }
        return $out;
    }

    private static function dom(string $html): ?DOMDocument
    {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $dom : null;
    }

    private static function nodeText(DOMNode $node): string
    {
        $text = $node->textContent ?? '';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private static function imgSrc(DOMElement $img): string
    {
        foreach (['src', 'data-src', 'data-original', 'data-lazy-src'] as $attr) {
            $v = trim($img->getAttribute($attr));
            if ($v !== '' && !str_starts_with($v, 'data:')) {
                return $v;
            }
        }
        $srcset = $img->getAttribute('srcset') ?: $img->getAttribute('data-srcset');
        if ($srcset !== '') {
            $parts = array_map('trim', explode(',', $srcset));
            $last = trim((string) end($parts));
            $url = strtok($last, ' ') ?: '';
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    private static function absUrl(?string $url, string $baseUrl): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $parts = parse_url($baseUrl);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }
        return $origin . '/' . ltrim($url, '/');
    }

    private static function looksLikeContentImage(string $src, DOMElement $img): bool
    {
        $bad = ['sprite', 'pixel', 'spacer', 'logo', 'icon', 'avatar', 'emoji', '1x1', 'blank'];
        $low = strtolower($src);
        foreach ($bad as $b) {
            if (str_contains($low, $b)) {
                return false;
            }
        }
        $w = (int) $img->getAttribute('width');
        $h = (int) $img->getAttribute('height');
        if ($w > 0 && $w < 80) {
            return false;
        }
        if ($h > 0 && $h < 80) {
            return false;
        }
        return true;
    }

    private static function cssToXPath(string $css): string
    {
        // Minimal CSS->XPath for our selectors
        $css = trim($css);
        if ($css === 'article' || $css === 'main') {
            return '//' . $css;
        }
        if (preg_match('/^([a-z0-9]+)\.([a-z0-9_-]+)$/i', $css, $m)) {
            return '//' . $m[1] . '[contains(concat(" ", normalize-space(@class), " "), " ' . $m[2] . ' ")]';
        }
        if (preg_match('/^\\.([a-z0-9_-]+)$/i', $css, $m)) {
            return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $m[1] . ' ")]';
        }
        if (preg_match('/^\\[itemprop=([a-z0-9_-]+)\\]$/i', $css, $m)) {
            return '//*[@itemprop="' . $m[1] . '"]';
        }
        if (preg_match('/^([a-z0-9]+)\\[itemprop=([a-z0-9_-]+)\\]$/i', $css, $m)) {
            return '//' . $m[1] . '[@itemprop="' . $m[2] . '"]';
        }
        return '//article';
    }

    /**
     * @return array{body:string,finalUrl:string}
     */
    private static function httpGet(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_TIMEOUT => 14,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            throw new RuntimeException('fetch failed ' . $code . ' ' . $err);
        }
        if (strlen($body) > 1_500_000) {
            $body = substr($body, 0, 1_500_000);
        }
        return ['body' => $body, 'finalUrl' => $final !== '' ? $final : $url];
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
        if (!is_file($file) || filemtime($file) + $ttl < time()) {
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
