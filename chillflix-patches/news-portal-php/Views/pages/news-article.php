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
if ($image === '') {
    $image = NewsFeed::placeholder((string) $article['id']);
} elseif (!str_starts_with($image, 'data:')) {
    $image = url('/api/news/image?u=' . rawurlencode($image));
}
$body = trim((string) ($article['body'] ?? ''));
$summary = trim((string) ($article['summary'] ?? ''));
if ($body === '') {
    $body = $summary;
}
// Split into short paragraphs for readability
$paras = preg_split('/\n+/', $body) ?: [];
if (count($paras) === 1 && mb_strlen($body) > 280) {
    $paras = preg_split('/(?<=[.!?])\s+(?=[A-Z“"])/u', $body, 4) ?: [$body];
}
?>
<main class="n24-main">
    <div class="n24-wrap">
        <article class="n24-article">
            <a class="n24-back" href="<?= e(url('/news?country=' . rawurlencode($countryCode))) ?>">← Back to news</a>
            <div><span class="n24-tag"><?= e((string) $article['category']) ?></span></div>
            <h1><?= e((string) $article['title']) ?></h1>
            <div class="n24-article-meta">
                <?php if ($published): ?><span><?= e($published) ?> · </span><?php endif; ?>
                <span><?= e($sourceName) ?></span>
            </div>
            <figure class="n24-article-figure">
                <img src="<?= e($image) ?>" alt="<?= e((string) $article['title']) ?>" loading="eager">
            </figure>
            <div class="n24-article-body">
                <?php if ($body === ''): ?>
                    <p>Full story is available from the original publisher. Open the source link below for the complete article.</p>
                <?php else: ?>
                    <?php foreach ($paras as $para): ?>
                        <?php $para = trim((string) $para); if ($para === '') continue; ?>
                        <p><?= e($para) ?></p>
                    <?php endforeach; ?>
                <?php endif; ?>
                <p class="n24-read-full">
                    <a href="<?= e($originalUrl) ?>" target="_blank" rel="noopener noreferrer">
                        Read the full article on <?= e($sourceName) ?> →
                    </a>
                </p>
            </div>

            <aside class="n24-credits" aria-label="Source credits">
                <strong>Source / credits</strong>
                This story was aggregated from
                <a href="<?= e($sourceUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($sourceName) ?></a>.
                We do not claim ownership of the original reporting. Please visit the publisher for the full article, corrections, and licensing.
                <div class="n24-credits-url">
                    Original URL:
                    <a href="<?= e($originalUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($originalUrl) ?></a>
                </div>
            </aside>
        </article>
    </div>
</main>
