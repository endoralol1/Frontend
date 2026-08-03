<?php
/** @var array $seo */
$site = (string) config('site_name');
// Do not overwrite page `$title` (media name) — SEO uses $docTitle
$docTitle = $seo['title'] ?? $site;
$desc = $seo['description'] ?? ($site . ' — ' . config('site_tagline'));
$canonical = $seo['canonical'] ?? url(current_path());
$image = $seo['image'] ?? logo_url();
$robots = $seo['robots'] ?? 'index, follow';
$ogType = $seo['type'] ?? 'website';
$keywords = $seo['keywords'] ?? 'watch movies online, free movies, TV series, streaming, HD movies, ' . $site;
$headerClass = $headerClass ?? 'absolute';
$bodyClass = $bodyClass ?? '';
$extraCss = $extraCss ?? [];
$extraJs = $extraJs ?? [];
?>
<!DOCTYPE html>
<html lang="<?= e((string) config('lang', 'en')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?= e($docTitle) ?></title>
    <meta name="description" content="<?= e($desc) ?>">
    <meta name="keywords" content="<?= e($keywords) ?>">
    <meta name="robots" content="<?= e($robots) ?>">
    <meta name="theme-color" content="<?= e((string) config('theme_color')) ?>">
    <meta name="referrer" content="origin">
    <link rel="canonical" href="<?= e($canonical) ?>">

    <meta property="og:locale" content="<?= e((string) config('locale')) ?>">
    <meta property="og:type" content="<?= e($ogType) ?>">
    <meta property="og:title" content="<?= e($docTitle) ?>">
    <meta property="og:description" content="<?= e($desc) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:site_name" content="<?= e($site) ?>">
    <meta property="og:image" content="<?= e($image) ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($docTitle) ?>">
    <meta name="twitter:description" content="<?= e($desc) ?>">
    <meta name="twitter:image" content="<?= e($image) ?>">

    <link rel="icon" href="<?= e(logo_url()) ?>" type="image/webp">
    <link rel="apple-touch-icon" href="<?= e(logo_url()) ?>">

    <link rel="dns-prefetch" href="https://image.tmdb.org">
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://image.tmdb.org" crossorigin>
    <link rel="preload" href="<?= e(logo_url()) ?>" as="image" fetchpriority="high">

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>?v=20260803-ui143" fetchpriority="high">
    <link rel="stylesheet" href="<?= e(asset('css/views.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/continue-watching.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/site-notice.css')) ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@iconscout/unicons@4.0.8/css/line.min.css">
    <link rel="stylesheet" href="<?= e(asset('vendor/tooltipster.bundle.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/swiper.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/episode-carousel.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=20260803-ui143">
    <?php foreach ($extraCss as $css): ?>
        <link rel="stylesheet" href="<?= e($css) ?>">
    <?php endforeach; ?>

    <script src="<?= e(asset('vendor/jquery.min.js')) ?>"></script>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
    <script>
        window.CF_BASE = <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>;
        window.APP = {
            baseUrl: <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>,
            siteName: <?= json_encode($site, JSON_UNESCAPED_UNICODE) ?>,
            mainOrigin: <?= json_encode((string) config('main_origin', 'https://www.chillflix.lol'), JSON_UNESCAPED_SLASHES) ?>,
            turnstileSiteKey: <?= json_encode((string) config('turnstile_site_key', ''), JSON_UNESCAPED_SLASHES) ?>,
            imgBase: <?= json_encode(rtrim((string) config('tmdb_img'), '/'), JSON_UNESCAPED_SLASHES) ?>
        };
        /* Calmer lazyload: fewer concurrent image downloads while scrolling */
        window.lazySizesConfig = window.lazySizesConfig || {};
        window.lazySizesConfig.expand = 140;
        window.lazySizesConfig.expFactor = 1.2;
        window.lazySizesConfig.loadMode = 1;
        window.lazySizesConfig.hFac = 0.25;
        window.lazySizesConfig.loadHidden = false;
    </script>

    <?php if (($seo['schema'] ?? '') === 'home'): ?>
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'WebSite',
                'name' => $site,
                'url' => base_url() . '/',
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => url('/search') . '?keyword={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ],
            [
                '@type' => 'Organization',
                'name' => $site,
                'url' => base_url() . '/',
                'logo' => logo_url(),
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
    </script>
    <?php endif; ?>

    <?php if (($seo['schema'] ?? '') === 'watch' && isset($item, $type, $title)): ?>
    <?php
        $schemaActors = [];
        foreach (array_slice($item['credits']['cast'] ?? [], 0, 8) as $schemaCast) {
            if (empty($schemaCast['name'])) {
                continue;
            }
            $schemaActors[] = [
                '@type' => 'Person',
                'name' => $schemaCast['name'],
            ];
        }
        $schemaDirectors = [];
        foreach ($item['credits']['crew'] ?? [] as $schemaCrew) {
            if (($schemaCrew['job'] ?? '') === 'Director' && !empty($schemaCrew['name'])) {
                $schemaDirectors[] = [
                    '@type' => 'Person',
                    'name' => $schemaCrew['name'],
                ];
            }
        }
        $schemaGenres = array_values(array_filter(array_map(
            static fn ($g) => $g['name'] ?? null,
            $item['genres'] ?? []
        )));
        $schemaPayload = array_filter([
            '@context' => 'https://schema.org',
            '@type' => $type === 'tv' ? 'TVSeries' : 'Movie',
            'name' => $title,
            'description' => $overview ?? $desc,
            'image' => $image,
            'datePublished' => date_of($item) ?: null,
            'genre' => $schemaGenres ?: null,
            'director' => $schemaDirectors ?: null,
            'actor' => $schemaActors ?: null,
            'duration' => ($type === 'movie' && !empty($item['runtime']))
                ? 'PT' . (int) $item['runtime'] . 'M'
                : null,
            'aggregateRating' => isset($item['vote_average']) && $item['vote_average'] > 0 ? [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $item['vote_average'], 1),
                'ratingCount' => (int) ($item['vote_count'] ?? 1),
                'bestRating' => 10,
                'worstRating' => 1,
            ] : null,
            'url' => $canonical,
        ], static fn ($v) => $v !== null && $v !== [] && $v !== '');
    ?>
    <script type="application/ld+json">
    <?= json_encode($schemaPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
    </script>
    <?php endif; ?>
</head>
<body class="<?= e($bodyClass) ?>">
<div class="cf-ambient" aria-hidden="true">
    <div class="cf-bgfx">
        <div class="cf-bgfx-aurora"></div>
        <div class="cf-bgfx-wave cf-bgfx-wave--a"></div>
        <div class="cf-bgfx-wave cf-bgfx-wave--b"></div>
        <div class="cf-bgfx-glow cf-bgfx-glow--tl"></div>
        <div class="cf-bgfx-glow cf-bgfx-glow--tr"></div>
        <div class="cf-bgfx-glow cf-bgfx-glow--bl"></div>
        <div class="cf-bgfx-glow cf-bgfx-glow--br"></div>
        <div class="cf-bgfx-glow cf-bgfx-glow--mid"></div>
        <div class="cf-bgfx-grain"></div>
        <div class="cf-bgfx-vignette"></div>
    </div>
</div>
<div class="wrapper">
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <?php require $content; ?>
    <?php require __DIR__ . '/../partials/footer.php'; ?>
    <?php require __DIR__ . '/../partials/bottom-nav.php'; ?>
</div>

<script src="<?= e(asset('vendor/lazysizes.min.js')) ?>" async></script>
<script src="<?= e(asset('vendor/ls.bgset.min.js')) ?>" async></script>
<script src="<?= e(asset('vendor/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('vendor/tooltipster.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('vendor/swiper.min.js')) ?>" defer></script>
<link rel="stylesheet" href="<?= e(asset('css/continue-party.css')) ?>?v=20260803-ui143">
<script src="<?= e(asset('js/continue-party.js')) ?>?v=20260803-ui143" defer></script>
<script src="<?= e(asset('js/app.js')) ?>?v=20260803-ui143" defer></script>
<?php foreach ($extraJs as $js): ?>
    <script src="<?= e($js) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
