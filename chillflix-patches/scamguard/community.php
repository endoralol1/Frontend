<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/UserAuth.php';

UserAuth::start();
$db = Database::getConnection();

$q = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'verified', 'pending', 'mine'], true)) {
    $filter = 'all';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$userId = UserAuth::id();

$where = [];
$params = [];

// Rejected threads stay visible only to their author.
if ($userId) {
    $where[] = "(t.review_status <> 'rejected' OR t.user_id = ?)";
    $params[] = $userId;
} else {
    $where[] = "t.review_status <> 'rejected'";
}

if ($filter === 'verified') {
    $where[] = "t.review_status = 'approved'";
} elseif ($filter === 'pending') {
    $where[] = "t.review_status = 'pending'";
} elseif ($filter === 'mine') {
    if (!$userId) {
        redirect('/login.php?next=' . rawurlencode('/community.php?filter=mine'));
    }
    $where[] = 't.user_id = ?';
    $params[] = $userId;
}

if ($q !== '') {
    $where[] = '(t.title LIKE ? OR t.subject_value LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int) (function () use ($db, $whereSql, $params) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM forum_threads t $whereSql");
    $stmt->execute($params);
    return $stmt->fetchColumn();
})();

$stmt = $db->prepare(
    "SELECT t.*, u.username
     FROM forum_threads t
     JOIN users u ON u.id = t.user_id
     $whereSql
     ORDER BY t.is_sticky DESC, t.last_activity_at DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$threads = $stmt->fetchAll();

$pages = max(1, (int) ceil($total / $perPage));

$pageTitle = 'Community reports — ' . get_setting('site_name', 'ScamGuard');
$pageDescription = 'Scam reports and discussions from the community. Read verified reports or share your own experience.';
$canonicalUrl = absolute_url('community.php');
require __DIR__ . '/includes/header.php';
?>

<section class="section container forum-page">
    <div class="forum-head">
        <div>
            <h2 class="section-title" style="margin:0;">Community reports</h2>
            <p style="color:var(--muted); margin:6px 0 0; font-size:14px;">Real reports from real users — verified by admins.</p>
        </div>
        <a class="btn btn-primary" href="<?= BASE_PATH ?>/report.php">+ New report</a>
    </div>

    <form class="forum-toolbar" method="get" action="<?= BASE_PATH ?>/community.php">
        <div class="forum-tabs">
            <?php
            $tabs = ['all' => 'All', 'verified' => 'Verified', 'pending' => 'Pending'];
            if ($userId) {
                $tabs['mine'] = 'My reports';
            }
            foreach ($tabs as $key => $label):
                $url = BASE_PATH . '/community.php?filter=' . $key . ($q !== '' ? '&q=' . rawurlencode($q) : '');
            ?>
                <a class="forum-tab <?= $filter === $key ? 'is-active' : '' ?>" href="<?= h($url) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="forum-search">
            <input type="hidden" name="filter" value="<?= h($filter) ?>">
            <input type="search" name="q" placeholder="Search reports…" value="<?= h($q) ?>">
            <button type="submit" class="btn btn-sm">Search</button>
        </div>
    </form>

    <div class="card forum-card">
        <?php if (!$threads): ?>
            <p class="check-empty">No reports here yet<?= $q !== '' ? ' matching your search' : '' ?>.
                <a href="<?= BASE_PATH ?>/report.php" style="color:var(--brand-2);">Be the first to report a scam</a>.</p>
        <?php else: ?>
            <ul class="forum-list">
                <?php foreach ($threads as $t):
                    $review = thread_review_badge((string) $t['review_status']);
                ?>
                <li class="forum-item <?= $t['is_sticky'] ? 'is-sticky' : '' ?>">
                    <a class="forum-main" href="<?= BASE_PATH ?>/thread.php?id=<?= (int) $t['id'] ?>">
                        <div class="forum-title-row">
                            <?php if ($t['is_sticky']): ?><span class="forum-pin" title="Pinned">📌</span><?php endif; ?>
                            <?php if ($t['is_locked']): ?><span class="forum-lock" title="Locked">🔒</span><?php endif; ?>
                            <span class="forum-title"><?= h($t['title']) ?></span>
                        </div>
                        <div class="forum-meta">
                            <span class="forum-chip forum-chip-type"><?= h(thread_subject_label((string) $t['subject_type'])) ?></span>
                            <?php if ($t['subject_type'] !== 'card'): ?>
                                <span class="forum-subject"><?= h($t['subject_value']) ?></span>
                            <?php endif; ?>
                            <span class="forum-chip"><?= h(report_category_label((string) $t['category'])) ?></span>
                            <span class="badge badge-sm <?= h($review['class']) ?>"><?= h($review['label']) ?></span>
                        </div>
                    </a>
                    <div class="forum-side">
                        <span class="forum-replies" title="Replies">💬 <?= (int) $t['comment_count'] ?></span>
                        <span class="forum-when">by <a class="user-link" href="<?= h(profile_path((string) $t['username'])) ?>"><?= h($t['username']) ?></a> · <?= h(time_ago($t['last_activity_at'])) ?></span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
    <div class="forum-pager">
        <?php for ($p = 1; $p <= min($pages, 12); $p++):
            $url = BASE_PATH . '/community.php?filter=' . $filter . '&page=' . $p . ($q !== '' ? '&q=' . rawurlencode($q) : '');
        ?>
            <a class="forum-page-link <?= $p === $page ? 'is-active' : '' ?>" href="<?= h($url) ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
