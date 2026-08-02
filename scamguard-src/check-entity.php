<?php
/**
 * Unified entry: /scamguard/check-entity.php?type=phone&q=...
 * Pretty URLs rewrite here.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/EntityRepository.php';

$type = strtolower(trim($_GET['type'] ?? 'auto'));
$q = trim($_GET['q'] ?? ($_GET['d'] ?? ''));
$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';

$allowed = ['website', 'phone', 'crypto', 'iban', 'card', 'auto'];
if (!in_array($type, $allowed, true)) {
    $type = 'auto';
}

if ($type === 'auto') {
    require_once __DIR__ . '/includes/EntityRepository.php';
    $type = EntityRepository::detectType($q);
}

if ($type === 'website') {
    $domain = normalize_domain($q);
    if (!$domain) {
        header('Location: ' . base_path('/?error=invalid'));
        exit;
    }
    header('Location: ' . domain_page_path($domain) . ($force ? '?refresh=1' : ''), true, 302);
    exit;
}

if ($q === '') {
    header('Location: ' . base_path('/?type=' . rawurlencode($type)));
    exit;
}

$repo = new EntityRepository();
$record = $repo->getOrCheck($type, $q, $force);

// Canonical pretty URL
$pretty = match ($type) {
    'phone' => base_path('phone/' . rawurlencode(ltrim((string) ($record['entity_value'] ?: $q), '+'))),
    'crypto' => base_path('crypto/' . rawurlencode((string) ($record['entity_value'] ?: $q))),
    'iban' => base_path('iban/' . rawurlencode((string) ($record['entity_value'] ?: $q))),
    'card' => base_path('card/' . rawurlencode((string) ($record['entity_value'] ?: 'check'))),
    default => base_path('/'),
};

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$isPretty = (bool) preg_match('#/scamguard/(phone|crypto|iban|card)/#', $requestPath);
if (!$force && !$isPretty && empty($record['_invalid'])) {
    header('Location: ' . $pretty, true, 301);
    exit;
}

$signals = json_decode((string) ($record['signals_json'] ?? ''), true);
if (!is_array($signals)) {
    $signals = [];
}
$facts = json_decode((string) ($record['facts_json'] ?? ''), true);
if (!is_array($facts)) {
    $facts = [];
}

$badge = status_badge((string) ($record['status'] ?? 'unknown'));
$score = (int) ($record['trust_score'] ?? 0);
$display = (string) ($record['display_value'] ?: $q);
$titleType = match ($type) {
    'phone' => 'phone number',
    'crypto' => 'crypto address',
    'iban' => 'IBAN',
    'card' => 'bank card',
    default => 'item',
};

$pageTitle = 'Facts about ' . $display . ' — ' . get_setting('site_name', 'ScamGuard');
$pageDescription = "ScamGuard {$titleType} check for {$display}. Trust score {$score}/100 — {$badge['label']}.";
$canonicalUrl = match ($type) {
    'phone' => absolute_url('phone/' . rawurlencode(ltrim((string) ($record['entity_value'] ?: $q), '+'))),
    'crypto' => absolute_url('crypto/' . rawurlencode((string) ($record['entity_value'] ?: $q))),
    'iban' => absolute_url('iban/' . rawurlencode((string) ($record['entity_value'] ?: $q))),
    'card' => absolute_url('card/' . rawurlencode((string) ($record['entity_value'] ?: 'check'))),
    default => absolute_url('/'),
};

$groups = [
    'facts' => 'Key facts',
    'network' => 'Network information',
    'reputation' => 'Reputation',
];

$grouped = [];
foreach ($signals as $signal) {
    $g = $signal['group'] ?? 'facts';
    $grouped[$g][] = $signal;
}

$positives = array_values(array_filter($signals, static fn($s) => ($s['tone'] ?? '') === 'good'));
$negatives = array_values(array_filter($signals, static fn($s) => in_array(($s['tone'] ?? ''), ['bad', 'warn'], true)));

require __DIR__ . '/includes/header.php';
?>

<section class="section container entity-check result-page">
    <p class="entity-kicker"><?= h(ucfirst($type)) ?> check</p>

    <?php
    render_status_banner((string) ($record['status'] ?? 'unknown'), $score, $display, [
        [
            'label' => 'REPORT',
            'href' => BASE_PATH . '/report.php?type=' . urlencode($type) . '&q=' . urlencode($display),
            'class' => 'btn btn-sm btn-danger',
        ],
        [
            'label' => '↻ Rescan',
            'href' => $pretty . '?refresh=1',
            'class' => 'btn btn-sm',
        ],
        [
            'label' => 'New check',
            'href' => BASE_PATH . '/?type=' . urlencode($type),
            'class' => 'btn btn-sm',
        ],
    ], [
        'last_update' => (string) ($record['checked_at'] ?? $record['last_checked'] ?? 'just now'),
    ]);
    ?>

    <p style="color:var(--muted); margin:0 0 18px; max-width:36rem;">
        <?php if (($record['verdict'] ?? '') === 'invalid'): ?>
            This <?= h($titleType) ?> could not be validated. Double-check the input and try again.
        <?php elseif (($record['status'] ?? '') === 'scam'): ?>
            Multiple risk signals — treat this <?= h($titleType) ?> as high risk.
        <?php elseif (($record['status'] ?? '') === 'risky'): ?>
            Caution advised — abuse reports or risk patterns were found.
        <?php else: ?>
            Still verify independently before sending money or personal data.
        <?php endif; ?>
    </p>

    <div class="highlights-grid" style="margin-top:22px;">
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

    <?php foreach ($groups as $gKey => $gLabel): ?>
        <?php if (empty($grouped[$gKey])) continue; ?>
        <section class="entity-panel">
            <h2 class="entity-panel-title"><?= h($gLabel) ?></h2>
            <div class="card" style="padding:0;">
                <div class="table-wrap"><table>
                    <tbody>
                    <?php foreach ($grouped[$gKey] as $s): ?>
                        <tr>
                            <td style="width:38%; color:var(--muted);"><?= h((string) $s['label']) ?></td>
                            <td>
                                <strong><?= h((string) $s['value']) ?></strong>
                                <?php if (!empty($s['note'])): ?>
                                    <div class="signal-note"><?= h((string) $s['note']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </section>
    <?php endforeach; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
