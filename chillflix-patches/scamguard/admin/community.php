<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/UserAuth.php';
Auth::requireLogin();

$pageTitle = 'Community';
$db = Database::getConnection();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';
    $threadId = (int) ($_POST['thread_id'] ?? 0);

    if (in_array($action, ['approve', 'reject'], true) && $threadId) {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $db->prepare('SELECT report_id, entity_report_id FROM forum_threads WHERE id = ?');
        $stmt->execute([$threadId]);
        $t = $stmt->fetch();
        $db->prepare('UPDATE forum_threads SET review_status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
           ->execute([$status, Auth::id(), $threadId]);
        if ($t && !empty($t['report_id'])) {
            $db->prepare('UPDATE reports SET status = ?, admin_id = ?, reviewed_at = NOW() WHERE id = ?')
               ->execute([$status, Auth::id(), $t['report_id']]);
        }
        if ($t && !empty($t['entity_report_id'])) {
            $db->prepare('UPDATE entity_reports SET status = ?, admin_id = ?, reviewed_at = NOW() WHERE id = ?')
               ->execute([$status, Auth::id(), $t['entity_report_id']]);
        }
        log_admin_activity(Auth::id(), 'forum_' . $action, 'thread:' . $threadId);
        $flash = 'Thread #' . $threadId . ' ' . $status . '.';
    } elseif (in_array($action, ['sticky', 'unsticky'], true) && $threadId) {
        $db->prepare('UPDATE forum_threads SET is_sticky = ? WHERE id = ?')->execute([$action === 'sticky' ? 1 : 0, $threadId]);
        log_admin_activity(Auth::id(), 'forum_' . $action, 'thread:' . $threadId);
        $flash = 'Thread #' . $threadId . ($action === 'sticky' ? ' pinned.' : ' unpinned.');
    } elseif (in_array($action, ['lock', 'unlock'], true) && $threadId) {
        $db->prepare('UPDATE forum_threads SET is_locked = ? WHERE id = ?')->execute([$action === 'lock' ? 1 : 0, $threadId]);
        log_admin_activity(Auth::id(), 'forum_' . $action, 'thread:' . $threadId);
        $flash = 'Thread #' . $threadId . ($action === 'lock' ? ' locked.' : ' unlocked.');
    } elseif ($action === 'delete' && $threadId) {
        $db->prepare('DELETE FROM forum_threads WHERE id = ?')->execute([$threadId]);
        log_admin_activity(Auth::id(), 'forum_delete_thread', 'thread:' . $threadId);
        $flash = 'Thread #' . $threadId . ' deleted.';
    } elseif (in_array($action, ['ban_user', 'unban_user'], true)) {
        $targetUser = (int) ($_POST['user_id'] ?? 0);
        if ($targetUser) {
            $db->prepare('UPDATE users SET is_banned = ? WHERE id = ?')->execute([$action === 'ban_user' ? 1 : 0, $targetUser]);
            log_admin_activity(Auth::id(), $action, 'user:' . $targetUser);
            $flash = 'User #' . $targetUser . ($action === 'ban_user' ? ' banned.' : ' unbanned.');
        }
    } elseif ($action === 'set_role') {
        $targetUser = (int) ($_POST['user_id'] ?? 0);
        $newRole = $_POST['role'] ?? 'user';
        if ($targetUser && in_array($newRole, ['user', 'moderator', 'admin'], true)) {
            $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetUser]);
            log_admin_activity(Auth::id(), 'set_community_role', 'user:' . $targetUser, $newRole);
            $flash = 'User #' . $targetUser . ' role set to ' . $newRole . '.';
        }
    }
}

$filter = $_GET['filter'] ?? 'pending';
if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $filter = 'pending';
}
$whereSql = $filter === 'all' ? '' : "WHERE t.review_status = " . $db->quote($filter);

$counts = [];
foreach ($db->query("SELECT review_status, COUNT(*) c FROM forum_threads GROUP BY review_status")->fetchAll() as $row) {
    $counts[$row['review_status']] = (int) $row['c'];
}
$totalThreads = array_sum($counts);
$totalUsers = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$bannedUsers = (int) $db->query('SELECT COUNT(*) FROM users WHERE is_banned = 1')->fetchColumn();

$threads = $db->query(
    "SELECT t.*, u.username, u.is_banned, u.role AS user_role
     FROM forum_threads t JOIN users u ON u.id = t.user_id
     $whereSql
     ORDER BY t.is_sticky DESC, t.created_at DESC
     LIMIT 100"
)->fetchAll();

$allUsers = $db->query(
    "SELECT u.id, u.username, u.email, u.role, u.is_banned, u.created_at, u.last_login_at,
            (SELECT COUNT(*) FROM forum_threads WHERE user_id = u.id) AS thread_count,
            (SELECT COUNT(*) FROM forum_comments WHERE user_id = u.id AND is_deleted = 0) AS comment_count
     FROM users u ORDER BY u.created_at DESC LIMIT 200"
)->fetchAll();

require __DIR__ . '/includes/layout_top.php';
?>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div class="admin-cards" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:12px; margin-bottom:18px;">
    <div class="card"><div style="font-size:22px;font-weight:800;"><?= $totalThreads ?></div><div style="color:var(--text-faint);font-size:13px;">Threads</div></div>
    <div class="card"><div style="font-size:22px;font-weight:800;color:var(--caution);"><?= $counts['pending'] ?? 0 ?></div><div style="color:var(--text-faint);font-size:13px;">Awaiting review</div></div>
    <div class="card"><div style="font-size:22px;font-weight:800;color:var(--safe);"><?= $counts['approved'] ?? 0 ?></div><div style="color:var(--text-faint);font-size:13px;">Approved</div></div>
    <div class="card"><div style="font-size:22px;font-weight:800;"><?= $totalUsers ?></div><div style="color:var(--text-faint);font-size:13px;">Users (<?= $bannedUsers ?> banned)</div></div>
</div>

<div style="display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label): ?>
        <a class="btn btn-sm <?= $filter === $key ? 'btn-primary' : '' ?>" href="?filter=<?= $key ?>"><?= h($label) ?><?= $key !== 'all' && isset($counts[$key]) ? ' (' . $counts[$key] . ')' : '' ?></a>
    <?php endforeach; ?>
</div>

<div class="card" style="overflow-x:auto;">
    <?php if (!$threads): ?>
        <p style="color:var(--text-faint); margin:6px 0;">No threads in this view.</p>
    <?php else: ?>
    <table class="admin-table" style="width:100%; border-collapse:collapse; font-size:13.5px;">
        <thead>
            <tr style="text-align:left; color:var(--text-faint);">
                <th style="padding:8px;">Thread</th>
                <th style="padding:8px;">Subject</th>
                <th style="padding:8px;">Author</th>
                <th style="padding:8px;">💬</th>
                <th style="padding:8px;">Status</th>
                <th style="padding:8px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($threads as $t): $review = thread_review_badge((string) $t['review_status']); ?>
            <tr style="border-top:1px solid var(--line);">
                <td style="padding:8px; max-width:280px;">
                    <a href="<?= BASE_PATH ?>/thread.php?id=<?= (int) $t['id'] ?>" target="_blank" style="color:var(--brand-2); font-weight:600;">
                        <?= $t['is_sticky'] ? '📌 ' : '' ?><?= $t['is_locked'] ? '🔒 ' : '' ?><?= h(mb_substr((string) $t['title'], 0, 60)) ?>
                    </a>
                    <div style="color:var(--text-faint); font-size:12px;"><?= h(report_category_label((string) $t['category'])) ?> · <?= h(time_ago($t['created_at'])) ?></div>
                </td>
                <td style="padding:8px;">
                    <span style="color:var(--text-faint); font-size:12px;"><?= h(thread_subject_label((string) $t['subject_type'])) ?></span><br>
                    <?= $t['subject_type'] === 'card' ? '<em>hidden</em>' : h(mb_substr((string) $t['subject_value'], 0, 36)) ?>
                </td>
                <td style="padding:8px;">
                    <a href="<?= BASE_PATH ?>/profile.php?u=<?= rawurlencode((string) $t['username']) ?>" target="_blank" style="color:var(--brand-2);"><?= h($t['username']) ?></a>
                    <?= role_chip($t['user_role'] ?? null) ?><?= $t['is_banned'] ? ' <span style="color:var(--scam);">(banned)</span>' : '' ?>
                </td>
                <td style="padding:8px;"><?= (int) $t['comment_count'] ?></td>
                <td style="padding:8px;"><span class="forum-status <?= h($review['class']) ?>"><?= h($review['label']) ?></span></td>
                <td style="padding:8px; white-space:nowrap;">
                    <?php
                    $actions = [];
                    if ($t['review_status'] !== 'approved') $actions[] = ['approve', '✓', 'Approve report (it points to the site)'];
                    if ($t['review_status'] !== 'rejected') $actions[] = ['reject', '✕', 'Reject report (not relevant / spam)'];
                    $actions[] = $t['is_sticky'] ? ['unsticky', 'Unpin', 'Unpin'] : ['sticky', '📌', 'Pin to top'];
                    $actions[] = $t['is_locked'] ? ['unlock', 'Unlock', 'Unlock'] : ['lock', '🔒', 'Lock discussion'];
                    $actions[] = ['delete', '🗑', 'Delete thread'];
                    foreach ($actions as [$act, $label, $title]):
                    ?>
                    <form method="post" style="display:inline;" <?= $act === 'delete' ? 'onsubmit="return confirm(\'Delete this thread permanently?\');"' : '' ?>>
                        <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
                        <input type="hidden" name="action" value="<?= h($act) ?>">
                        <input type="hidden" name="thread_id" value="<?= (int) $t['id'] ?>">
                        <button type="submit" class="btn btn-sm" title="<?= h($title) ?>" style="padding:3px 9px;"><?= h($label) ?></button>
                    </form>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<h2 style="margin:26px 0 10px; font-size:1.1rem;">Users &amp; roles</h2>
<p style="color:var(--text-faint); font-size:13px; margin:0 0 12px;">
    Moderators and admins are normal community accounts with a role — no separate login needed.
    <strong>Moderator</strong> can approve/reject, pin, lock, and delete in the forum.
    <strong>Admin</strong> can additionally log into this admin panel with their community credentials.
</p>
<div class="card" style="overflow-x:auto;">
    <?php if (!$allUsers): ?>
        <p style="color:var(--text-faint); margin:6px 0;">No community users yet.</p>
    <?php else: ?>
    <table class="admin-table" style="width:100%; border-collapse:collapse; font-size:13.5px;">
        <thead>
            <tr style="text-align:left; color:var(--text-faint);">
                <th style="padding:8px;">User</th>
                <th style="padding:8px;">Email</th>
                <th style="padding:8px;">Reports / Comments</th>
                <th style="padding:8px;">Joined</th>
                <th style="padding:8px;">Role</th>
                <th style="padding:8px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($allUsers as $u): ?>
            <tr style="border-top:1px solid var(--line);">
                <td style="padding:8px;">
                    <a href="<?= BASE_PATH ?>/profile.php?u=<?= rawurlencode((string) $u['username']) ?>" target="_blank" style="color:var(--brand-2); font-weight:600;"><?= h($u['username']) ?></a>
                    <?= $u['is_banned'] ? ' <span style="color:var(--scam);">(banned)</span>' : '' ?>
                </td>
                <td style="padding:8px; color:var(--text-faint);"><?= h($u['email']) ?></td>
                <td style="padding:8px;"><?= (int) $u['thread_count'] ?> / <?= (int) $u['comment_count'] ?></td>
                <td style="padding:8px; color:var(--text-faint);"><?= h(time_ago($u['created_at'])) ?></td>
                <td style="padding:8px;">
                    <form method="post" style="display:flex; gap:6px; align-items:center;">
                        <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
                        <input type="hidden" name="action" value="set_role">
                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                        <select name="role" style="padding:4px 8px; font-size:12.5px;">
                            <?php foreach (['user' => 'User', 'moderator' => 'Moderator', 'admin' => 'Admin'] as $rk => $rl): ?>
                                <option value="<?= $rk ?>" <?= ($u['role'] ?? 'user') === $rk ? 'selected' : '' ?>><?= $rl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm" style="padding:3px 10px;">Set</button>
                    </form>
                </td>
                <td style="padding:8px;">
                    <form method="post" style="display:inline;" onsubmit="return confirm('<?= $u['is_banned'] ? 'Unban' : 'Ban' ?> this user?');">
                        <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
                        <input type="hidden" name="action" value="<?= $u['is_banned'] ? 'unban_user' : 'ban_user' ?>">
                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" class="btn btn-sm" style="padding:3px 10px;"><?= $u['is_banned'] ? 'Unban' : 'Ban' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
