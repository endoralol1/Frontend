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
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-GK376SVTPY"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', 'G-GK376SVTPY');
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover, interactive-widget=resizes-content">
    <title><?= e($docTitle) ?></title>
    <meta name="description" content="<?= e($desc) ?>">
    <meta name="keywords" content="<?= e($keywords) ?>">
    <meta name="robots" content="<?= e($robots) ?>">
    <meta name="theme-color" content="<?= e((string) config('theme_color')) ?>">
    <script>
    /* Accent theme boot — before paint to avoid orange flash */
    (function () {
      try {
        var map = {
          ember: ['#db6937', '#c43c2e', '219, 105, 55', '196, 60, 46', '0deg', '1'],
          crimson: ['#e11d48', '#be123c', '225, 29, 72', '190, 18, 60', '-28deg', '1.05'],
          violet: ['#8b5cf6', '#6d28d9', '139, 92, 246', '109, 40, 217', '235deg', '1.08'],
          azure: ['#3b82f6', '#1d4ed8', '59, 130, 246', '29, 78, 216', '198deg', '1.05'],
          teal: ['#14b8a6', '#0f766e', '20, 184, 166', '15, 118, 110', '152deg', '1.1'],
          lime: ['#84cc16', '#4d7c0f', '132, 204, 22', '77, 124, 15', '70deg', '1.15'],
          rose: ['#ec4899', '#be185d', '236, 72, 153', '190, 24, 93', '310deg', '1.05'],
          gold: ['#d4a017', '#a16207', '212, 160, 23', '161, 98, 7', '28deg', '1.08'],
          silver: ['#b8c0cc', '#7a8494', '184, 192, 204', '122, 132, 148', '210deg', '0.2']
        };
        var id = localStorage.getItem('cf_accent') || 'teal';
        var c = map[id] || map.teal;
        var r = document.documentElement;
        r.style.setProperty('--cf-orange', c[0]);
        r.style.setProperty('--cf-orange-deep', c[1]);
        r.style.setProperty('--cf-orange-rgb', c[2]);
        r.style.setProperty('--cf-orange-deep-rgb', c[3]);
        r.style.setProperty('--cf-logo-hue', c[4] || '0deg');
        r.style.setProperty('--cf-logo-sat', c[5] || '1');
        // Dark accent-tinted hero/page fade (replaces fixed ember-brown)
        (function () {
          var hex = String(c[0] || '').replace('#', '');
          if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
          var n = parseInt(hex, 16);
          var ar = (n >> 16) & 255, ag = (n >> 8) & 255, ab = n & 255;
          var br = 16, bg = 19, bb = 26;
          var rr = Math.round(ar * 0.12 + br * 0.88);
          var gg = Math.round(ag * 0.12 + bg * 0.88);
          var bb2 = Math.round(ab * 0.12 + bb * 0.88);
          function h(x){ return x.toString(16).padStart(2,'0'); }
          r.style.setProperty('--cf-hero-fade', '#' + h(rr) + h(gg) + h(bb2));
          r.style.setProperty('--cf-hero-fade-rgb', rr + ', ' + gg + ', ' + bb2);
          var sr = Math.round(ar * 0.42 + 255 * 0.58);
          var sg = Math.round(ag * 0.42 + 255 * 0.58);
          var sb = Math.round(ab * 0.42 + 255 * 0.58);
          r.style.setProperty('--cf-orange-soft', '#' + h(sr) + h(sg) + h(sb));
          r.style.setProperty('--cf-orange-soft-rgb', sr + ', ' + sg + ', ' + sb);
        })();
        r.setAttribute('data-accent', id in map ? id : 'teal');

        try { localStorage.removeItem('cf_oled'); document.documentElement.removeAttribute('data-oled'); } catch (eO) {}
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', c[0]);
      } catch (e) {}
    })();
    </script>
    <meta name="monetag" content="7b8e4d838889a09220772438b25fb29d">
    <!-- AdCash -->
    <script id="aclib" type="text/javascript" src="https://acscdn.com/script/aclib.js"></script>
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

    <script>
    (function () {
      try {
        var v = null;
        try { v = localStorage.getItem('cf_pref_performance'); } catch (e0) {}
        if (v == null || v === '') {
          v = /(?:^|;\s*)cf_pref_performance=1(?:;|$)/.test(document.cookie || '') ? '1' : '0';
        }
        if (v === '1') document.documentElement.classList.add('cf-perf-mode');
      } catch (e1) {}
    })();
    </script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&family=Syne:wght@600;700;800&display=swap">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>?v=20260813-teal-default1" fetchpriority="high">
    <link rel="stylesheet" href="<?= e(asset('css/views.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/continue-watching.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/site-notice.css')) ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@iconscout/unicons@4.0.8/css/line.min.css">
    <link rel="stylesheet" href="<?= e(asset('vendor/tooltipster.bundle.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/swiper.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/episode-carousel.css')) ?>?v=20260813-teal-default1">
    <link rel="stylesheet" href="<?= e(asset('css/app-docksearch-1232.css')) ?>?v=20260814-overflow-fix1">
<link rel="stylesheet" href="<?= e(asset('css/card-hover-preview.css')) ?>?v=20260813-touch-expand2">
<link rel="stylesheet" href="<?= e(asset('css/perf-mode.css')) ?>?v=20260813-teal-default1">
<link rel="stylesheet" href="<?= e(asset('css/card-poster-lifecycle.css')) ?>?v=20260813-teal-default1">
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
            imgBase: <?= json_encode(rtrim((string) config('tmdb_img'), '/'), JSON_UNESCAPED_SLASHES) ?>,
            partyApi: <?= json_encode(url('/api/party'), JSON_UNESCAPED_SLASHES) ?>,
            user: <?= json_encode(Auth::user(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
        };
        /* Calmer lazyload: fewer concurrent image downloads while scrolling */
        window.lazySizesConfig = window.lazySizesConfig || {};
        window.lazySizesConfig.expand = 100;
        window.lazySizesConfig.expFactor = 1.1;
        window.lazySizesConfig.loadMode = 1;
        window.lazySizesConfig.hFac = 0.2;
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
    
    <script>
        window.CF_I18N_PACKS = null;
        window.CF_I18N_PACKS_URL = <?= json_encode(asset('data/i18n-packs.json') . '?v=1228', JSON_UNESCAPED_SLASHES) ?>;
        window.__cfLoadI18nPacks = function () {
            if (window.CF_I18N_PACKS) return Promise.resolve(window.CF_I18N_PACKS);
            if (window.__cfI18nPacksPromise) return window.__cfI18nPacksPromise;
            window.__cfI18nPacksPromise = fetch(window.CF_I18N_PACKS_URL, { credentials: 'same-origin', cache: 'no-cache' })
                .then(function (r) { return r.json(); })
                .then(function (packs) { window.CF_I18N_PACKS = packs || {}; return window.CF_I18N_PACKS; })
                .catch(function () { window.CF_I18N_PACKS = {}; return window.CF_I18N_PACKS; });
            return window.__cfI18nPacksPromise;
        };
        try { window.__cfLoadI18nPacks(); } catch (e0) {}
        window.__cfApplyI18nDom = function () {
            try {
                document.querySelectorAll('[data-i18n]').forEach(function (el) {
                    var key = el.getAttribute('data-i18n');
                    if (!key || !window.t) return;
                    var val = t(key);
                    if (!val || val === key) return;
                    var counter = el.querySelector('.favorites-counter');
                    if (counter) {
                        var texts = [];
                        el.childNodes.forEach(function (n) { if (n.nodeType === 3) texts.push(n); });
                        if (texts.length) texts[0].textContent = val + ' ';
                        else el.insertBefore(document.createTextNode(val + ' '), counter);
                    } else if (!el.children.length) {
                        el.textContent = val;
                    } else {
                        var target = el.querySelector(':scope > strong, :scope > em, :scope > span');
                        if (target && !target.children.length) target.textContent = val;
                    }
                });
            } catch (e1) {}
        };
    </script>
<script>
        window.CF_LANG = <?= json_encode(class_exists('Locale') ? Locale::current() : (string) config('lang', 'en'), JSON_UNESCAPED_UNICODE) ?>;
        window.CF_I18N = <?= json_encode(class_exists('Locale') ? Locale::jsDictionary() : new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.t = function (key, fallback) {
            try {
                if (window.CF_I18N && window.CF_I18N[key]) return window.CF_I18N[key];
            } catch (e) {}
            return fallback != null ? fallback : key;
        };
    </script>
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
        <div class="cf-bgfx-stars" aria-hidden="true">
            <canvas class="cf-bgfx-stars-canvas" id="cf-sky-stars"></canvas>
        </div>
        <div class="cf-bgfx-moon" aria-hidden="true"></div>
        <div class="cf-bgfx-grain"></div>
        <div class="cf-bgfx-vignette"></div>
    </div>
</div>
<div class="wrapper">
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <?php require $content; ?>
    <div class="container cf-adcash-banner" id="cf-adcash-banner" style="margin:1rem auto 1.25rem;min-height:2rem;text-align:center;overflow:hidden;">
        <script type="text/javascript">
            aclib.runBanner({
                zoneId: '11970470',
            });
        </script>
    </div>
    <?php require __DIR__ . '/../partials/footer.php'; ?>
    <?php require __DIR__ . '/../partials/bottom-nav.php'; ?>
</div>

<script src="<?= e(asset('vendor/lazysizes.min.js')) ?>" async></script>
<script src="<?= e(asset('vendor/ls.bgset.min.js')) ?>" async></script>
<script src="<?= e(asset('vendor/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('vendor/tooltipster.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('vendor/swiper.min.js')) ?>" defer></script>
<link rel="stylesheet" href="<?= e(asset('css/continue-party.css')) ?>?v=1232">
<link rel="stylesheet" href="<?= e(asset('css/home-personalize.css')) ?>?v=20260811-restore14">
<script src="<?= e(asset('js/continue-party.js')) ?>?v=1232" defer></script>
<link rel="stylesheet" href="<?= e(asset('css/inbox.css')) ?>?v=20260814-inbox6">
<script src="<?= e(asset('js/inbox.js')) ?>?v=20260814-inbox6" defer></script>
<script src="<?= e(asset('js/home-personalize.js')) ?>?v=20260813-teal-default1" defer></script>
<link rel="stylesheet" href="<?= e(asset('css/person-popup.css')) ?>?v=20260813-teal-default1">
<script src="<?= e(asset('js/person-popup.js')) ?>?v=20260813-teal-default1" defer></script>
<script src="<?= e(asset('js/card-hover-preview.js')) ?>?v=20260813-touch-expand2" defer></script>
<script src="<?= e(asset('js/card-poster-lifecycle.js')) ?>?v=20260813-teal-default1" defer></script>
<script src="<?= e(asset('js/app.docksearch-1228.js')) ?>?v=20260813-teal-default1" defer></script>
<?php foreach ($extraJs as $js): ?>
    <script src="<?= e($js) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
