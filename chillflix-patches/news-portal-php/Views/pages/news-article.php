<?php
/** @var array<string,mixed> $article */
/** @var string $countryCode */

$published = null;
if (!empty($article['publishedAt'])) {
    $ts = strtotime((string) $article['publishedAt']);
    if ($ts) {
        $published = date('d.m.Y. H:i', $ts);
    }
}
$sourceName = (string) ($article['sourceName'] ?? 'Publisher');
$sourceUrl = (string) ($article['sourceUrl'] ?: $article['url']);
$originalUrl = (string) $article['url'];
$isLive = !empty($article['isLive']) || str_contains(strtolower($originalUrl), '/live/');
$category = strtoupper((string) ($article['category'] ?? ($isLive ? 'LIVE' : 'WORLD')));
if ($category === 'NEWS' || $category === 'TOP') {
    $category = $isLive ? 'LIVE' : 'WORLD';
}

$sectionMap = [
    'WORLD' => 'NEWS',
    'EUROPE' => 'NEWS',
    'POLITICS' => 'NEWS',
    'SPORT' => 'SPORT',
    'SPORTS' => 'SPORT',
    'SCI/TECH' => 'SCI/TECH',
    'TECH' => 'SCI/TECH',
    'TECHNOLOGY' => 'SCI/TECH',
    'BUSINESS' => 'EXPRESS',
    'SHOW' => 'SHOW',
    'ENTERTAINMENT' => 'SHOW',
    'LIFE' => 'LIFESTYLE',
    'HEALTH' => 'LIFESTYLE',
    'LIVE' => 'LIVE',
];
$sectionLabel = $sectionMap[$category] ?? $category;
if ($isLive) {
    $sectionLabel = 'LIVE';
}

$image = (string) ($article['image'] ?: '');
$heroSrc = $image === ''
    ? NewsFeed::placeholder((string) $article['id'])
    : (str_starts_with($image, 'data:') ? $image : url('/api/news/image?u=' . rawurlencode($image)));

$imgUrl = static function (string $src): string {
    if ($src === '' || str_starts_with($src, 'data:')) {
        return $src;
    }
    return url('/api/news/image?u=' . rawurlencode($src));
};

$cleanCap = static function (string $cap) use ($sourceName): string {
    $cap = preg_replace('/^(Image caption|Figure caption|Photo caption|Caption)\s*[,:\-–—]?\s*/i', '', $cap) ?? $cap;
    $cap = trim($cap);
    if ($cap === '') {
        return '';
    }
    // 24sata-style credit line when it looks like a photo credit
    if (preg_match('/^(foto|photo|ilustr|image|getty|reuters|afp|pixsell|source|copyright)\b/i', $cap)) {
        if (!str_contains(mb_strtoupper($cap), 'FOTO') && !str_contains(mb_strtoupper($cap), 'PHOTO')) {
            return 'Foto: ' . $cap;
        }
        return $cap;
    }
    // Descriptive caption → credit-style suffix
    if (mb_strlen($cap) < 120 && !str_contains($cap, '|')) {
        return $cap . ' | Foto: ' . $sourceName;
    }
    return $cap;
};

/** @var list<array<string,mixed>> $blocks */
$blocks = [];
if (!empty($article['blocks']) && is_array($article['blocks'])) {
    $blocks = $article['blocks'];
} else {
    $body = trim((string) ($article['body'] ?? $article['summary'] ?? ''));
    if ($body !== '') {
        foreach (preg_split('/\n+/', $body) ?: [] as $para) {
            $para = trim((string) $para);
            if ($para !== '') {
                $blocks[] = ['type' => 'html', 'html' => '<p>' . e($para) . '</p>'];
            }
        }
    }
}

$formatTime = static function (?string $iso): string {
    if (!$iso) {
        return '';
    }
    $ts = strtotime($iso);
    return $ts ? date('d.m.Y. H:i', $ts) : $iso;
};

$heroCaption = '';
foreach ($blocks as $b) {
    if (($b['type'] ?? '') === 'img') {
        $heroCaption = $cleanCap(trim((string) ($b['caption'] ?? $b['alt'] ?? '')));
        break;
    }
}
if ($heroCaption === '' && $image !== '') {
    $heroCaption = 'Foto: ' . $sourceName;
}
?>
<main class="s24-main">
    <div class="n24-wrap">
        <article class="n24-article<?= $isLive ? ' n24-article--live' : '' ?>">
            <a class="n24-back" href="<?= e(url('/news?country=' . rawurlencode($countryCode))) ?>">← Back</a>

            <span class="n24-article-kicker"><?= e($sectionLabel) ?></span>
            <h1 class="n24-article-title"><?= e((string) $article['title']) ?></h1>
            <?php if (!empty($article['summary'])): ?>
                <p class="n24-article-deck"><?= e((string) $article['summary']) ?></p>
            <?php endif; ?>

            <div class="n24-byline">
                <strong><?= e($sourceName) ?></strong>
                <?php if ($published): ?><span><?= e($published) ?></span><?php endif; ?>
            </div>

            <?php if (!$isLive || $image !== ''): ?>
            <figure class="n24-hero-fig">
                <img src="<?= e($heroSrc) ?>" alt="<?= e((string) $article['title']) ?>" loading="eager">
                <?php if ($heroCaption !== ''): ?>
                    <figcaption class="n24-hero-cap"><?= e($heroCaption) ?></figcaption>
                <?php endif; ?>
            </figure>
            <?php endif; ?>

            <div class="n24-body-copy">
                <?php if ($blocks === []): ?>
                    <p>We could not load the full story body right now. Open the original publisher link below.</p>
                <?php else: ?>
                    <?php
                    $skippedHeroImg = false;
                    foreach ($blocks as $block):
                        $type = (string) ($block['type'] ?? '');
                        if (!$skippedHeroImg && $type === 'img' && !$isLive) {
                            $skippedHeroImg = true;
                            continue;
                        }
                    ?>

                        <?php if ($type === 'live'): ?>
                            <section class="n24-live-post">
                                <div class="n24-live-time">
                                    <?php if (!empty($block['time'])): ?>
                                        <time datetime="<?= e((string) $block['time']) ?>"><?= e($formatTime((string) $block['time'])) ?></time>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($block['headline'])): ?>
                                    <h3><?= e((string) $block['headline']) ?></h3>
                                <?php endif; ?>
                                <?php if (!empty($block['image'])): ?>
                                    <figure>
                                        <img src="<?= e($imgUrl((string) $block['image'])) ?>" alt="" loading="lazy">
                                        <?php
                                        $liveCap = $cleanCap(trim((string) ($block['headline'] ?? '')));
                                        if ($liveCap !== ''):
                                        ?>
                                            <figcaption class="n24-img-cap"><?= e($liveCap) ?></figcaption>
                                        <?php endif; ?>
                                    </figure>
                                <?php endif; ?>
                                <?php if (!empty($block['html'])): ?>
                                    <?= $block['html'] /* sanitized upstream */ ?>
                                <?php endif; ?>
                            </section>

                        <?php elseif ($type === 'img' && !empty($block['src'])): ?>
                            <?php
                            $cap = trim((string) ($block['caption'] ?? ''));
                            if ($cap === '') {
                                $cap = trim((string) ($block['alt'] ?? ''));
                            }
                            $cap = $cleanCap($cap);
                            ?>
                            <figure>
                                <img src="<?= e($imgUrl((string) $block['src'])) ?>" alt="<?= e((string) ($block['alt'] ?? '')) ?>" loading="lazy">
                                <?php if ($cap !== ''): ?>
                                    <figcaption class="n24-img-cap"><?= e($cap) ?></figcaption>
                                <?php endif; ?>
                            </figure>

                        <?php elseif ($type === 'html' && !empty($block['html'])): ?>
                            <?= $block['html'] /* sanitized upstream */ ?>

                        <?php elseif ($type === 'h2' && !empty($block['text'])): ?>
                            <h2><?= e((string) $block['text']) ?></h2>

                        <?php elseif (!empty($block['text'])): ?>
                            <p><?= e((string) $block['text']) ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <aside class="n24-credits" aria-label="Source credits">
                <strong>Source / credits</strong>
                Full reporting by
                <a href="<?= e($sourceUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($sourceName) ?></a>.
                Republished here for reading convenience with attribution.
                <div style="margin-top:8px;word-break:break-all">
                    <a href="<?= e($originalUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($originalUrl) ?></a>
                </div>
            </aside>
        </article>
    </div>
</main>
