            return fallback != null ? fallback : key;
        };
    </script>
    <?php
    // AdCash anti-adblock lib high in <head> (provides window.aclib).
    // Staff never get ads; guests + users only. Pop may also respect user Settings.
    $cfAdsHead = class_exists('SiteAds') ? SiteAds::get() : null;
    $cfAdsHeadPath = function_exists('current_path') ? current_path() : '/';
    $cfAdsHeadMaster = is_array($cfAdsHead) && SiteAds::shouldRenderOnPage($bodyClass ?? null, $cfAdsHeadPath);
    $cfAdsHeadBanner = $cfAdsHeadMaster && is_array($cfAdsHead) && !empty($cfAdsHead['banner']);
    $cfAdsHeadInterstitial = $cfAdsHeadMaster && is_array($cfAdsHead) && !empty($cfAdsHead['interstitial']);
    $cfAdsHeadVideo = $cfAdsHeadMaster && is_array($cfAdsHead) && !empty($cfAdsHead['videoSlider']);
    $cfAdsHeadPop = is_array($cfAdsHead) && SiteAds::popShouldRender($bodyClass ?? null, $cfAdsHeadPath);
    $cfAdsHeadNeed = $cfAdsHeadBanner || $cfAdsHeadInterstitial || $cfAdsHeadVideo || $cfAdsHeadPop;
    $cfAdsLib = is_array($cfAdsHead) ? (string) ($cfAdsHead['antiAdblockSrc'] ?? '/assets/js/rlqkn118.js') : '/assets/js/rlqkn118.js';
    // AdCash docs: inject library SOURCE high in <head> (not only external src).
    $cfAdsLibInline = ($cfAdsHeadNeed && is_array($cfAdsHead) && class_exists('SiteAds'))
        ? SiteAds::antiAdblockInlineJs($cfAdsHead)
        : '';
    if ($cfAdsHeadNeed):
    ?>
    <script>
    window.CF_ADS = <?= json_encode([
        'banner' => (bool) $cfAdsHeadBanner,
        'interstitial' => (bool) $cfAdsHeadInterstitial,
        'videoSlider' => (bool) $cfAdsHeadVideo,
        'pop' => (bool) $cfAdsHeadPop,
        'popBypassUserOptOut' => is_array($cfAdsHead) && !empty($cfAdsHead['popBypassUserOptOut']),
        'bannerZone' => is_array($cfAdsHead) ? (string) ($cfAdsHead['bannerZone'] ?? '') : '',
        'popZone' => is_array($cfAdsHead) ? (string) ($cfAdsHead['popZone'] ?? '') : '',
    ], JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <?php if ($cfAdsLibInline !== ''): ?>
    <script type="text/javascript"><?= $cfAdsLibInline ?></script>
    <?php else: ?>
    <script type="text/javascript" src="<?= e($cfAdsLib) ?>"></script>
    <?php endif; ?>
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
        <div class="cf-bgfx-stars" aria-hidden="true">
            <canvas class="cf-bgfx-stars-canvas" id="cf-sky-stars"></canvas>
        </div>
        <div class="cf-bgfx-moon" aria-hidden="true"></div>
        <div class="cf-bgfx-grain"></div>
        <div class="cf-bgfx-vignette"></div>
