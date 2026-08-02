<?php
require_once __DIR__ . '/../includes/Auth.php';
Auth::requireLogin();
$pageTitle = 'API Keys';
$db = Database::getConnection();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $label = trim($_POST['label'] ?? '');
        $email = trim($_POST['owner_email'] ?? '');
        $rate = max(1, (int) ($_POST['rate_limit_per_day'] ?? 1000));
        $newKey = bin2hex(random_bytes(24));
        $stmt = $db->prepare('INSERT INTO api_keys (api_key, label, owner_email, rate_limit_per_day) VALUES (?, ?, ?, ?)');
        $stmt->execute([$newKey, $label ?: null, $email ?: null, $rate]);
        log_admin_activity(Auth::id(), 'create_api_key', $label);
        $flash = 'API key created: ' . $newKey . ' (copy it now — shown only once here, but also viewable below).';
    } elseif ($action === 'revoke') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->prepare('UPDATE api_keys SET active = 0 WHERE id = ?')->execute([$id]);
        log_admin_activity(Auth::id(), 'revoke_api_key', (string) $id);
        $flash = 'API key revoked.';
    }
}

require_once __DIR__ . '/../includes/functions.php';
require __DIR__ . '/includes/layout_top.php';

$keys = $db->query('SELECT * FROM api_keys ORDER BY created_at DESC')->fetchAll();
$csrf = Auth::csrfToken();
?>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0;">Create new API key</h3>
    <form method="post">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <div class="grid grid-3">
            <div class="field"><label>Label</label><input type="text" name="label" placeholder="e.g. Partner integration"></div>
            <div class="field"><label>Owner email</label><input type="email" name="owner_email"></div>
            <div class="field"><label>Rate limit / day</label><input type="number" name="rate_limit_per_day" value="1000"></div>
        </div>
        <button type="submit" class="btn btn-primary">Generate Key</button>
    </form>
</div>

<div class="card" style="padding:0;">
    <table>
        <thead><tr><th>Key</th><th>Label</th><th>Owner</th><th>Requests</th><th>Rate limit</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($keys as $k): ?>
            <tr>
                <td><code><?= h(substr($k['api_key'], 0, 12)) ?>&hellip;</code></td>
                <td><?= h($k['label'] ?? '—') ?></td>
                <td><?= h($k['owner_email'] ?? '—') ?></td>
                <td><?= number_format($k['requests_made']) ?></td>
                <td><?= number_format($k['rate_limit_per_day']) ?>/day</td>
                <td><?= $k['active'] ? '<span class="badge badge-safe">Active</span>' : '<span class="badge badge-scam">Revoked</span>' ?></td>
                <td>
                    <?php if ($k['active']): ?>
                    <form method="post">
                        <input type="hidden" name="action" value="revoke">
                        <input type="hidden" name="id" value="<?= $k['id'] ?>">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($keys)): ?><tr><td colspan="7" style="color:var(--text-faint);">No API keys yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
