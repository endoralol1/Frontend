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
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', c[0]);
      } catch (e) {}
    })();
    </script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= e($seo['title'] ?? (config('site_name') . ' Player')) ?></title>
    <meta name="robots" content="<?= e($seo['robots'] ?? 'noindex, follow') ?>">
    <meta name="theme-color" content="#07080c">
    <link rel="icon" href="<?= e(logo_url()) ?>" type="image/webp">
    <link rel="stylesheet" href="<?= e(asset('css/player.css')) ?>?v=20260811-restore14">
    <script>
        window.APP = {
            baseUrl: <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>,
            siteName: <?= json_encode((string) config('site_name'), JSON_UNESCAPED_UNICODE) ?>,
            mainOrigin: <?= json_encode((string) config('player_main_api', config('main_origin', 'https://www.chillflix.lol')), JSON_UNESCAPED_SLASHES) ?>
        };
        window.PLAYER = <?= json_encode([
            'type' => $type,
            'id' => (int) $item['id'],
            'title' => $title,
            'year' => $year ?? '',
            'poster' => $poster ?? '',
            'backdrop' => $backdrop ?? '',
            'backUrl' => $backUrl ?? url('/home'),
            'season' => (int) ($season ?? 1),
            'episode' => (int) ($episode ?? 1),
            'next' => $nextEpisode ?? null,
            'sourcesApi' => url('/api/player/sources'),
            'providersApi' => url('/api/player/providers'),
            'skipSegmentsApi' => url('/api/player/skip-segments'),
            'providers' => (class_exists('PlayerSources') ? (PlayerSources::listProviders()['providers'] ?? []) : []),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
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
<body class="page-player">
<?php require $content; ?>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.6.16/dist/hls.min.js" defer></script>
<script src="<?= e(asset('js/player.js')) ?>?v=20260815-cw-resume1" defer></script>
</body>
</html>
