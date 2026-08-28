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
?>
<main class="n24-main">
    <div class="n24-wrap">
        <article class="n24-article">
            <a href="<?= e(url('/news?country=' . rawurlencode($countryCode))) ?>">← Back to news</a>
            <div><span class="n24-tag"><?= e((string) $article['category']) ?></span></div>
            <h1><?= e((string) $article['title']) ?></h1>
            <div class="n24-article-meta">
                <?php if ($published): ?><span><?= e($published) ?> · </span><?php endif; ?>
                <span><?= e($sourceName) ?></span>
            </div>
            <img src="<?= e($article['image'] ?: NewsFeed::placeholder((string) $article['id'])) ?>" alt="">
            <div class="n24-article-body">
                <?php if (!empty($article['summary'])): ?>
                    <p class="n24-summary" style="font-size:1.05rem;color:#222"><?= e((string) $article['summary']) ?></p>
                <?php else: ?>
                    <p>Full story is available from the original publisher. Open the source link below for the complete article.</p>
                <?php endif; ?>
                <p style="margin-top:1.25rem">
                    <a href="<?= e($originalUrl) ?>" target="_blank" rel="noopener noreferrer" style="color:#e30613;font-weight:800">
                        Read the full article on <?= e($sourceName) ?> →
                    </a>
                </p>
            </div>

            <aside class="n24-credits" aria-label="Source credits">
                <strong>Source / credits</strong>
                This story was aggregated from
                <a href="<?= e($sourceUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($sourceName) ?></a>.
                We do not claim ownership of the original reporting. Please visit the publisher for the full article, corrections, and licensing.
                <div style="margin-top:.55rem">
                    Original URL:
                    <a href="<?= e($originalUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($originalUrl) ?></a>
                </div>
            </aside>
        </article>
    </div>
</main>
