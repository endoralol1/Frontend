<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'How It Works — ' . get_setting('site_name', 'ScamGuard');
require __DIR__ . '/includes/header.php';
?>
<section class="section container" style="max-width:760px;">
    <h2 class="section-title">How the trust score works</h2>

    <div class="card" style="margin-bottom:16px;">
        <h3 style="margin-top:0;">1. Registration data</h3>
        <p style="color:var(--text-muted); line-height:1.6;">We look up how old a domain is and how long it's registered for. Scam sites are usually registered days or weeks ago, often for just one year, since they expect to be taken down or abandoned quickly. Long-established domains registered years in advance are a positive signal.</p>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <h3 style="margin-top:0;">2. SSL certificate</h3>
        <p style="color:var(--text-muted); line-height:1.6;">We check whether the site has a valid, unexpired SSL certificate and who issued it. Missing or invalid SSL is a red flag, though a valid certificate alone doesn't guarantee legitimacy &mdash; scammers can get free SSL certs too, which is why this is only one signal among several.</p>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <h3 style="margin-top:0;">3. Hosting &amp; network</h3>
        <p style="color:var(--text-muted); line-height:1.6;">We identify the server's IP address, hosting provider, and country. Certain hosting providers and regions are disproportionately used by scam operations, which factors into the score.</p>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <h3 style="margin-top:0;">4. Page content</h3>
        <p style="color:var(--text-muted); line-height:1.6;">We check whether the site publishes real contact information and a privacy policy, and scan for common high-pressure scam language ("verify your account now," "act immediately," etc.).</p>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <h3 style="margin-top:0;">5. Threat feeds</h3>
        <p style="color:var(--text-muted); line-height:1.6;">We cross-check every domain against public phishing and malware threat feeds. A confirmed hit on one of these feeds is the strongest single signal we use.</p>
    </div>

    <div class="alert alert-info">
        The trust score is a risk indicator, not a definitive verdict. A low score means there are enough warning signs to investigate further before trusting a site &mdash; not that it's guaranteed to be a scam. Always cross-check with your own judgment.
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
