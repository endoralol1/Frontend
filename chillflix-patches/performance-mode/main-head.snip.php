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
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>?v=20260813-perf01" fetchpriority="high">
    <link rel="stylesheet" href="<?= e(asset('css/views.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/continue-watching.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/site-notice.css')) ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@iconscout/unicons@4.0.8/css/line.min.css">
    <link rel="stylesheet" href="<?= e(asset('vendor/tooltipster.bundle.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/swiper.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/episode-carousel.css')) ?>?v=20260813-perf01">
    <link rel="stylesheet" href="<?= e(asset('css/app-docksearch-1232.css')) ?>?v=20260813-perf01">
<link rel="stylesheet" href="<?= e(asset('css/card-hover-preview.css')) ?>?v=20260813-perf01">
<link rel="stylesheet" href="<?= e(asset('css/perf-mode.css')) ?>?v=20260813-perf01">
    <?php foreach ($extraCss as $css): ?>
        <link rel="stylesheet" href="<?= e($css) ?>">
