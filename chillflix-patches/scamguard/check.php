<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/DomainRepository.php';

$input = trim($_GET['d'] ?? '');
$domain = $input !== '' ? normalize_domain($input) : null;
$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';

if (!$domain) {
    $pageTitle = 'Invalid domain — ' . get_setting('site_name', 'ScamGuard');
    $pageDescription = 'That domain could not be checked. Try a full hostname like example.com.';
    $robotsMeta = 'noindex,follow';
    $canonicalUrl = absolute_url('check.php');
    require __DIR__ . '/includes/header.php';
    echo '<section class="section container"><div class="alert alert-error">That doesn\'t look like a valid domain. Try something like <code>example.com</code>.</div>
    <a href="' . h(BASE_PATH) . '/" class="btn">&larr; Back to search</a></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Canonical pretty URL for SEO (keep refresh on query form)
$prettyPath = domain_page_path($domain);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$isPretty = str_contains($requestPath, '/site/');
if (!$force && !$isPretty) {
    header('Location: ' . $prettyPath, true, 301);
    exit;
}

@set_time_limit(45);
$repo = new DomainRepository();
$record = $repo->getOrCheck($domain, 'search', $force);
$history = $repo->getHistory($record['id'], 20);
$badge = status_badge($record['status']);
$signals = json_decode((string) ($record['signals_json'] ?? ''), true);
if (!is_array($signals)) {
    $signals = [];
}

$groups = [
    'verdict' => 'Verdict',
    'reputation' => 'Reputation & traffic',
    'threat' => 'Malware, phishing & spam',
    'heuristics' => 'Scam heuristics',
    'registration' => 'Registration',
    'ssl' => 'SSL / TLS',
    'network' => 'Network & hosting',
    'email' => 'Email authentication',
    'content' => 'Website content',
    'security' => 'HTTP security',
];

$verdict = (string) ($record['verdict'] ?? 'unknown');
$verdictReasons = json_decode((string) ($record['verdict_reasons'] ?? '[]'), true) ?: [];
$verdictLabel = strtoupper(str_replace('_', ' ', $verdict));

$grouped = [];
foreach ($signals as $signal) {
    $g = $signal['group'] ?? 'other';
    $grouped[$g][] = $signal;
}

$positives = array_values(array_filter($signals, static fn($s) => ($s['tone'] ?? '') === 'good'));
$negatives = array_values(array_filter($signals, static fn($s) => in_array(($s['tone'] ?? ''), ['bad', 'warn'], true)));
$unchecked = array_values(array_filter($signals, static fn($s) => ($s['value'] ?? '') === 'Not checked'));

$score = (int) $record['trust_score'];
$statusLabel = $badge['label'];
$pageTitle = $domain . ' scam check — score ' . $score . '/100 (' . $statusLabel . ') | ' . get_setting('site_name', 'ScamGuard');
$pageDescription = "Is {$domain} safe or a scam? ScamGuard trust score {$score}/100 — {$statusLabel}. Includes phishing/malware feed hits, domain age, SSL, and hosting signals.";
$canonicalUrl = domain_page_url($domain);
$ogType = 'article';
$jsonLd = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => $pageTitle,
    'url' => $canonicalUrl,
    'description' => $pageDescription,
    'dateModified' => !empty($record['last_checked']) ? date('c', strtotime($record['last_checked'])) : date('c'),
    'about' => [
        '@type' => 'Thing',
        'name' => $domain,
        'url' => 'https://' . $domain,
    ],
    'isPartOf' => [
        '@type' => 'WebSite',
        'name' => get_setting('site_name', 'ScamGuard'),
        'url' => absolute_url('/'),
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

require __DIR__ . '/includes/header.php';

function tone_class(string $tone): string
{
    return match ($tone) {
        'good' => 'tone-good',
        'warn' => 'tone-warn',
        'bad' => 'tone-bad',
        default => 'tone-neutral',
    };
}
?>

<section class="section container result-page">

    <?php
    render_status_banner((string) $record['status'], (int) $record['trust_score'], (string) $record['domain'], [
        [
            'label' => 'VISIT',
            'href' => 'https://' . $record['domain'],
            'class' => 'btn btn-sm btn-primary',
            'external' => true,
        ],
        [
            'label' => 'REPORT',
            'href' => BASE_PATH . '/report.php?d=' . urlencode($record['domain']),
            'class' => 'btn btn-sm btn-danger',
        ],
        [
            'label' => '↻ Rescan',
            'href' => domain_page_path($record['domain']) . '?refresh=1',
            'class' => 'btn btn-sm',
        ],
    ]);
    ?>

    <div class="score-meta-line" style="margin:-6px 0 14px; color:var(--muted);">
        Last checked: <?= h($record['last_checked'] ?? 'just now') ?>
        &middot; <?= (int) $record['check_count'] ?> scan<?= $record['check_count'] == 1 ? '' : 's' ?>
        <?php if (!empty($record['uses_cdn'])): ?>
            &middot; Behind <?= h($record['cdn_provider'] ?: 'CDN') ?>
        <?php endif; ?>
        <?php if (!empty($record['page_title'])): ?>
            &middot; <?= h($record['page_title']) ?>
        <?php endif; ?>
    </div>

    <div class="verdict-card <?= h($verdict) ?>" style="margin-top:16px;">
        <div class="verdict-kicker">Automated verdict</div>
        <div class="verdict-title"><?= h($verdictLabel) ?></div>
        <?php if ($verdictReasons): ?>
            <ul class="verdict-reasons">
                <?php foreach ($verdictReasons as $reason): ?>
                    <li><?= h((string) $reason) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="verdict-pills">
            <span class="pill <?= !empty($record['malware_hit']) ? 'bad' : 'good' ?>">Malware lists: <?= !empty($record['malware_hit']) ? 'HIT' : 'Clean' ?></span>
            <span class="pill <?= !empty($record['phishing_hit']) ? 'bad' : 'good' ?>">Phishing lists: <?= !empty($record['phishing_hit']) ? 'HIT' : 'Clean' ?></span>
            <span class="pill">Score <?= (int) $record['trust_score'] ?>/100</span>
        </div>
    </div>

    <?php if (!empty($record['uses_cdn'])): ?>
        <div class="alert alert-info" style="margin-top:16px;">
            The public A-record IP (<code><?= h($record['ip_address'] ?? '') ?></code>) belongs to
            <strong><?= h($record['cdn_provider'] ?: 'a CDN') ?></strong>.
            That is normal for many legit sites and does <em>not</em> mean the origin server is in that location.
        </div>
    <?php endif; ?>

    <?php if ($record['manual_override']): ?>
        <div class="alert alert-info" style="margin-top:16px;">
            This domain's status has been manually reviewed by an administrator.
            <?php if (!empty($record['admin_notes'])): ?><br><?= h($record['admin_notes']) ?><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($record['threat_feed_hit']): ?>
        <div class="alert alert-error" style="margin-top:16px;">
            ⚠ Listed on threat/phishing feeds: <?= h($record['threat_feed_sources']) ?>
        </div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="card summary-card">
            <div class="summary-label">Registrar</div>
            <div class="summary-value"><?= h($record['whois_registrar'] ?? 'Unknown') ?></div>
        </div>
        <div class="card summary-card">
            <div class="summary-label">Domain age</div>
            <div class="summary-value"><?= $record['domain_age_days'] !== null ? number_format((int) $record['domain_age_days']) . ' days' : 'Unknown' ?></div>
        </div>
        <div class="card summary-card">
            <div class="summary-label">SSL</div>
            <div class="summary-value"><?= $record['ssl_valid'] ? 'Valid' : 'Missing/invalid' ?></div>
        </div>
        <div class="card summary-card">
            <div class="summary-label">Threat feeds</div>
            <div class="summary-value"><?= $record['threat_feed_hit'] ? 'Hit' : 'Clean' ?></div>
        </div>
    </div>

    <div class="highlights-grid">
        <section class="highlight-panel highlight-positive">
            <h3>Positive highlights</h3>
            <?php if (!$positives): ?>
                <p class="highlight-empty">No strong positive signals recorded.</p>
            <?php else: ?>
                <ul class="highlight-list">
                    <?php foreach (array_slice($positives, 0, 8) as $p): ?>
                        <li class="highlight-item">
                            <span class="highlight-icon" aria-hidden="true">✓</span>
                            <div class="highlight-body">
                                <div class="highlight-title"><?= h((string) $p['label']) ?></div>
                                <div class="highlight-text"><?= h((string) $p['value']) ?><?php if (!empty($p['note'])): ?> — <?= h((string) $p['note']) ?><?php endif; ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="highlight-panel highlight-negative">
            <h3>Negative highlights</h3>
            <?php if (!$negatives): ?>
                <p class="highlight-empty">No elevated risk signals recorded.</p>
            <?php else: ?>
                <ul class="highlight-list">
                    <?php foreach (array_slice($negatives, 0, 8) as $n): ?>
                        <li class="highlight-item">
                            <span class="highlight-icon" aria-hidden="true">✕</span>
                            <div class="highlight-body">
                                <div class="highlight-title"><?= h((string) $n['label']) ?></div>
                                <div class="highlight-text"><?= h((string) $n['value']) ?><?php if (!empty($n['note'])): ?> — <?= h((string) $n['note']) ?><?php endif; ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($unchecked): ?>
    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Not checked (vs ScamAdviser)</h3>
        <p style="color:var(--text-faint); font-size:14px; margin-top:0;">These partner/paid sources are shown so the gap is explicit.</p>
        <ul class="verdict-reasons">
            <?php foreach ($unchecked as $u): ?>
                <li><strong><?= h((string) $u['label']) ?>:</strong> <?= h((string) ($u['note'] ?: 'Not available')) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($signals): ?>
        <div class="signal-groups">
            <?php foreach ($groups as $key => $title): ?>
                <?php if (empty($grouped[$key])) continue; ?>
                <div class="card signal-group">
                    <h3><?= h($title) ?></h3>
                    <div class="signal-list">
                        <?php foreach ($grouped[$key] as $signal): ?>
                            <div class="signal-row <?= tone_class((string) ($signal['tone'] ?? 'neutral')) ?>">
                                <div class="signal-main">
                                    <span class="label"><?= h((string) ($signal['label'] ?? '')) ?></span>
                                    <?php if (!empty($signal['note'])): ?>
                                        <span class="signal-note"><?= h((string) $signal['note']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="value"><?= h(is_scalar($signal['value'] ?? null) ? (string) $signal['value'] : json_encode($signal['value'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (count($history) > 1): ?>
    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Score history</h3>
        <div class="history-bars">
            <?php foreach ($history as $hrow): ?>
                <div title="<?= h($hrow['checked_at']) ?>: <?= (int) $hrow['trust_score'] ?>"
                     style="height:<?= max(8, (int) $hrow['trust_score']) ?>%;"></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <p style="margin-top:20px; color:var(--text-faint); font-size:13px;">
        Permanent link:
        <a href="<?= h(domain_page_path($record['domain'])) ?>"><?= h(domain_page_url($record['domain'])) ?></a>
        · <a href="<?= BASE_PATH ?>/browse.php">Browse more checks</a>
    </p>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
