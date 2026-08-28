<?php
/** @var list<array<string,mixed>> $articles */
/** @var string $countryCode */
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
                        <img src="<?= e($lead['image'] ?: NewsFeed::placeholder((string) $lead['id'])) ?>" alt="" loading="eager">
                        <span class="n24-tag"><?= e((string) $lead['category']) ?></span>
                        <h1><?= e((string) $lead['title']) ?></h1>
                    </a>
                </article>
                <div class="n24-side">
                    <?php if ($sideA): ?>
                        <article class="n24-side-card">
                            <a href="<?= e(NewsFeed::articleUrl($sideA, $countryCode)) ?>">
                                <img src="<?= e($sideA['image'] ?: NewsFeed::placeholder((string) $sideA['id'])) ?>" alt="" loading="lazy">
                                <span class="n24-tag alt"><?= e((string) $sideA['category']) ?></span>
                                <h2><?= e((string) $sideA['title']) ?></h2>
                                <?php if (!empty($sideA['summary'])): ?>
                                    <p><?= e((string) $sideA['summary']) ?></p>
                                <?php endif; ?>
                            </a>
                        </article>
                    <?php endif; ?>
                    <?php if ($sideB): ?>
                        <article class="n24-side-card compact">
                            <div>
                                <a href="<?= e(NewsFeed::articleUrl($sideB, $countryCode)) ?>">
                                    <span class="n24-tag orange"><?= e((string) $sideB['category']) ?></span>
                                    <h2><?= e((string) $sideB['title']) ?></h2>
                                    <?php if (!empty($sideB['summary'])): ?>
                                        <p><?= e((string) $sideB['summary']) ?></p>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <a href="<?= e(NewsFeed::articleUrl($sideB, $countryCode)) ?>">
                                <img src="<?= e($sideB['image'] ?: NewsFeed::placeholder((string) $sideB['id'])) ?>" alt="" loading="lazy">
                            </a>
                        </article>
                    <?php endif; ?>
                </div>
            </section>

            <div class="n24-ad">AD</div>

            <h2 class="n24-section-title">Latest</h2>
            <section class="n24-grid">
                <?php foreach ($rest as $i => $article): ?>
                    <?php
                    $tagClass = $i % 3 === 1 ? 'alt' : ($i % 3 === 2 ? 'orange' : '');
                    ?>
                    <article class="n24-card">
                        <a href="<?= e(NewsFeed::articleUrl($article, $countryCode)) ?>">
                            <img src="<?= e($article['image'] ?: NewsFeed::placeholder((string) $article['id'])) ?>" alt="" loading="lazy">
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
