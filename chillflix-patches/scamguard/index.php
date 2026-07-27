<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/DomainRepository.php';

$repo = new DomainRepository();
$stats = $repo->stats();
$recent = $repo->recentlyCheckedMixed(12);

$pageTitle = get_setting('site_name', 'ScamGuard') . ' — Check if a website is safe before you click';
$pageDescription = 'Free website scam checker. Scan any domain for phishing, malware lists, registration age, SSL, and scam heuristics before you enter it.';
$canonicalUrl = absolute_url('/');
$jsonLd = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => get_setting('site_name', 'ScamGuard'),
    'url' => absolute_url('/'),
    'description' => $pageDescription,
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => absolute_url('site/{domain}'),
        'query-input' => 'required name=domain',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

require __DIR__ . '/includes/header.php';
?>

<section class="hero">
    <div class="container">
        <h1>Is that website safe, or a scam?</h1>
        <p>Paste a domain to scan malware/phishing intel, scam heuristics, registration data, SSL, and hosting signals.</p>

        <form class="search-box" action="<?= BASE_PATH ?>/check.php" method="get">
            <input type="text" name="d" placeholder="e.g. example.com" autofocus required>
            <button type="submit">Check Site</button>
        </form>

        <div class="stats-row">
            <div class="stat">
                <div class="num"><?= number_format($stats['total_domains']) ?></div>
                <div class="label">Domains tracked</div>
            </div>
            <div class="stat">
                <div class="num"><?= number_format($stats['likely_safe']) ?></div>
                <div class="label">Likely safe</div>
            </div>
            <div class="stat">
                <div class="num"><?= number_format($stats['flagged_scams']) ?></div>
                <div class="label">Flagged as scams</div>
            </div>
            <div class="stat">
                <div class="num"><?= number_format($stats['checked_today']) ?></div>
                <div class="label">Checked today</div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div style="display:flex; justify-content:space-between; align-items:end; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
            <h2 class="section-title" style="margin:0;">Recently checked</h2>
            <a class="btn btn-sm" href="<?= BASE_PATH ?>/browse.php">Browse all checks</a>
        </div>
        <p style="color:var(--text-faint); margin:0 0 14px; font-size:14px;">
            Shows a mix of safe and risky results — not only threat-feed hits.
        </p>
        <div class="card" style="padding:0;">
            <div class="table-wrap"><table>
                <thead>
                    <tr><th>Domain</th><th>Score</th><th>Status</th><th>Last checked</th></tr>
                </thead>
                <tbody>
                <?php if (empty($recent)): ?>
                    <tr><td colspan="4" style="color:var(--text-faint);">No domains checked yet — be the first to search one above.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $r): $badge = status_badge($r['status']); ?>
                    <tr>
                        <td><a href="<?= h(domain_page_path($r['domain'])) ?>"><?= h($r['domain']) ?></a></td>
                        <td><?= (int) $r['trust_score'] ?>/100</td>
                        <td><span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span></td>
                        <td style="color:var(--text-faint);"><?= h($r['last_checked'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
