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
$countries = NewsFeed::COUNTRIES;
$qs = 'country=' . rawurlencode((string) $countryCode);

// Exact 24sata.hr mobile section colors (from their CSS)
$nav = [
    ['id' => 'world', 'label' => 'News', 'color' => '#d22328'],
    ['id' => 'entertainment', 'label' => 'Show', 'color' => '#f5a528'],
    ['id' => 'sports', 'label' => 'Sport', 'color' => '#40b14d'],
    ['id' => 'health', 'label' => 'Life&style', 'color' => '#efc10d'],
    ['id' => 'top', 'label' => 'Video', 'color' => '#9757f6', 'force' => 'video'],
    ['id' => 'business', 'label' => 'Express', 'color' => '#e53935'],
];
?>
<!DOCTYPE html>
<html lang="<?= e((string) ($seo['lang'] ?? 'en')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= e($docTitle) ?></title>
    <meta name="description" content="<?= e($desc) ?>">
    <meta name="robots" content="<?= e($robots) ?>">
    <link rel="canonical" href="<?= e($canonical) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Roboto:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/news-portal.css')) ?>?v=20260828-sata6">
    <?php foreach ($extraCss as $href): ?>
        <link rel="stylesheet" href="<?= e($href) ?>">
    <?php endforeach; ?>
</head>
<body class="s24-body">

<header class="s24-header">
    <div class="s24-top">
        <a class="s24-logo" href="<?= e(url('/news?' . $qs)) ?>" aria-label="Daily24 home">
            <svg xmlns="http://www.w3.org/2000/svg" width="47" height="38" viewBox="0 0 53 43" aria-hidden="true">
                <path fill="#F5A528" d="M0 43h53v-8H0z"/>
                <path fill="#D22328" d="M0 35h53V0H0z"/>
                <text x="26.5" y="25" text-anchor="middle" fill="#FFF" font-family="Arial Black, Helvetica, sans-serif" font-weight="900" font-size="24">24</text>
                <text x="26.5" y="41" text-anchor="middle" fill="#FFF" font-family="Arial Black, Helvetica, sans-serif" font-weight="800" font-size="7.5" letter-spacing="1.2">DAILY</text>
            </svg>
        </a>
        <div class="s24-icons">
            <button type="button" class="s24-icon" aria-label="Search" onclick="document.getElementById('s24-ask-input')?.focus()">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#222" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.2-3.2"/></svg>
            </button>
            <button type="button" class="s24-icon" aria-label="Photos" tabindex="-1">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#222" stroke-width="2"><path d="M4 7h3l2-2h6l2 2h3v12H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
            </button>
            <a class="s24-icon s24-icon--login" href="<?= e(url('/')) ?>">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#d7060c" stroke-width="2"><circle cx="12" cy="8" r="3.5"/><path d="M5 19c1.5-3.5 4-5 7-5s5.5 1.5 7 5"/></svg>
                <span>LOGIN</span>
            </a>
            <button type="button" class="s24-icon" aria-label="Notifications" tabindex="-1">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#222" stroke-width="2"><path d="M6 16v-5a6 6 0 1112 0v5l1.5 2H4.5L6 16z"/><path d="M10 19a2 2 0 004 0"/></svg>
            </button>
            <details class="s24-menu">
                <summary class="s24-icon" aria-label="Menu">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#222" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </summary>
                <div class="s24-menu-panel">
                    <form method="get" action="<?= e(url('/news')) ?>">
                        <?php if ($categoryId !== 'top'): ?>
                            <input type="hidden" name="category" value="<?= e($categoryId) ?>">
                        <?php endif; ?>
                        <label for="s24-country">Edition</label>
                        <select id="s24-country" name="country" onchange="this.form.submit()">
                            <?php foreach ($countries as $c): ?>
                                <option value="<?= e($c['code']) ?>" <?= $countryCode === $c['code'] ? 'selected' : '' ?>>
                                    <?= e($c['native']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <a href="<?= e(url('/')) ?>"><?= e($site) ?></a>
                </div>
            </details>
        </div>
    </div>

    <nav class="s24-nav" aria-label="Sections">
        <ul class="s24-nav-list">
            <?php foreach ($nav as $item):
                $href = url('/news?' . $qs . '&category=' . rawurlencode($item['id']));
                $active = ($categoryId === $item['id'] && empty($item['force']))
                    || ($categoryId === 'top' && $item['id'] === 'world' && empty($item['force']));
            ?>
                <li style="--s24-cat: <?= e($item['color']) ?>">
                    <a class="<?= $active ? 'is-active' : '' ?>" href="<?= e($href) ?>"><?= e($item['label']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</header>

<div class="s24-ask-wrap">
    <form class="s24-ask" method="get" action="<?= e(url('/news')) ?>">
        <input type="hidden" name="country" value="<?= e($countryCode) ?>">
        <?php if ($categoryId !== 'top'): ?>
            <input type="hidden" name="category" value="<?= e($categoryId) ?>">
        <?php endif; ?>
        <span class="s24-ask-brand" aria-hidden="true"><b>24</b>ASK</span>
        <div class="s24-ask-field">
            <span class="s24-ask-star" aria-hidden="true">✦</span>
            <input id="s24-ask-input" name="q" value="<?= e((string) ($q ?? '')) ?>" placeholder="Ask anything…" aria-label="Ask anything">
        </div>
        <button type="submit" class="s24-ask-go" aria-label="Search">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#fff" stroke-width="2.5"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>
</div>

<?php require $content; ?>

<footer class="s24-footer">
    <div class="s24-footer-inner">
        <div class="s24-footer-brand"><span>24</span>daily</div>
        <p>© <?= date('Y') ?> Daily24 — headlines from public feeds. Credit stays with the original publisher.</p>
        <div class="s24-footer-links">
            <a href="<?= e(url('/news')) ?>">News</a>
            <a href="<?= e(url('/news?category=sports')) ?>">Sport</a>
            <a href="<?= e(url('/news?category=technology')) ?>">Sci/Tech</a>
            <a href="<?= e(url('/')) ?>"><?= e($site) ?></a>
        </div>
    </div>
</footer>
</body>
</html>
