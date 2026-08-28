<?php
declare(strict_types=1);

/**
 * Fetches publisher HTML and extracts a faithful article:
 * formatted HTML (bold/links/lists), inline images in order, live updates.
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

        $cacheKey = 'fullv2_' . substr(sha1($url), 0, 24);
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
            $url = (string) $article['url'];
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
        if (!empty($cached['isLive'])) {
            $article['isLive'] = true;
            $article['category'] = 'LIVE';
        }
        if (!empty($cached['sectionLabel'])) {
            $article['category'] = $cached['sectionLabel'];
        }
        if (!empty($cached['blocks']) && is_array($cached['blocks'])) {
            $article['blocks'] = $cached['blocks'];
            $texts = [];
            foreach ($cached['blocks'] as $block) {
                $type = (string) ($block['type'] ?? '');
                if ($type === 'html' && !empty($block['html'])) {
                    $texts[] = trim(strip_tags((string) $block['html']));
                } elseif ($type === 'live' && !empty($block['html'])) {
                    $texts[] = trim(strip_tags((string) $block['html']));
                } elseif ($type === 'p' && !empty($block['text'])) {
                    $texts[] = (string) $block['text'];
                }
            }
            if ($texts !== []) {
                $article['body'] = implode("\n\n", $texts);
            }
        }

        // Infer section if still generic NEWS
        if (empty($article['isLive'])) {
            $inferred = self::inferSectionLabel($url, (string) ($article['category'] ?? ''));
            if ($inferred !== '') {
                $article['category'] = $inferred;
            }
        }

        return $article;
    }

    public static function inferSectionLabel(string $url, string $current = ''): string
    {
        $cur = strtoupper(trim($current));
        if ($cur !== '' && $cur !== 'NEWS' && $cur !== 'TOP') {
            return $cur;
        }
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        if (str_contains($path, '/live/')) {
            return 'LIVE';
        }
        $map = [
            '/sport' => 'SPORT',
            '/football' => 'SPORT',
            '/business' => 'BUSINESS',
            '/technology' => 'SCI/TECH',
            '/tech' => 'SCI/TECH',
            '/science' => 'SCI/TECH',
            '/health' => 'LIFE',
            '/culture' => 'SHOW',
            '/entertainment' => 'SHOW',
            '/arts' => 'SHOW',
            '/world' => 'WORLD',
            '/asia' => 'WORLD',
            '/europe' => 'WORLD',
            '/us-canada' => 'WORLD',
            '/uk' => 'WORLD',
            '/politics' => 'POLITICS',
        ];
        foreach ($map as $needle => $label) {
            if (str_contains($path, $needle)) {
                return $label;
            }
        }
        return $cur === 'NEWS' || $cur === 'TOP' || $cur === '' ? 'WORLD' : $cur;
    }

    /**
     * @return array<string,mixed>
     */
    private static function extract(string $html, string $baseUrl): array
    {
        $out = self::meta($html, $baseUrl);
        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $path = strtolower((string) (parse_url($baseUrl, PHP_URL_PATH) ?: ''));

        $isLive = str_contains($path, '/live/') || str_contains($html, 'LiveBlogPosting');
        if ($isLive) {
            $out['isLive'] = true;
            $out['sectionLabel'] = 'LIVE';
            $liveBlocks = self::extractLiveBlog($html, $baseUrl);
            if ($liveBlocks !== []) {
                $out['blocks'] = $liveBlocks;
                return $out;
            }
        }

        if (str_contains($host, 'bbc.')) {
            $blocks = self::extractBbcRich($html, $baseUrl);
        } elseif (str_contains($host, 'theguardian.')) {
            $blocks = self::extractHtmlRegion($html, $baseUrl, [
                '//*[contains(@class,"article-body")]',
                '//*[@itemprop="articleBody"]',
                '//article',
            ]);
        } else {
            $blocks = self::extractHtmlRegion($html, $baseUrl, [
                '//*[@itemprop="articleBody"]',
                '//article',
                '//main',
            ]);
        }

        if ($blocks === []) {
            $ld = self::extractJsonLdArticle($html, $baseUrl);
            if (!empty($ld['blocks'])) {
                $blocks = $ld['blocks'];
            }
            if (empty($out['image']) && !empty($ld['image'])) {
                $out['image'] = $ld['image'];
            }
        }

        if ($blocks !== []) {
            $out['blocks'] = array_slice($blocks, 0, 120);
        }
        if (empty($out['sectionLabel'])) {
            $out['sectionLabel'] = self::inferSectionLabel($baseUrl);
        }
        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function extractLiveBlog(string $html, string $baseUrl): array
    {
        $blocks = [];
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            foreach ($matches[1] as $json) {
                $data = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (!is_array($data)) {
                    continue;
                }
                $items = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];
                foreach ($items as $item) {
                    if (!is_array($item) || ($item['@type'] ?? '') !== 'LiveBlogPosting') {
                        continue;
                    }
                    $updates = $item['liveBlogUpdate'] ?? [];
                    if (!is_array($updates)) {
                        continue;
                    }
                    foreach ($updates as $update) {
                        if (!is_array($update)) {
                            continue;
                        }
                        $headline = trim((string) ($update['headline'] ?? ''));
                        $body = trim((string) ($update['articleBody'] ?? ''));
                        $time = (string) ($update['datePublished'] ?? $update['dateModified'] ?? '');
                        $image = null;
                        if (!empty($update['image'])) {
                            $image = is_array($update['image'])
                                ? (string) ($update['image']['url'] ?? $update['image'][0]['url'] ?? $update['image'][0] ?? '')
                                : (string) $update['image'];
                            $image = self::absUrl($image, $baseUrl);
                        }
                        if ($headline === '' && $body === '') {
                            continue;
                        }
                        $htmlBody = '';
                        if ($body !== '') {
                            // Split long bodies into paragraphs when plain text
                            $paras = preg_split('/\n+/', $body) ?: [$body];
                            foreach ($paras as $para) {
                                $para = trim($para);
                                if ($para === '') {
                                    continue;
                                }
                                $htmlBody .= '<p>' . self::escapeKeepBreaks($para) . '</p>';
                            }
                        }
                        $blocks[] = [
                            'type' => 'live',
                            'headline' => $headline,
                            'time' => $time,
                            'html' => $htmlBody,
                            'image' => $image,
                        ];
                    }
                    if ($blocks !== []) {
                        return array_slice($blocks, 0, 40);
                    }
                }
            }
        }

        // DOM fallback: content-post cards
        if ($blocks === []) {
            $dom = self::dom($html);
            if ($dom) {
                $xp = new DOMXPath($dom);
                $posts = $xp->query('//*[@data-testid="content-post"]');
                if ($posts !== false) {
                    foreach ($posts as $post) {
                        if (!$post instanceof DOMElement) {
                            continue;
                        }
                        $headline = '';
                        $h = $xp->query('.//h2|.//h3', $post)->item(0);
                        if ($h) {
                            $headline = trim(self::nodeText($h));
                        }
                        $time = '';
                        $tNode = $xp->query('.//*[@data-testid="timestamp"]|.//time', $post)->item(0);
                        if ($tNode instanceof DOMElement) {
                            $time = $tNode->getAttribute('datetime') ?: trim(self::nodeText($tNode));
                        }
                        $htmlParts = '';
                        foreach ($xp->query('.//*[contains(@class,"RichTextContainer")]|.//*[@data-testid="rich-text"]|.//p', $post) ?: [] as $node) {
                            if (!$node instanceof DOMElement) {
                                continue;
                            }
                            $htmlParts .= self::sanitizeHtml($node->C14N() ?: '');
                        }
                        $imgSrc = null;
                        $img = $xp->query('.//img', $post)->item(0);
                        if ($img instanceof DOMElement) {
                            $imgSrc = self::absUrl(self::bestImgSrc($img), $baseUrl);
                        }
                        if ($headline === '' && $htmlParts === '') {
                            continue;
                        }
                        $blocks[] = [
                            'type' => 'live',
                            'headline' => $headline,
                            'time' => $time,
                            'html' => $htmlParts,
                            'image' => $imgSrc,
                        ];
                    }
                }
            }
        }

        return array_slice($blocks, 0, 40);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function extractBbcRich(string $html, string $baseUrl): array
    {
        $dom = self::dom($html);
        if (!$dom) {
            return [];
        }
        $xp = new DOMXPath($dom);
        $main = $xp->query('//*[@data-testid="main-content"]')->item(0) ?: $dom->documentElement;
        $blocks = [];

        // Walk significant nodes in document order inside main
        $nodes = $xp->query('.//*[@data-testid="rich-text" or @data-testid="image" or contains(@class,"RichTextContainer")]', $main);
        if ($nodes === false || $nodes->length === 0) {
            return self::extractHtmlRegion($html, $baseUrl, ['//*[@data-testid="main-content"]', '//article', '//main']);
        }

        $seen = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            // Skip nested duplicates (rich-text wraps RichTextContainer)
            $testid = $node->getAttribute('data-testid');
            $class = $node->getAttribute('class');

            if ($testid === 'image' || (str_contains($class, 'Figure') && $node->getElementsByTagName('img')->length)) {
                $img = $node->getElementsByTagName('img')->item(0);
                if ($img instanceof DOMElement) {
                    $src = self::absUrl(self::bestImgSrc($img), $baseUrl);
                    if ($src && !isset($seen['img:' . $src])) {
                        $seen['img:' . $src] = true;
                        $caption = '';
                        $fig = $node->getElementsByTagName('figcaption')->item(0);
                        if ($fig) {
                            $caption = self::cleanCaption(trim(self::nodeText($fig)));
                        }
                        if ($caption === '') {
                            $caption = self::cleanCaption(trim($img->getAttribute('alt')));
                        }
                        $blocks[] = [
                            'type' => 'img',
                            'src' => $src,
                            'alt' => $img->getAttribute('alt'),
                            'caption' => $caption,
                        ];
                    }
                }
                continue;
            }

            if ($testid === 'rich-text' || str_contains($class, 'RichTextContainer')) {
                // Prefer inner RichTextContainer when on rich-text wrapper
                $target = $node;
                if ($testid === 'rich-text') {
                    foreach ($node->getElementsByTagName('div') as $div) {
                        if ($div instanceof DOMElement && str_contains($div->getAttribute('class'), 'RichTextContainer')) {
                            $target = $div;
                            break;
                        }
                    }
                }
                $safe = self::sanitizeHtml($target->C14N() ?: '');
                $plain = trim(strip_tags($safe));
                if ($plain === '' || isset($seen['html:' . md5($plain)])) {
                    continue;
                }
                $seen['html:' . md5($plain)] = true;
                $blocks[] = ['type' => 'html', 'html' => $safe];
            }
        }

        return $blocks;
    }

    /**
     * @param list<string> $xpaths
     * @return list<array<string,mixed>>
     */
    private static function extractHtmlRegion(string $html, string $baseUrl, array $xpaths): array
    {
        $dom = self::dom($html);
        if (!$dom) {
            return [];
        }
        $xp = new DOMXPath($dom);
        $root = null;
        foreach ($xpaths as $path) {
            $nodes = $xp->query($path);
            if ($nodes !== false && $nodes->length > 0) {
                $root = $nodes->item(0);
                break;
            }
        }
        if (!$root) {
            return [];
        }

        $blocks = [];
        $seen = [];
        $walk = $xp->query('.//p|.//h2|.//h3|.//ul|.//ol|.//blockquote|.//img|.//figure', $root);
        if ($walk === false) {
            return [];
        }
        foreach ($walk as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if ($tag === 'img' || $tag === 'figure') {
                $img = $tag === 'img' ? $node : $node->getElementsByTagName('img')->item(0);
                if ($img instanceof DOMElement) {
                    $src = self::absUrl(self::bestImgSrc($img), $baseUrl);
                    if ($src && self::looksLikeContentImage($src, $img) && !isset($seen['img:' . $src])) {
                        $seen['img:' . $src] = true;
                        $caption = '';
                        if ($tag === 'figure') {
                            $fig = $node->getElementsByTagName('figcaption')->item(0);
                            if ($fig) {
                                $caption = self::cleanCaption(trim(self::nodeText($fig)));
                            }
                        }
                        if ($caption === '') {
                            $caption = self::cleanCaption(trim($img->getAttribute('alt')));
                        }
                        $blocks[] = [
                            'type' => 'img',
                            'src' => $src,
                            'alt' => $img->getAttribute('alt'),
                            'caption' => $caption,
                        ];
                    }
                }
                continue;
            }
            $safe = self::sanitizeHtml($node->C14N() ?: '');
            $plain = trim(strip_tags($safe));
            if ($plain === '' || mb_strlen($plain) < 20) {
                continue;
            }
            if (preg_match('/cookie|subscribe|newsletter|sign in|advertisement/i', $plain)) {
                continue;
            }
            $key = 'html:' . md5($plain);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $blocks[] = ['type' => 'html', 'html' => $safe];
        }
        return $blocks;
    }

    /**
     * @return array{blocks?:list<array<string,mixed>>,image?:string,description?:string}
     */
    private static function extractJsonLdArticle(string $html, string $baseUrl): array
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
                if (!array_intersect($types, ['NewsArticle', 'Article', 'ReportageNewsArticle', 'BlogPosting'])) {
                    continue;
                }
                if (!empty($item['image'])) {
                    $img = $item['image'];
                    if (is_array($img)) {
                        $img = $img['url'] ?? ($img[0]['url'] ?? ($img[0] ?? null));
                    }
                    if (is_string($img) && $img !== '') {
                        $out['image'] = self::absUrl($img, $baseUrl);
                    }
                }
                if (!empty($item['description']) && is_string($item['description'])) {
                    $out['description'] = trim($item['description']);
                }
                if (!empty($item['articleBody']) && is_string($item['articleBody'])) {
                    foreach (preg_split('/\n+/', trim($item['articleBody'])) ?: [] as $para) {
                        $para = trim($para);
                        if (mb_strlen($para) >= 40) {
                            $out['blocks'][] = ['type' => 'html', 'html' => '<p>' . self::escapeKeepBreaks($para) . '</p>'];
                        }
                    }
                }
            }
        }
        return $out;
    }

    private static function cleanCaption(string $caption): string
    {
        $caption = preg_replace('/^(Image caption|Figure caption|Photo caption|Caption)\s*[,:\-–—]?\s*/i', '', $caption) ?? $caption;
        $caption = preg_replace('/\s+/u', ' ', $caption) ?? $caption;
        return trim($caption);
    }

    public static function sanitizeHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#</?(script|style|iframe|object|embed|form|input|button|svg|path|use)[^>]*>#is', '', $html) ?? $html;

        $allowed = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'span'];
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="utf-8" ?><div id="n24root">' . $html . '</div>';
        $dom->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $dom->getElementById('n24root');
        if (!$root) {
            return '';
        }
        self::scrubNode($root, $allowed);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return trim($out);
    }

    /**
     * @param list<string> $allowed
     */
    private static function scrubNode(DOMNode $node, array $allowed): void
    {
        $remove = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $allowed, true)) {
                    // unwrap: keep children
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $remove[] = $child;
                    continue;
                }
                // scrub attributes
                $keepAttrs = [];
                if ($tag === 'a') {
                    $href = $child->getAttribute('href');
                    if (preg_match('#^https?://#i', $href)) {
                        $keepAttrs['href'] = $href;
                        $keepAttrs['target'] = '_blank';
                        $keepAttrs['rel'] = 'noopener noreferrer';
                    }
                }
                while ($child->hasAttributes()) {
                    $child->removeAttributeNode($child->attributes->item(0));
                }
                foreach ($keepAttrs as $k => $v) {
                    $child->setAttribute($k, $v);
                }
                // Convert BBC <b class=BoldText> stays as b
                self::scrubNode($child, $allowed);
            } elseif ($child->hasChildNodes()) {
                self::scrubNode($child, $allowed);
            }
        }
        foreach ($remove as $el) {
            if ($el->parentNode) {
                $el->parentNode->removeChild($el);
            }
        }
    }

    private static function escapeKeepBreaks(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
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
        $image = $get('og:image') ?? $get('og:image:secure_url') ?? $get('twitter:image');
        if ($image) {
            $out['image'] = self::absUrl($image, $baseUrl);
        }
        $site = $get('og:site_name');
        if ($site) {
            $out['siteName'] = trim($site);
        }
        $section = $get('article:section');
        if ($section) {
            $out['sectionLabel'] = strtoupper(trim($section));
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

    private static function bestImgSrc(DOMElement $img): string
    {
        $candidates = [];
        foreach (['src', 'data-src', 'data-original', 'data-lazy-src'] as $attr) {
            $v = trim($img->getAttribute($attr));
            if ($v !== '' && !str_starts_with($v, 'data:')) {
                $candidates[] = $v;
            }
        }
        foreach (['srcset', 'data-srcset'] as $attr) {
            $srcset = $img->getAttribute($attr);
            if ($srcset === '' && $img->parentNode instanceof DOMElement) {
                // <source srcset> inside picture
                continue;
            }
            if ($srcset !== '') {
                foreach (explode(',', $srcset) as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    $url = strtok($part, ' ') ?: '';
                    if ($url !== '') {
                        $candidates[] = $url;
                    }
                }
            }
        }
        // picture > source
        $parent = $img->parentNode;
        if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'picture') {
            foreach ($parent->getElementsByTagName('source') as $source) {
                if (!$source instanceof DOMElement) {
                    continue;
                }
                $srcset = $source->getAttribute('srcset');
                foreach (explode(',', $srcset) as $part) {
                    $url = trim(strtok(trim($part), ' ') ?: '');
                    if ($url !== '') {
                        $candidates[] = $url;
                    }
                }
            }
        }

        $best = '';
        $bestScore = -1;
        foreach ($candidates as $c) {
            $score = 0;
            if (preg_match('#/(\d{3,4})/#', $c, $m)) {
                $score = (int) $m[1];
            } elseif (str_contains($c, '1024') || str_contains($c, '1200') || str_contains($c, '976')) {
                $score = 1000;
            } elseif (str_contains($c, '640') || str_contains($c, '800')) {
                $score = 700;
            } else {
                $score = 100;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }
        return $best;
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
        $bad = ['sprite', 'pixel', 'spacer', 'logo', 'icon', 'avatar', 'emoji', '1x1', 'blank', 'placeholder'];
        $low = strtolower($src);
        foreach ($bad as $b) {
            if (str_contains($low, $b)) {
                return false;
            }
        }
        $w = (int) $img->getAttribute('width');
        $h = (int) $img->getAttribute('height');
        if (($w > 0 && $w < 80) || ($h > 0 && $h < 80)) {
            return false;
        }
        return true;
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
        if (strlen($body) > 1_800_000) {
            $body = substr($body, 0, 1_800_000);
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
