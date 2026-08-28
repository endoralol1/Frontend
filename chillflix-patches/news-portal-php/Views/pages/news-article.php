<?php
/** @var array<string,mixed> $article */
/** @var string $countryCode */

$published = null;
if (!empty($article['publishedAt'])) {
    $ts = strtotime((string) $article['publishedAt']);
    if ($ts) {
        $published = date('M j, Y g:i A', $ts);
    }
}
$sourceName = (string) ($article['sourceName'] ?? 'Publisher');
$sourceUrl = (string) ($article['sourceUrl'] ?: $article['url']);
$originalUrl = (string) $article['url'];

$image = (string) ($article['image'] ?: '');
$heroSrc = $image === ''
    ? NewsFeed::placeholder((string) $article['id'])
    : (str_starts_with($image, 'data:') ? $image : url('/api/news/image?u=' . rawurlencode($image)));

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
                $blocks[] = ['type' => 'p', 'text' => $para];
            }
        }
    }
}

$imgUrl = static function (string $src): string {
    if ($src === '' || str_starts_with($src, 'data:')) {
        return $src;
    }
    return url('/api/news/image?u=' . rawurlencode($src));
};
?>
<main class="n24-main n24-main--article">
    <div class="n24-wrap n24-wrap--article">
        <article class="n24-article">
            <a class="n24-back" href="<?= e(url('/news?country=' . rawurlencode($countryCode))) ?>">← Back to news</a>
            <div><span class="n24-tag"><?= e((string) $article['category']) ?></span></div>
            <h1><?= e((string) $article['title']) ?></h1>
            <div class="n24-article-meta">
                <?php if ($published): ?><span><?= e($published) ?> · </span><?php endif; ?>
                <span><?= e($sourceName) ?></span>
            </div>

            <figure class="n24-article-figure">
                <img src="<?= e($heroSrc) ?>" alt="<?= e((string) $article['title']) ?>" loading="eager">
            </figure>

            <div class="n24-article-body">
                <?php if ($blocks === []): ?>
                    <p>We could not load the full story body right now. Open the original publisher link below.</p>
                <?php else: ?>
                    <?php foreach ($blocks as $block): ?>
                        <?php
                        $type = (string) ($block['type'] ?? 'p');
                        if ($type === 'img' && !empty($block['src'])):
                            $src = $imgUrl((string) $block['src']);
                        ?>
                            <figure class="n24-inline-figure">
                                <img src="<?= e($src) ?>" alt="<?= e((string) ($block['alt'] ?? '')) ?>" loading="lazy">
                            </figure>
                        <?php elseif ($type === 'h2' && !empty($block['text'])): ?>
                            <h2><?= e((string) $block['text']) ?></h2>
                        <?php elseif (!empty($block['text'])): ?>
                            <p><?= e((string) $block['text']) ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>

                <p class="n24-read-full">
                    <a href="<?= e($originalUrl) ?>" target="_blank" rel="noopener noreferrer">
                        View original on <?= e($sourceName) ?> →
                    </a>
                </p>
            </div>

            <aside class="n24-credits" aria-label="Source credits">
                <strong>Source / credits</strong>
                Full reporting by
                <a href="<?= e($sourceUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($sourceName) ?></a>.
                Republished here for reading convenience with attribution. Visit the publisher for updates, corrections, and licensing.
                <div class="n24-credits-url">
                    Original URL:
                    <a href="<?= e($originalUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($originalUrl) ?></a>
                </div>
            </aside>
        </article>
    </div>
</main>
