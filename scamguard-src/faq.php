<?php
require_once __DIR__ . '/includes/functions.php';
$siteName = get_setting('site_name', 'ScamGuard');
$pageTitle = 'FAQ — ' . $siteName;
require __DIR__ . '/includes/header.php';

$faqs = [
    ['Is a low trust score proof that a site is a scam?', 'No. It means there are enough warning signals to warrant caution and further investigation. New, legitimate businesses can also score lower simply due to lack of history.'],
    ['How often is a domain re-checked?', 'Cached results are automatically refreshed on a schedule set by the site administrator (by default, every 72 hours), and instantly whenever a manual override is applied.'],
    ['Can I report a site I think is a scam?', 'Yes — use the "Report a Scam" button on any result page, or the Report page in the navigation.'],
    ['Does ' . $siteName . ' visit every site with a real browser?', 'No. All checks use lightweight, direct protocol lookups (WHOIS/RDAP, TLS handshake, DNS, and a plain HTTP fetch of the homepage) — no headless browser rendering is used.'],
    ['How are new domains discovered automatically?', 'Beyond user searches, the system watches Certificate Transparency logs and public threat feeds for newly registered or newly reported domains, and checks them proactively.'],
];
?>
<section class="section container" style="max-width:760px;">
    <h2 class="section-title">Frequently Asked Questions</h2>
    <?php foreach ($faqs as [$q, $a]): ?>
        <div class="card" style="margin-bottom:14px;">
            <h3 style="margin-top:0; font-size:16px;"><?= h($q) ?></h3>
            <p style="color:var(--text-muted); margin-bottom:0; line-height:1.6;"><?= h($a) ?></p>
        </div>
    <?php endforeach; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
