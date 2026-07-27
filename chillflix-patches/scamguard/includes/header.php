<?php
$siteName = get_setting('site_name', 'ScamGuard');
$announcementEnabled = get_setting('announcement_enabled', '0') === '1';
$announcement = get_setting('announcement_banner', '');
$assetVer = '20260727card1';

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
            <a href="<?= BASE_PATH ?>/?type=iban">IBAN</a>
            <a href="<?= BASE_PATH ?>/browse.php">Browse</a>
            <a href="<?= BASE_PATH ?>/report.php">Report</a>
            <a href="<?= BASE_PATH ?>/report.php" class="nav-cta">Report Now</a>
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
            <a href="<?= BASE_PATH ?>/report.php">Report</a>
            <a href="<?= BASE_PATH ?>/report.php" class="nav-cta">Report Now</a>
        </div>
    </div>
</nav>

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
</script>

<main class="page">
