<?php
require_once __DIR__ . '/../includes/DomainRepository.php';
require_once __DIR__ . '/../includes/Auth.php';

Auth::requireLogin();
$pageTitle = 'Reports';
$db = Database::getConnection();
$repo = new DomainRepository();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf'] ?? null)) {
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = $db->prepare('SELECT * FROM reports WHERE id = ?');
    $stmt->execute([$id]);
    $report = $stmt->fetch();

    if ($report) {
        if ($action === 'approve') {
            // Approving a report nudges the domain toward "risky" and flags it for re-check
            $existing = $repo->find($report['domain_text']);
            if ($existing) {
                $newScore = min($existing['trust_score'], 25);
                $db->prepare('UPDATE domains SET trust_score = ?, status = ?, last_checked = NULL WHERE id = ?')
                   ->execute([$newScore, score_to_status($newScore), $existing['id']]);
            } else {
                $repo->getOrCheck($report['domain_text'], 'user_report');
            }
            $db->prepare("UPDATE reports SET status = 'approved', admin_id = ?, reviewed_at = NOW() WHERE id = ?")
               ->execute([Auth::id(), $id]);
            $flash = 'Report approved and domain flagged for re-check.';
        } elseif ($action === 'reject') {
            $db->prepare("UPDATE reports SET status = 'rejected', admin_id = ?, reviewed_at = NOW() WHERE id = ?")
               ->execute([Auth::id(), $id]);
            $flash = 'Report rejected.';
        }
        log_admin_activity(Auth::id(), 'review_report', (string) $id, $action);
    }
}

require __DIR__ . '/includes/layout_top.php';

$statusFilter = $_GET['status'] ?? 'pending';
$stmt = $db->prepare('SELECT * FROM reports WHERE status = ? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$statusFilter]);
$reports = $stmt->fetchAll();
$csrf = Auth::csrfToken();

$categoryLabels = [
    'phishing' => 'Phishing', 'fake_shop' => 'Fake Shop', 'crypto_scam' => 'Crypto Scam',
    'tech_support_scam' => 'Tech Support Scam', 'identity_theft' => 'Identity Theft', 'other' => 'Other',
];
?>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div style="margin-bottom:16px; display:flex; gap:8px;">
    <?php foreach (['pending','approved','rejected'] as $s): ?>
        <a href="?status=<?= $s ?>" class="btn btn-sm <?= $statusFilter === $s ? 'btn-primary' : '' ?>"><?= ucfirst($s) ?></a>
    <?php endforeach; ?>
</div>

<div class="card" style="padding:0;">
    <table>
        <thead><tr><th>Domain</th><th>Category</th><th>Description</th><th>Reporter</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($reports as $r): ?>
            <tr>
                <td><a href="<?= BASE_PATH ?>/check.php?d=<?= urlencode($r['domain_text']) ?>" target="_blank"><?= h($r['domain_text']) ?></a></td>
                <td><?= h($categoryLabels[$r['category']] ?? $r['category']) ?></td>
                <td style="max-width:280px;"><?= h(mb_strimwidth($r['description'] ?? '—', 0, 120, '…')) ?></td>
                <td><?= h($r['reporter_email'] ?? 'Anonymous') ?></td>
                <td style="color:var(--text-faint); white-space:nowrap;"><?= h($r['created_at']) ?></td>
                <td>
                    <?php if ($statusFilter === 'pending'): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                    </form>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <button type="submit" class="btn btn-sm">Reject</button>
                    </form>
                    <?php else: ?>
                        <span style="color:var(--text-faint);">Reviewed</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($reports)): ?><tr><td colspan="6" style="color:var(--text-faint);">No <?= h($statusFilter) ?> reports.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
