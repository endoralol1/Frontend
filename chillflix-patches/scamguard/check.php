<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/DomainRepository.php';
require_once __DIR__ . '/includes/UserAuth.php';

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
    'analysis' => 'Analyst brief',
    'ai' => 'AI opinion',
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
$siteName = get_setting('site_name', 'ScamGuard');
// Lead with the exact domain so brand queries like "chillflix.lol" can match this report.
$pageTitle = $domain . ' Scam Check (' . $score . '/100 · ' . $statusLabel . ') | ' . $siteName;
$pageDescription = $domain . ' scam check by ' . $siteName . ': trust score ' . $score . '/100 (' . $statusLabel . '). '
    . 'See phishing/malware list hits, domain age, SSL, hosting, and community reports before you visit ' . $domain . '.';
$canonicalUrl = domain_page_url($domain);
$ogType = 'article';
$jsonLd = json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebPage',
            '@id' => $canonicalUrl . '#webpage',
            'name' => $pageTitle,
            'headline' => $domain . ' scam check',
            'url' => $canonicalUrl,
            'description' => $pageDescription,
            'dateModified' => !empty($record['last_checked']) ? date('c', strtotime($record['last_checked'])) : date('c'),
            'inLanguage' => 'en',
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => absolute_url('/'),
            ],
            'about' => [
                '@type' => 'Thing',
                'name' => $domain,
                'url' => 'https://' . $domain,
            ],
            'mainEntity' => [
                '@type' => 'Thing',
                'name' => $domain,
                'description' => $statusLabel . ' — trust score ' . $score . '/100',
            ],
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => $siteName,
                    'item' => absolute_url('/'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => 'Browse',
                    'item' => absolute_url('browse.php'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 3,
                    'name' => $domain . ' scam check',
                    'item' => $canonicalUrl,
                ],
            ],
        ],
        [
            '@type' => 'FAQPage',
            'mainEntity' => [
                [
                    '@type' => 'Question',
                    'name' => 'Is ' . $domain . ' safe or a scam?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $siteName . ' rates ' . $domain . ' at ' . $score . '/100 (' . $statusLabel . '). '
                            . 'This is a risk signal based on threat feeds, registration data, SSL, hosting, and community reports — not a legal verdict.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'What is the ' . $domain . ' trust score?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'The current ' . $siteName . ' trust score for ' . $domain . ' is ' . $score . ' out of 100 (' . $statusLabel . ').',
                    ],
                ],
            ],
        ],
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
            'href' => BASE_PATH . '/community.php?compose=1&type=website&q_prefill=' . urlencode($record['domain']),
            'class' => 'btn btn-sm btn-danger',
        ],
        [
            'label' => 'Rescan',
            'href' => domain_page_path($record['domain']) . '?refresh=1',
            'class' => 'btn btn-sm',
        ],
    ], [
        'last_update' => (string) ($record['last_checked'] ?? 'just now'),
    ]);
    ?>

    <?php
    // ---- Compact report body (user-facing) --------------------------------
    $analystSignals = array_values(array_filter($signals, static function ($s) {
        $g = $s['group'] ?? '';
        if ($g === 'analysis') {
            return true;
        }
        if ($g === 'ai') {
            $v = strtolower((string) ($s['value'] ?? ''));
            return $v !== '' && $v !== 'not configured' && $v !== 'unavailable';
        }
        return false;
    }));
    $primaryAnalyst = $analystSignals[0] ?? null;
    $plainSummary = match (true) {
        !empty($record['malware_hit']) || !empty($record['phishing_hit']) || ($record['status'] ?? '') === 'blacklisted'
            => 'Strong warning signals were found. Avoid logging in or sending money until you verify the site another way.',
        ($record['status'] ?? '') === 'scam' || $score < 25
            => 'This looks risky. Treat it as unsafe for payments, passwords, or personal data.',
        ($record['status'] ?? '') === 'risky' || $score < 50
            => 'Some risk signals showed up. Proceed carefully and double-check before sharing anything sensitive.',
        ($record['status'] ?? '') === 'caution' || $score < 80
            => 'No clear scam hit, but a few caution signs remain. Fine to browse carefully — stay alert.',
        default
            => 'No major red flags in this scan. Still use normal caution with logins and payments.',
    };
    $shortReasons = [];
    foreach (array_slice($verdictReasons ?: [], 0, 3) as $reason) {
        $r = trim((string) $reason);
        if ($r === '') {
            continue;
        }
        // Keep only the lead clause for readability.
        $r = preg_replace('/\s*\|\s*.*$/u', '', $r) ?? $r;
        // Older scans stored a scary-sounding bot-wall reason; present it neutrally.
        if (stripos($r, 'Real page content was blocked') !== false) {
            $r = 'Site is behind Cloudflare bot protection — content checks skipped. Normal setup, not a scam signal.';
        }
        if (mb_strlen($r) > 110) {
            $r = mb_substr($r, 0, 107) . '…';
        }
        $shortReasons[] = $r;
    }
    // Cloudflare/CDN bot walls hide the page from scanners. That's a normal setup on
    // legitimate sites, so present it as info — never as a problem.
    $contentBlocked = false;
    foreach ($signals as $s) {
        if (($s['label'] ?? '') === 'Content visibility'
            && stripos((string) ($s['value'] ?? ''), 'bot wall') !== false) {
            $contentBlocked = true;
            break;
        }
    }
    $challengeTitle = false;
    if (!empty($record['page_title'])) {
        $challengeTitle = (bool) preg_match(
            '/attention required|just a moment|checking your browser|access denied|security check|verify you are|cloudflare/i',
            (string) $record['page_title']
        );
        $contentBlocked = $contentBlocked || $challengeTitle;
    }

    // ScamAdviser-style highlight sentences: friendly line + optional detail.
    $highlightRow = static function (array $s, bool $positive): array {
        $label = (string) ($s['label'] ?? '');
        $value = is_scalar($s['value'] ?? null) ? trim((string) $s['value']) : '';
        $note = trim((string) ($s['note'] ?? ''));

        $goodMap = [
            'Domain age' => 'This domain was registered a long time ago',
            'Valid SSL' => 'A valid SSL certificate was found',
            'HTTPS / TLS' => 'The connection to this site is encrypted (HTTPS)',
            'Malware lists' => 'Not flagged by malware blocklists',
            'Phishing lists' => 'Not flagged by phishing blocklists',
            'Spam reputation (DNSBL)' => 'Clean spam / blacklist reputation',
            'HTTP status' => 'The website responds normally',
            'Content visibility' => 'Page content could be read and inspected',
            'Contact info' => 'Contact details were found on the site',
            'Privacy policy' => 'A privacy policy page was found',
            'Terms page' => 'A terms & conditions page was found',
            'SPF' => 'Email SPF protection is set up',
            'DMARC' => 'Email DMARC protection is set up',
            'MX records' => 'Mail server (MX) records are set up',
            'Trustpilot reviews' => 'Positive Trustpilot review profile',
            'Sitejabber reviews' => 'Positive Sitejabber review profile',
            'Tranco traffic rank' => 'This website gets notable traffic (Tranco ranked)',
            'AI risk judgment' => 'AI review found no major concerns',
            'Threat intel summary' => 'No hits across threat intelligence feeds',
            'Engine verdict' => 'Our scan engine rates this site positively',
            'User reports (ScamGuard)' => 'No negative community reports',
            'DNSSEC' => 'DNSSEC is enabled for this domain',
        ];
        $badMap = [
            'Domain age' => 'The domain is relatively new',
            'Valid SSL' => 'There is a problem with the SSL certificate',
            'HTTPS / TLS' => 'The secure (HTTPS) connection failed',
            'Malware lists' => 'Flagged by malware blocklists',
            'Phishing lists' => 'Flagged by phishing blocklists',
            'Spam reputation (DNSBL)' => 'Listed on spam blacklists',
            'HTTP status' => 'The site returned an HTTP error',
            'Homepage fetch' => 'The homepage could not be loaded',
            'Contact info' => 'No contact details found on the page',
            'Privacy policy' => 'No privacy policy was found',
            'Tranco traffic rank' => 'Low or unranked traffic — small or new audience',
            'AI risk judgment' => 'AI review suggests some caution',
            'Engine verdict' => 'Overall verdict is cautious — not fully verified',
            'User reports (ScamGuard)' => 'Community members reported problems',
            'Suspicious phrases' => 'Scam-style wording was detected on the page',
            'Payment language' => 'Commerce / payment wording detected',
            'MX records' => 'No mail server (MX) records found',
            'Trustpilot reviews' => 'Trustpilot reviews raise concerns',
            'Sitejabber reviews' => 'Sitejabber reviews raise concerns',
            'Redirects' => 'Many redirects before the final page',
            'WHOIS / RDAP' => 'Domain registration data is unavailable',
        ];

        $text = $positive ? ($goodMap[$label] ?? $label) : ($badMap[$label] ?? $label);
        $sub = $note !== '' ? $note : $value;
        if (in_array($sub, ['Found', 'Not found', 'Present', 'Missing', 'Clean', 'Listed', 'Valid'], true)) {
            $sub = '';
        }
        if (mb_strlen($sub) > 110) {
            $sub = mb_substr($sub, 0, 107) . '…';
        }
        return ['text' => $text, 'sub' => $sub];
    };

    $goodBits = [];
    foreach (array_slice($positives, 0, 5) as $p) {
        $goodBits[] = $highlightRow($p, true);
    }
    $badBits = [];
    foreach ($negatives as $n) {
        // Behind a bot wall the HTTP status belongs to the challenge page, not the site.
        if ($contentBlocked && ($n['label'] ?? '') === 'HTTP status') {
            continue;
        }
        if (count($badBits) >= 5) {
            break;
        }
        $badBits[] = $highlightRow($n, false);
    }
    $ageLabel = $record['domain_age_days'] !== null
        ? number_format((int) $record['domain_age_days']) . ' days'
        : 'Unknown';
    ?>

    <h2 class="sr-only"><?= h($domain) ?> scam check</h2>
    <p class="sr-only">
        <?= h($siteName) ?> rates <?= h($domain) ?> at <?= (int) $score ?>/100 (<?= h($statusLabel) ?>).
        Recheck anytime at <?= h(domain_page_url($domain)) ?>?refresh=1
    </p>

    <div class="report-compact">
        <section class="rc-card rc-summary <?= h(tone_class(($score >= 80) ? 'good' : (($score >= 50) ? 'warn' : 'bad'))) ?>">
            <div class="rc-summary-top">
                <div>
                    <p class="rc-kicker">In plain words</p>
                    <h2 class="rc-title"><?= h($statusLabel) ?></h2>
                </div>
                <div class="rc-scorechip" aria-label="Trust score <?= (int) $score ?> out of 100">
                    <span class="rc-scorechip-num"><?= (int) $score ?></span>
                    <span class="rc-scorechip-den">/100</span>
                </div>
            </div>
            <p class="rc-plain"><?= h($plainSummary) ?></p>
            <?php if ($shortReasons): ?>
                <ul class="rc-reasons">
                    <?php foreach ($shortReasons as $reason): ?>
                        <li><?= h($reason) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif (!empty($primaryAnalyst['note'])): ?>
                <p class="rc-plain rc-plain-muted"><?= h(mb_strimwidth((string) $primaryAnalyst['note'], 0, 180, '…')) ?></p>
            <?php endif; ?>
            <div class="rc-pills">
                <span class="rc-pill <?= !empty($record['malware_hit']) ? 'is-bad' : 'is-good' ?>">
                    Malware <?= !empty($record['malware_hit']) ? 'hit' : 'clean' ?>
                </span>
                <span class="rc-pill <?= !empty($record['phishing_hit']) ? 'is-bad' : 'is-good' ?>">
                    Phishing <?= !empty($record['phishing_hit']) ? 'hit' : 'clean' ?>
                </span>
                <span class="rc-pill"><?= (int) $record['check_count'] ?> scan<?= (int) $record['check_count'] === 1 ? '' : 's' ?></span>
                <?php if ($contentBlocked): ?>
                    <span class="rc-pill">Cloudflare protected</span>
                <?php elseif (!empty($record['page_title'])): ?>
                    <span class="rc-pill rc-pill-wide"><?= h(mb_strimwidth((string) $record['page_title'], 0, 42, '…')) ?></span>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($record['threat_feed_hit']): ?>
            <div class="rc-alert is-danger">Listed on threat feeds: <?= h((string) $record['threat_feed_sources']) ?></div>
        <?php endif; ?>
        <?php if ($record['manual_override']): ?>
            <div class="rc-alert is-info">Manually reviewed by an admin<?= !empty($record['admin_notes']) ? ': ' . h((string) $record['admin_notes']) : '.' ?></div>
        <?php endif; ?>
        <?php if ($contentBlocked): ?>
            <div class="rc-alert is-neutral">
                This site uses Cloudflare bot protection, so our scanner couldn't read the page content.
                That's a normal setup used by millions of legitimate sites — not a red flag by itself.
            </div>
        <?php endif; ?>

        <section class="rc-facts" aria-label="Key facts">
            <div class="rc-fact">
                <span class="rc-fact-label">Age</span>
                <span class="rc-fact-value"><?= h($ageLabel) ?></span>
            </div>
            <div class="rc-fact">
                <span class="rc-fact-label">SSL</span>
                <span class="rc-fact-value <?= !empty($record['ssl_valid']) ? 'is-good' : 'is-bad' ?>"><?= !empty($record['ssl_valid']) ? 'Valid' : 'Issue' ?></span>
            </div>
            <div class="rc-fact">
                <span class="rc-fact-label">Threat lists</span>
                <span class="rc-fact-value <?= $record['threat_feed_hit'] ? 'is-bad' : 'is-good' ?>"><?= $record['threat_feed_hit'] ? 'Hit' : 'Clean' ?></span>
            </div>
            <div class="rc-fact">
                <span class="rc-fact-label">Registrar</span>
                <span class="rc-fact-value"><?= h(mb_strimwidth((string) ($record['whois_registrar'] ?? 'Unknown'), 0, 22, '…')) ?></span>
            </div>
        </section>

        <section class="rc-highlights" aria-label="Report highlights">
            <div class="rc-hl-group">
                <header class="rc-hl-head is-good">
                    <span class="rc-hl-headicon" aria-hidden="true">✓</span>
                    <h3>Positive highlights</h3>
                </header>
                <?php if (!$goodBits): ?>
                    <p class="rc-empty">Nothing strongly positive yet.</p>
                <?php else: ?>
                    <ul class="rc-hl-list">
                        <?php foreach ($goodBits as $bit): if ($bit['text'] === '') continue; ?>
                            <li class="rc-hl-item is-good">
                                <span class="rc-hl-icon" aria-hidden="true">✓</span>
                                <div class="rc-hl-body">
                                    <span class="rc-hl-text"><?= h($bit['text']) ?></span>
                                    <?php if ($bit['sub'] !== ''): ?>
                                        <span class="rc-hl-sub"><?= h($bit['sub']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="rc-hl-group">
                <header class="rc-hl-head is-bad">
                    <span class="rc-hl-headicon" aria-hidden="true">!</span>
                    <h3>Negative highlights</h3>
                </header>
                <?php if (!$badBits): ?>
                    <p class="rc-empty">No elevated warnings.</p>
                <?php else: ?>
                    <ul class="rc-hl-list">
                        <?php foreach ($badBits as $bit): if ($bit['text'] === '') continue; ?>
                            <li class="rc-hl-item is-bad">
                                <span class="rc-hl-icon" aria-hidden="true">!</span>
                                <div class="rc-hl-body">
                                    <span class="rc-hl-text"><?= h($bit['text']) ?></span>
                                    <?php if ($bit['sub'] !== ''): ?>
                                        <span class="rc-hl-sub"><?= h($bit['sub']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>

        <details class="rc-details">
            <summary>Full technical details</summary>
            <div class="rc-details-body">
                <?php if (!empty($record['uses_cdn'])): ?>
                    <p class="rc-tech-note">
                        Public IP <code><?= h((string) ($record['ip_address'] ?? '')) ?></code> is
                        <?= h((string) ($record['cdn_provider'] ?: 'CDN')) ?> — normal for many sites, not the origin location.
                    </p>
                <?php endif; ?>

                <?php if ($signals): ?>
                    <div class="signal-groups rc-signal-groups">
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

                <?php if ($unchecked): ?>
                    <div class="card" style="margin-top:12px;">
                        <h3 style="margin-top:0; font-size:14px;">Not checked in this scan</h3>
                        <ul class="verdict-reasons">
                            <?php foreach ($unchecked as $u): ?>
                                <li><strong><?= h((string) $u['label']) ?>:</strong> <?= h((string) ($u['note'] ?: 'Not available')) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </details>
    </div>

    <?php if (count($history) > 1): ?>
    <div class="rc-card" style="margin-top:12px;">
        <h3 class="rc-section-title">Score history</h3>
        <div class="history-bars">
            <?php foreach ($history as $hrow): ?>
                <div title="<?= h($hrow['checked_at']) ?>: <?= (int) $hrow['trust_score'] ?>"
                     style="height:<?= max(8, (int) $hrow['trust_score']) ?>%;"></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $threadStmt = Database::getConnection()->prepare(
        "SELECT t.id, t.title, t.category, t.comment_count, t.review_status, t.is_sticky, t.is_locked, t.last_activity_at, u.username
         FROM forum_threads t JOIN users u ON u.id = t.user_id
         WHERE t.subject_type = 'website' AND t.subject_value = ? AND t.review_status <> 'rejected'
         ORDER BY t.is_sticky DESC, t.last_activity_at DESC LIMIT 4"
    );
    $threadStmt->execute([$record['domain']]);
    $domainThreads = $threadStmt->fetchAll();
    ?>
    <div class="rc-card" style="margin-top:12px;">
        <div class="rc-community-head">
            <h3 class="rc-section-title" style="margin:0;">Community</h3>
            <a class="btn btn-sm btn-danger" href="<?= BASE_PATH ?>/community.php?compose=1&type=website&q_prefill=<?= rawurlencode($record['domain']) ?>">Report</a>
        </div>
        <?php if (!$domainThreads): ?>
            <p class="rc-empty" style="margin:8px 0 0;">No reports yet for this site.</p>
        <?php else: ?>
            <ul class="forum-list" style="margin-top:6px;">
                <?php foreach ($domainThreads as $t): $rb = thread_review_badge((string) $t['review_status']); ?>
                <li class="forum-item forum-item-compact">
                    <div class="forum-body">
                        <a class="forum-main" href="<?= BASE_PATH ?>/thread.php?id=<?= (int) $t['id'] ?>">
                            <div class="forum-title-row">
                                <?php if ($t['is_sticky'] || $t['is_locked']): ?>
                                <div class="forum-flags">
                                    <?php if ($t['is_sticky']): ?><span class="forum-flag">Pinned</span><?php endif; ?>
                                    <?php if ($t['is_locked']): ?><span class="forum-flag">Locked</span><?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <span class="forum-title"><?= h($t['title']) ?></span>
                            </div>
                        </a>
                        <div class="forum-foot">
                            <span class="forum-status <?= h($rb['class']) ?>"><?= h($rb['label']) ?></span>
                            <span class="forum-sep" aria-hidden="true">·</span>
                            <span><?= h(time_ago($t['last_activity_at'])) ?></span>
                        </div>
                    </div>
                    <div class="forum-side" title="<?= (int) $t['comment_count'] ?> replies">
                        <span class="forum-replies"><?= (int) $t['comment_count'] ?></span>
                        <span class="forum-replies-label">replies</span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <p class="rc-footer-link">
        <a href="<?= h(domain_page_path($record['domain']) . '?refresh=1') ?>">Recheck <?= h($domain) ?></a>
        · <a href="<?= BASE_PATH ?>/browse.php">Browse more</a>
    </p>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
