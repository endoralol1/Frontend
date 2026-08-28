<?php
/** @var array $seo */
$site = (string) config('site_name');
$docTitle = $seo['title'] ?? ('Daily24 News | ' . $site);
$desc = $seo['description'] ?? 'Global news portal with country editions.';
$canonical = $seo['canonical'] ?? url('/news');
$robots = $seo['robots'] ?? 'index, follow';
$extraCss = $extraCss ?? [];
$countryCode = $countryCode ?? 'GLOBAL';
$categoryId = $categoryId ?? 'top';
$categories = NewsFeed::CATEGORIES;
$countries = NewsFeed::COUNTRIES;
$qs = 'country=' . rawurlencode((string) $countryCode);
?>
<!DOCTYPE html>
<html lang="<?= e((string) ($seo['lang'] ?? 'en')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($docTitle) ?></title>
    <meta name="description" content="<?= e($desc) ?>">
    <meta name="robots" content="<?= e($robots) ?>">
    <link rel="canonical" href="<?= e($canonical) ?>">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700;900&display=swap">
    <link rel="stylesheet" href="<?= e(asset('css/news-portal.css')) ?>?v=20260828-n24php3">
    <?php foreach ($extraCss as $href): ?>
        <link rel="stylesheet" href="<?= e($href) ?>">
    <?php endforeach; ?>
</head>
<body class="n24-root">
<header class="n24-topbar">
    <div class="n24-wrap n24-topbar-inner">
        <a class="n24-logo" href="<?= e(url('/news?' . $qs)) ?>" aria-label="Daily24 home">
            <span class="n24-logo-24">24</span>
            <span class="n24-logo-name">DAILY</span>
        </a>
        <button type="button" class="n24-burger" aria-label="Menu"><span></span><span></span><span></span></button>
        <nav class="n24-nav" aria-label="Sections">
            <a class="n24-plus" href="<?= e(url('/news?' . $qs)) ?>">Top</a>
            <?php foreach ($categories as $cat): ?>
                <?php if ($cat['id'] === 'top') continue; ?>
                <a class="<?= $categoryId === $cat['id'] ? 'is-active' : '' ?>"
                   href="<?= e(url('/news?' . $qs . '&category=' . rawurlencode($cat['id']))) ?>">
                    <?= e($cat['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="n24-actions">
            <form method="get" action="<?= e(url('/news')) ?>" id="n24-country-form">
                <?php if ($categoryId !== 'top'): ?>
                    <input type="hidden" name="category" value="<?= e($categoryId) ?>">
                <?php endif; ?>
                <label class="sr-only" for="n24-country">Country / language</label>
                <select class="n24-country" id="n24-country" name="country" onchange="this.form.submit()" aria-label="Country and language">
                    <?php foreach ($countries as $c): ?>
                        <option value="<?= e($c['code']) ?>" <?= $countryCode === $c['code'] ? 'selected' : '' ?>>
                            <?= e($c['native']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
</header>

<div class="n24-ask">
    <form class="n24-wrap n24-ask-inner" method="get" action="<?= e(url('/news')) ?>">
        <input type="hidden" name="country" value="<?= e($countryCode) ?>">
        <?php if ($categoryId !== 'top'): ?>
            <input type="hidden" name="category" value="<?= e($categoryId) ?>">
        <?php endif; ?>
        <span class="n24-ask-brand">24ASK</span>
        <input name="q" value="<?= e((string) ($q ?? '')) ?>" placeholder="+ Ask anything about the news…" aria-label="Search news">
        <button type="submit" aria-label="Search">→</button>
    </form>
</div>

<?php require $content; ?>

<footer class="n24-footer">
    <div class="n24-wrap">
        <div class="n24-footer-grid">
            <a href="<?= e(url('/news')) ?>">News</a>
            <a href="<?= e(url('/news?category=world')) ?>">World</a>
            <a href="<?= e(url('/news?category=sports')) ?>">Sport</a>
            <a href="<?= e(url('/news?category=technology')) ?>">Sci/Tech</a>
            <a href="<?= e(url('/')) ?>"><?= e($site) ?></a>
        </div>
        <small>
            © <?= date('Y') ?> Daily24 — headlines aggregated from public news feeds.
            Credit always remains with the original publisher.
        </small>
    </div>
</footer>
</body>
</html>
