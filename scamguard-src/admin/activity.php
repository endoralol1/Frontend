<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = 'Activity Log';
$db = Database::getConnection();
require __DIR__ . '/includes/layout_top.php';

$logs = $db->query("
    SELECT admin_activity_log.*, admin_users.username
    FROM admin_activity_log
    LEFT JOIN admin_users ON admin_users.id = admin_activity_log.admin_id
    ORDER BY admin_activity_log.created_at DESC LIMIT 100
")->fetchAll();
?>

<div class="card" style="padding:0;">
    <table>
        <thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Target</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
            <tr>
                <td style="color:var(--text-faint); white-space:nowrap;"><?= h($l['created_at']) ?></td>
                <td><?= h($l['username'] ?? 'Unknown') ?></td>
                <td><?= h($l['action']) ?></td>
                <td><?= h($l['target'] ?? '—') ?></td>
                <td style="color:var(--text-muted);"><?= h($l['details'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?><tr><td colspan="5" style="color:var(--text-faint);">No activity logged yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
