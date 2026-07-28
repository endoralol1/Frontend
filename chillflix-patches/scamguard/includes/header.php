<?php
require_once __DIR__ . '/UserAuth.php';
UserAuth::start();
$navUser = UserAuth::check() ? UserAuth::username() : null;

$siteName = get_setting('site_name', 'ScamGuard');
$announcementEnabled = get_setting('announcement_enabled', '0') === '1';
$announcement = get_setting('announcement_banner', '');
$assetVer = '20260728chat5';

$pageTitle = $pageTitle ?? $siteName;
$pageDescription = $pageDescription ?? 'Check websites for scam, phishing, and malware risk signals before you click.';
$canonicalUrl = $canonicalUrl ?? absolute_url('/');
$robotsMeta = $robotsMeta ?? 'index,follow';
$ogType = $ogType ?? 'website';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($pageTitle) ?></title>
<meta name="description" content="<?= h($pageDescription) ?>">
<meta name="robots" content="<?= h($robotsMeta) ?>">
<link rel="canonical" href="<?= h($canonicalUrl) ?>">
<meta property="og:type" content="<?= h($ogType) ?>">
<meta property="og:site_name" content="<?= h($siteName) ?>">
<meta property="og:title" content="<?= h($pageTitle) ?>">
<meta property="og:description" content="<?= h($pageDescription) ?>">
<meta property="og:url" content="<?= h($canonicalUrl) ?>">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= h($pageTitle) ?>">
<meta name="twitter:description" content="<?= h($pageDescription) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css?v=<?= h($assetVer) ?>">
<?php if (!empty($jsonLd)): ?>
<script type="application/ld+json"><?= $jsonLd ?></script>
<?php endif; ?>
</head>
<body>

<?php if ($announcementEnabled && $announcement): ?>
<div class="announce"><?= h($announcement) ?></div>
<?php endif; ?>

<nav class="nav" id="site-nav">
    <div class="nav-inner">
        <a href="<?= BASE_PATH ?>/" class="brand">
            <span class="brand-mark">🛡️</span>
            <span class="brand-text"><?= h($siteName) ?></span>
        </a>

        <div class="nav-links">
            <a href="<?= BASE_PATH ?>/">Quick check</a>
            <a href="<?= BASE_PATH ?>/?type=phone">Phone</a>
            <a href="<?= BASE_PATH ?>/?type=crypto">Crypto</a>
            <a href="<?= BASE_PATH ?>/browse.php">Browse</a>
            <a href="<?= BASE_PATH ?>/community.php">Community</a>
            <?php if ($navUser): ?>
                <a href="<?= BASE_PATH ?>/profile.php?u=<?= rawurlencode($navUser) ?>" class="nav-user" title="My profile">👤 <?= h($navUser) ?></a>
                <a href="<?= BASE_PATH ?>/logout.php" class="nav-signout">Sign out</a>
            <?php else: ?>
                <a href="<?= BASE_PATH ?>/login.php">Sign in</a>
            <?php endif; ?>
            <a href="<?= BASE_PATH ?>/community.php?compose=1" class="nav-cta">Report Now</a>
        </div>

        <button class="nav-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="nav-dropdown">
            <span></span><span></span><span></span>
        </button>
    </div>

    <div id="nav-dropdown" class="nav-dropdown" aria-hidden="true">
        <div class="nav-dropdown-inner">
            <a href="<?= BASE_PATH ?>/">Quick check</a>
            <a href="<?= BASE_PATH ?>/?type=phone">Phone</a>
            <a href="<?= BASE_PATH ?>/?type=crypto">Crypto</a>
            <a href="<?= BASE_PATH ?>/?type=iban">IBAN</a>
            <a href="<?= BASE_PATH ?>/browse.php">Browse</a>
            <a href="<?= BASE_PATH ?>/community.php">Community</a>
            <?php if ($navUser): ?>
                <a href="<?= BASE_PATH ?>/profile.php?u=<?= rawurlencode($navUser) ?>">👤 <?= h($navUser) ?> — my profile</a>
                <a href="<?= BASE_PATH ?>/logout.php">Sign out</a>
            <?php else: ?>
                <a href="<?= BASE_PATH ?>/login.php">Sign in</a>
                <a href="<?= BASE_PATH ?>/register.php">Create account</a>
            <?php endif; ?>
            <a href="<?= BASE_PATH ?>/community.php?compose=1" class="nav-cta">Report Now</a>
        </div>
    </div>
</nav>

<div class="scan-loading" id="scan-loading" aria-hidden="true" role="status" aria-live="polite">
    <div class="scan-loading-card">
        <div class="scan-loading-ring" aria-hidden="true"></div>
        <div class="scan-loading-copy">
            <strong>Rescanning site</strong>
            <span id="scan-loading-current">Preparing a fresh scan…</span>
        </div>
        <div class="scan-loading-steps" aria-hidden="true" id="scan-loading-steps">
            <span data-step="0">DNS</span>
            <span data-step="1">SSL</span>
            <span data-step="2">Page</span>
            <span data-step="3">Feeds</span>
            <span data-step="4">Reviews</span>
            <span data-step="5">AI</span>
        </div>
    </div>
</div>

<script>
(() => {
  const nav = document.getElementById('site-nav');
  const btn = nav && nav.querySelector('.nav-toggle');
  const dropdown = document.getElementById('nav-dropdown');
  if (!btn || !dropdown) return;

  const setOpen = (open) => {
    nav.classList.toggle('is-open', open);
    dropdown.classList.toggle('is-open', open);
    btn.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    dropdown.setAttribute('aria-hidden', open ? 'false' : 'true');
    document.body.classList.toggle('nav-open', open);
  };

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    setOpen(!nav.classList.contains('is-open'));
  });

  dropdown.querySelectorAll('a').forEach((a) => {
    a.addEventListener('click', () => setOpen(false));
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') setOpen(false);
  });
})();

(() => {
  const overlay = document.getElementById('scan-loading');
  if (!overlay) return;

  const showLoading = () => {
    overlay.classList.add('is-visible');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('scan-loading-open');
    startScanProgress();
  };

  const startScanProgress = () => {
    const current = document.getElementById('scan-loading-current');
    const stepEls = Array.from(document.querySelectorAll('#scan-loading-steps [data-step]'));
    if (!current || stepEls.length === 0 || overlay.dataset.progressStarted === '1') return;
    overlay.dataset.progressStarted = '1';

    const stages = [
      'Checking DNS, WHOIS, and domain age…',
      'Verifying SSL and hosting details…',
      'Fetching page content and security headers…',
      'Checking malware, phishing, and abuse feeds…',
      'Looking up reviews and public reputation…',
      'Running AI purpose and risk review…',
      'Calculating final trust score…'
    ];
    let index = 0;
    const paint = () => {
      current.textContent = stages[index] || stages[stages.length - 1];
      stepEls.forEach((el) => {
        const step = parseInt(el.getAttribute('data-step'), 10);
        el.classList.toggle('is-active', step === Math.min(index, stepEls.length - 1));
        el.classList.toggle('is-done', step < Math.min(index, stepEls.length));
      });
      if (index < stages.length - 1) index++;
    };
    paint();
    window.setInterval(paint, 1250);
  };

  // Any navigation that can trigger a scan (a report page or an explicit rescan)
  // shows the staged loader so slow first-time / full scans never feel frozen.
  const isReportUrl = (url) => {
    if (url.origin !== window.location.origin) return false;
    if (url.searchParams.get('refresh') === '1') return true;
    const p = url.pathname;
    return p.includes('/site/')
      || p.endsWith('/check.php')
      || p.endsWith('/check-entity.php');
  };

  document.addEventListener('click', (event) => {
    const link = event.target.closest && event.target.closest('a[href]');
    if (!link) return;
    if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
    if (link.target && link.target !== '_self') return;

    const href = link.getAttribute('href') || '';
    if (href.startsWith('#')) return;
    let url;
    try {
      url = new URL(href, window.location.href);
    } catch (e) {
      return;
    }
    if (!isReportUrl(url)) return;

    event.preventDefault();
    showLoading();
    window.setTimeout(() => {
      window.location.href = url.toString();
    }, 90);
  });

  // Show the loader when a search/check form is submitted too.
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const action = (form.getAttribute('action') || '').toLowerCase();
    const isCheck = form.id === 'scam-search'
      || action.includes('check.php')
      || action.includes('check-entity.php')
      || form.hasAttribute('data-scan-form');
    if (!isCheck) return;
    showLoading();
  });
})();
</script>

<main class="page">
