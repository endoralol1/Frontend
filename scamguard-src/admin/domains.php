<?php
require_once __DIR__ . '/../includes/DomainRepository.php';
require_once __DIR__ . '/../includes/Auth.php';
Auth::requireLogin();

$pageTitle = 'Domains';
$db = Database::getConnection();
$repo = new DomainRepository();

$flash = null;

// -------- Handle actions (POST) --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'rescan' && $id) {
        $record = $repo->findById($id);
        if ($record) {
            $checker = new DomainChecker($record['domain']);
            $result = $checker->run();
            $repo->upsert($result, $id, $record['discovered_via']);
            log_admin_activity(Auth::id(), 'rescan_domain', $record['domain']);
            $flash = 'Domain re-checked.';
        }
    } elseif ($action === 'override' && $id) {
        $status = $_POST['status'] ?? 'unknown';
        $score = (int) ($_POST['score'] ?? 50);
        $notes = trim($_POST['notes'] ?? '');
        $stmt = $db->prepare('UPDATE domains SET status = ?, trust_score = ?, manual_override = 1, admin_notes = ? WHERE id = ?');
        $stmt->execute([$status, max(1, min(100, $score)), $notes, $id]);
        log_admin_activity(Auth::id(), 'manual_override', (string) $id, "status=$status score=$score");
        $flash = 'Manual override applied.';
    } elseif ($action === 'clear_override' && $id) {
        $stmt = $db->prepare('UPDATE domains SET manual_override = 0 WHERE id = ?');
        $stmt->execute([$id]);
        log_admin_activity(Auth::id(), 'clear_override', (string) $id);
        $flash = 'Override cleared — domain will be re-scored on next check.';
    } elseif ($action === 'delete' && $id) {
        $stmt = $db->prepare('DELETE FROM domains WHERE id = ?');
        $stmt->execute([$id]);
        log_admin_activity(Auth::id(), 'delete_domain', (string) $id);
        $flash = 'Domain record deleted.';
    } elseif ($action === 'bulk_add') {
        $lines = preg_split('/[\r\n,]+/', $_POST['bulk_domains'] ?? '');
        $added = 0;
        foreach ($lines as $line) {
            $d = normalize_domain(trim($line));
            if ($d && !$repo->find($d)) {
                $repo->getOrCheck($d, 'manual');
                $added++;
            }
        }
        log_admin_activity(Auth::id(), 'bulk_add', null, "$added domains added");
        $flash = "$added domain(s) added and checked.";
    }
}

require_once __DIR__ . '/../includes/DomainChecker.php';
require __DIR__ . '/includes/layout_top.php';

// -------- Handle filters (GET) --------
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') { $where[] = 'domain LIKE ?'; $params[] = "%$search%"; }
if ($statusFilter !== '') { $where[] = 'status = ?'; $params[] = $statusFilter; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM domains $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = $db->prepare("SELECT * FROM domains $whereSql ORDER BY last_checked DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$domains = $stmt->fetchAll();

$csrf = Auth::csrfToken();
?>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:20px;">
    <form method="get" style="display:flex; gap:10px; flex-wrap:wrap;">
        <input type="text" name="search" placeholder="Search domain..." value="<?= h($search) ?>" style="max-width:280px;">
        <select name="status" style="max-width:180px;">
            <option value="">All statuses</option>
            <?php foreach (['safe','caution','risky','scam','whitelisted','blacklisted','unknown'] as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Filter</button>
    </form>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0;">Bulk add domains</h3>
    <form method="post">
        <input type="hidden" name="action" value="bulk_add">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <textarea name="bulk_domains" rows="3" placeholder="Paste domains, one per line or comma-separated"></textarea>
        <button type="submit" class="btn btn-primary" style="margin-top:10px;">Add &amp; Check</button>
    </form>
</div>

<div class="card" style="padding:0;">
    <table>
        <thead><tr><th>Domain</th><th>Score</th><th>Status</th><th>Age</th><th>Last checked</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($domains as $d): $badge = status_badge($d['status']); ?>
            <tr>
                <td>
                    <a href="<?= BASE_PATH ?>/check.php?d=<?= urlencode($d['domain']) ?>" target="_blank"><?= h($d['domain']) ?></a>
                    <?php if ($d['manual_override']): ?><span title="Manually overridden">🔒</span><?php endif; ?>
                    <?php if ($d['threat_feed_hit']): ?><span title="Threat feed hit">⚠️</span><?php endif; ?>
                </td>
                <td><?= (int) $d['trust_score'] ?></td>
                <td><span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span></td>
                <td><?= $d['domain_age_days'] !== null ? (int) $d['domain_age_days'] . 'd' : '—' ?></td>
                <td style="color:var(--text-faint); white-space:nowrap;"><?= h($d['last_checked'] ?? '—') ?></td>
                <td>
                    <details>
                        <summary class="btn btn-sm">Manage</summary>
                        <div style="padding:14px; background:var(--bg-elevated); border-radius:8px; margin-top:8px; min-width:260px;">

                            <form method="post" style="margin-bottom:10px;">
                                <input type="hidden" name="action" value="rescan">
                                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <button type="submit" class="btn btn-sm" style="width:100%;">🔄 Re-check now</button>
                            </form>

                            <form method="post" style="margin-bottom:10px;">
                                <input type="hidden" name="action" value="override">
                                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <label style="margin-top:0;">Set status</label>
                                <select name="status">
                                    <?php foreach (['whitelisted','safe','caution','risky','scam','blacklisted'] as $s): ?>
                                        <option value="<?= $s ?>" <?= $d['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label>Score</label>
                                <input type="number" name="score" min="1" max="100" value="<?= (int) $d['trust_score'] ?>">
                                <label>Notes</label>
                                <textarea name="notes" rows="2"><?= h($d['admin_notes'] ?? '') ?></textarea>
                                <button type="submit" class="btn btn-primary btn-sm" style="width:100%; margin-top:8px;">Apply Override</button>
                            </form>

                            <?php if ($d['manual_override']): ?>
                            <form method="post" style="margin-bottom:10px;">
                                <input type="hidden" name="action" value="clear_override">
                                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <button type="submit" class="btn btn-sm" style="width:100%;">Clear Override</button>
                            </form>
                            <?php endif; ?>

                            <form method="post" onsubmit="return confirm('Delete this domain record permanently?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="width:100%;">🗑 Delete</button>
                            </form>
                        </div>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($domains)): ?><tr><td colspan="6" style="color:var(--text-faint);">No domains match your filter.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<div style="margin-top:16px; display:flex; gap:8px;">
    <?php if ($page > 1): ?><a class="btn btn-sm" href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>">&larr; Prev</a><?php endif; ?>
    <?php if ($offset + $perPage < $total): ?><a class="btn btn-sm" href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>">Next &rarr;</a><?php endif; ?>
    <span style="color:var(--text-faint); align-self:center; margin-left:8px;">Page <?= $page ?> &middot; <?= number_format($total) ?> total</span>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
