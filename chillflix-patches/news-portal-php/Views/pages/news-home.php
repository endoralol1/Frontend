<?php
/** @var list<array<string,mixed>> $articles */
/** @var string $countryCode */

if (!function_exists('n24_img_src')) {
    function n24_img_src(array $article): string
    {
        $image = (string) ($article['image'] ?? '');
        if ($image === '') {
            return NewsFeed::placeholder((string) $article['id']);
        }
        if (str_starts_with($image, 'data:')) {
            return $image;
        }
        return url('/api/news/image?u=' . rawurlencode($image));
    }
}

$lead = $articles[0] ?? null;
$sideA = $articles[1] ?? null;
$sideB = $articles[2] ?? null;
$rest = array_slice($articles, 3);
?>
<main class="n24-main">
    <div class="n24-wrap">
        <?php if (!$lead): ?>
            <div class="n24-empty">No stories right now. Try another country or refresh in a minute.</div>
        <?php else: ?>
            <section class="n24-hero">
                <article class="n24-hero-lead">
                    <a href="<?= e(NewsFeed::articleUrl($lead, $countryCode)) ?>">
                        <div class="n24-media">
                            <img src="<?= e(n24_img_src($lead)) ?>" alt="" loading="eager">
                        </div>
                        <span class="n24-tag"><?= e((string) $lead['category']) ?></span>
                        <h1><?= e((string) $lead['title']) ?></h1>
                        <?php if (!empty($lead['summary'])): ?>
                            <p class="n24-lead-summary"><?= e((string) $lead['summary']) ?></p>
                        <?php endif; ?>
                    </a>
                </article>
                <div class="n24-side">
                    <?php if ($sideA): ?>
                        <article class="n24-side-card">
                            <a href="<?= e(NewsFeed::articleUrl($sideA, $countryCode)) ?>">
                                <div class="n24-media">
                                    <img src="<?= e(n24_img_src($sideA)) ?>" alt="" loading="lazy">
                                </div>
                                <span class="n24-tag alt"><?= e((string) $sideA['category']) ?></span>
                                <h2><?= e((string) $sideA['title']) ?></h2>
                                <?php if (!empty($sideA['summary'])): ?>
                                    <p><?= e((string) $sideA['summary']) ?></p>
                                <?php endif; ?>
                            </a>
                        </article>
                    <?php endif; ?>
                    <?php if ($sideB): ?>
                        <article class="n24-side-card n24-side-card--row">
                            <div class="n24-side-card__text">
                                <a href="<?= e(NewsFeed::articleUrl($sideB, $countryCode)) ?>">
                                    <span class="n24-tag orange"><?= e((string) $sideB['category']) ?></span>
                                    <h2><?= e((string) $sideB['title']) ?></h2>
                                    <?php if (!empty($sideB['summary'])): ?>
                                        <p><?= e((string) $sideB['summary']) ?></p>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <a class="n24-side-card__thumb" href="<?= e(NewsFeed::articleUrl($sideB, $countryCode)) ?>">
                                <div class="n24-media n24-media--square">
                                    <img src="<?= e(n24_img_src($sideB)) ?>" alt="" loading="lazy">
                                </div>
                            </a>
                        </article>
                    <?php endif; ?>
                </div>
            </section>

            <div class="n24-ad" aria-hidden="true">AD</div>

            <h2 class="n24-section-title">Latest</h2>
            <section class="n24-grid">
                <?php foreach ($rest as $i => $article): ?>
                    <?php $tagClass = $i % 3 === 1 ? 'alt' : ($i % 3 === 2 ? 'orange' : ''); ?>
                    <article class="n24-card">
                        <a href="<?= e(NewsFeed::articleUrl($article, $countryCode)) ?>">
                            <div class="n24-media">
                                <img src="<?= e(n24_img_src($article)) ?>" alt="" loading="lazy">
                            </div>
                            <span class="n24-tag <?= e($tagClass) ?>"><?= e((string) $article['category']) ?></span>
                            <h3><?= e((string) $article['title']) ?></h3>
                            <?php if (!empty($article['summary'])): ?>
                                <p><?= e((string) $article['summary']) ?></p>
                            <?php endif; ?>
                        </a>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </div>
</main>
