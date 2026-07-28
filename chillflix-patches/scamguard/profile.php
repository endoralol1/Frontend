<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/UserAuth.php';
require_once __DIR__ . '/includes/Auth.php';

UserAuth::start();
$db = Database::getConnection();
$isAdmin = Auth::check() || UserAuth::isModerator();
$viewerId = UserAuth::id();

$username = trim($_GET['u'] ?? '');
$stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    $pageTitle = 'User not found — ' . get_setting('site_name', 'ScamGuard');
    $robotsMeta = 'noindex,follow';
    require __DIR__ . '/includes/header.php';
    echo '<section class="section container"><div class="alert alert-error">This user does not exist.</div>
    <a href="' . h(BASE_PATH) . '/community.php" class="btn">&larr; Back to community</a></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$profileId = (int) $user['id'];
$isSelf = $viewerId === $profileId;

// ---- Report stats -----------------------------------------------------
$stmt = $db->prepare(
    "SELECT COUNT(*) AS total,
            SUM(review_status = 'approved') AS approved,
            SUM(review_status = 'rejected') AS rejected,
            SUM(review_status = 'pending') AS pending
     FROM forum_threads WHERE user_id = ?"
);
$stmt->execute([$profileId]);
$reportStats = $stmt->fetch() ?: ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0];

$stmt = $db->prepare('SELECT COUNT(*) FROM forum_comments WHERE user_id = ? AND is_deleted = 0');
$stmt->execute([$profileId]);
$commentCount = (int) $stmt->fetchColumn();

// ---- Feedback received (votes on their threads + comments) ------------
$stmt = $db->prepare(
    "SELECT
        COALESCE(SUM(v.vote = 1), 0) AS up,
        COALESCE(SUM(v.vote = -1), 0) AS down
     FROM forum_votes v
     WHERE (v.subject_type = 'thread'  AND v.subject_id IN (SELECT id FROM forum_threads  WHERE user_id = ?))
        OR (v.subject_type = 'comment' AND v.subject_id IN (SELECT id FROM forum_comments WHERE user_id = ? AND is_deleted = 0))"
);
$stmt->execute([$profileId, $profileId]);
$feedback = $stmt->fetch() ?: ['up' => 0, 'down' => 0];
$repScore = (int) $feedback['up'] - (int) $feedback['down'];

// ---- Their reports (hide rejected from strangers) ----------------------
$showAll = $isSelf || $isAdmin;
$stmt = $db->prepare(
    "SELECT id, subject_type, subject_value, category, title, review_status, is_sticky, is_locked, comment_count, created_at, last_activity_at
     FROM forum_threads
     WHERE user_id = ?" . ($showAll ? '' : " AND review_status <> 'rejected'") . "
     ORDER BY created_at DESC LIMIT 30"
);
$stmt->execute([$profileId]);
$threads = $stmt->fetchAll();

// Votes per thread for display
$threadVoteMap = forum_vote_counts($db, 'thread', array_map(static fn($t) => (int) $t['id'], $threads));

// ---- Their recent comments ---------------------------------------------
$stmt = $db->prepare(
    "SELECT c.id, c.body, c.created_at, c.thread_id, t.title, t.review_status
     FROM forum_comments c
     JOIN forum_threads t ON t.id = c.thread_id
     WHERE c.user_id = ? AND c.is_deleted = 0" . ($showAll ? '' : " AND t.review_status <> 'rejected'") . "
     ORDER BY c.created_at DESC LIMIT 20"
);
$stmt->execute([$profileId]);
$recentComments = $stmt->fetchAll();

$pageTitle = $user['username'] . ' — Community profile — ' . get_setting('site_name', 'ScamGuard');
$pageDescription = 'Community profile of ' . $user['username'] . ': scam reports, discussions, and feedback.';
$canonicalUrl = absolute_url('profile.php?u=' . rawurlencode((string) $user['username']));
require __DIR__ . '/includes/header.php';
?>

<section class="section container profile-page">
    <p class="thread-breadcrumb"><a href="<?= BASE_PATH ?>/community.php">&larr; Community reports</a></p>

    <div class="card profile-head">
        <div class="profile-avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr((string) $user['username'], 0, 1))) ?></div>
        <div class="profile-id">
            <h1 class="profile-name"><?= h($user['username']) ?>
                <?= role_chip($user['role'] ?? null) ?>
                <?php if ($user['is_banned']): ?><span class="badge badge-sm badge-scam">Banned</span><?php endif; ?>
                <?php if ($isSelf): ?><span class="badge badge-sm badge-unknown">You</span><?php endif; ?>
            </h1>
            <div class="profile-meta">
                Member since <?= h(date('M j, Y', strtotime((string) $user['created_at']))) ?>
                <?php if (!empty($user['last_login_at'])): ?> · Last active <?= h(time_ago($user['last_login_at'])) ?><?php endif; ?>
                <?php if ($isAdmin): ?> · <span class="profile-admin-info">Email: <?= h($user['email']) ?> (admin-only)</span><?php endif; ?>
            </div>
            <div class="profile-rep <?= $repScore > 0 ? 'is-positive' : ($repScore < 0 ? 'is-negative' : '') ?>">
                <span class="profile-rep-score"><?= $repScore > 0 ? '+' : '' ?><?= $repScore ?></span>
                <span class="profile-rep-label">community feedback</span>
                <span class="profile-rep-split">▲ <?= (int) $feedback['up'] ?> helpful · ▼ <?= (int) $feedback['down'] ?> not helpful</span>
            </div>
        </div>
        <?php if ($isAdmin): ?>
        <div class="profile-actions">
            <a class="btn btn-sm" href="<?= BASE_PATH ?>/admin/community.php" target="_blank">Moderate in admin</a>
        </div>
        <?php endif; ?>
    </div>

    <div class="profile-stats">
        <div class="stat">
            <span class="num"><?= (int) $reportStats['total'] ?></span>
            <span class="label">Reports</span>
        </div>
        <div class="stat">
            <span class="num num-safe"><?= (int) $reportStats['approved'] ?></span>
            <span class="label">Verified</span>
        </div>
        <div class="stat">
            <span class="num num-scam"><?= (int) $reportStats['rejected'] ?></span>
            <span class="label">Rejected</span>
        </div>
        <div class="stat">
            <span class="num"><?= (int) $reportStats['pending'] ?></span>
            <span class="label">Pending</span>
        </div>
        <div class="stat">
            <span class="num"><?= $commentCount ?></span>
            <span class="label">Comments</span>
        </div>
    </div>

    <h3 class="profile-section-title">Reports by <?= h($user['username']) ?></h3>
    <div class="card forum-card">
        <?php if (!$threads): ?>
            <p class="check-empty">No reports yet.</p>
        <?php else: ?>
            <ul class="forum-list">
                <?php foreach ($threads as $t):
                    $review = thread_review_badge((string) $t['review_status']);
                    $tv = $threadVoteMap[(int) $t['id']] ?? ['up' => 0, 'down' => 0];
                ?>
                <li class="forum-item">
                    <a class="forum-main" href="<?= BASE_PATH ?>/thread.php?id=<?= (int) $t['id'] ?>">
                        <div class="forum-title-row">
                            <?php if ($t['is_sticky']): ?><span class="forum-pin">📌</span><?php endif; ?>
                            <?php if ($t['is_locked']): ?><span class="forum-lock">🔒</span><?php endif; ?>
                            <span class="forum-title"><?= h($t['title']) ?></span>
                        </div>
                        <div class="forum-meta">
                            <span class="forum-chip forum-chip-type"><?= h(thread_subject_label((string) $t['subject_type'])) ?></span>
                            <?php if ($t['subject_type'] !== 'card'): ?>
                                <span class="forum-subject"><?= h($t['subject_value']) ?></span>
                            <?php endif; ?>
                            <span class="badge badge-sm <?= h($review['class']) ?>"><?= h($review['label']) ?></span>
                            <span class="forum-chip">▲ <?= (int) $tv['up'] ?> · ▼ <?= (int) $tv['down'] ?></span>
                        </div>
                    </a>
                    <div class="forum-side">
                        <span class="forum-replies">💬 <?= (int) $t['comment_count'] ?></span>
                        <span class="forum-when"><?= h(time_ago($t['created_at'])) ?></span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <h3 class="profile-section-title">Recent comments</h3>
    <div class="card forum-card" style="padding:6px 18px;">
        <?php if (!$recentComments): ?>
            <p class="check-empty">No comments yet.</p>
        <?php else: ?>
            <ul class="profile-comment-list">
                <?php foreach ($recentComments as $c): ?>
                <li class="profile-comment">
                    <div class="profile-comment-body">“<?= h(mb_substr(trim((string) $c['body']), 0, 220)) ?><?= mb_strlen(trim((string) $c['body'])) > 220 ? '…' : '' ?>”</div>
                    <div class="profile-comment-meta">
                        on <a href="<?= BASE_PATH ?>/thread.php?id=<?= (int) $c['thread_id'] ?>#comments"><?= h($c['title']) ?></a>
                        · <?= h(time_ago($c['created_at'])) ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
