<?php
require_once __DIR__ . '/../includes/Auth.php';
Auth::requireLogin();
$pageTitle = 'Scoring Configuration';
$db = Database::getConnection();
$flash = null;

$fields = [
    'weight_domain_age' => 'Domain age weight',
    'weight_registration_length' => 'Registration length weight',
    'weight_ssl' => 'SSL certificate weight',
    'weight_hosting' => 'Hosting reputation weight',
    'weight_content' => 'Content signals weight',
    'weight_threat_feed' => 'Threat feed hit weight',
    'threshold_safe' => 'Safe threshold (score ≥)',
    'threshold_caution' => 'Caution threshold (score ≥)',
    'threshold_risky' => 'Risky threshold (score ≥)',
    'recheck_interval_hours' => 'Re-check interval (hours)',
    'new_domain_threshold_days' => '"New domain" cutoff (days)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf'] ?? null)) {
    foreach ($fields as $key => $label) {
        if (isset($_POST[$key])) {
            $val = (float) $_POST[$key];
            $stmt = $db->prepare('UPDATE scoring_config SET config_value = ? WHERE config_key = ?');
            $stmt->execute([$val, $key]);
        }
    }
    log_admin_activity(Auth::id(), 'update_scoring_config');
    $flash = 'Scoring configuration updated. Changes apply to the next check of each domain.';
}

require_once __DIR__ . '/../includes/functions.php';
require __DIR__ . '/includes/layout_top.php';

$current = [];
foreach ($db->query('SELECT config_key, config_value, description FROM scoring_config')->fetchAll() as $row) {
    $current[$row['config_key']] = $row;
}
$csrf = Auth::csrfToken();
?>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div class="alert alert-info">
    Weights control how many points each signal can add or subtract. Thresholds control which score range maps to which status label (Safe / Caution / Risky / Scam).
</div>

<form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <div class="grid grid-2">
        <?php foreach ($fields as $key => $label): $row = $current[$key] ?? null; ?>
            <div class="card">
                <label style="font-size:14px; color:var(--text);"><?= h($label) ?></label>
                <input type="number" step="0.1" name="<?= h($key) ?>" value="<?= h($row['config_value'] ?? '0') ?>">
                <p style="color:var(--text-faint); font-size:12.5px; margin:8px 0 0;"><?= h($row['description'] ?? '') ?></p>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-top:20px;">Save Scoring Configuration</button>
</form>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
