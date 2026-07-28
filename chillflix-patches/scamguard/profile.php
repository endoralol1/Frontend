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
$rep = user_reputation($db, $profileId);
$weights = reputation_weights();
$points = (int) $rep['points'];

$showAll = $isSelf || $isAdmin;
$stmt = $db->prepare(
    "SELECT id, subject_type, subject_value, category, is_announcement, title, review_status, is_sticky, is_locked, comment_count, created_at, last_activity_at
     FROM forum_threads
     WHERE user_id = ? AND is_announcement = 0" . ($showAll ? '' : " AND review_status <> 'rejected'") . "
     ORDER BY created_at DESC LIMIT 30"
);
$stmt->execute([$profileId]);
$threads = $stmt->fetchAll();
$threadVoteMap = forum_vote_counts($db, 'thread', array_map(static fn($t) => (int) $t['id'], $threads));

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
$pageDescription = 'Community profile of ' . $user['username'] . ': reputation points, scam reports, and feedback.';
$canonicalUrl = absolute_url('profile.php?u=' . rawurlencode((string) $user['username']));
require __DIR__ . '/includes/header.php';
?>

<section class="section container profile-page">
    <p class="thread-breadcrumb"><a href="<?= BASE_PATH ?>/community.php">&larr; Community reports</a></p>

    <div class="card profile-head">
        <div class="profile-avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr((string) $user['username'], 0, 1))) ?></div>
        <div class="profile-id">
            <h1 class="profile-name">
                <?= h($user['username']) ?>
                <?= role_chip($user['role'] ?? null) ?>
                <?php if ($user['is_banned']): ?><span class="badge badge-sm badge-scam">Banned</span><?php endif; ?>
                <?php if ($isSelf): ?><span class="badge badge-sm badge-unknown">You</span><?php endif; ?>
            </h1>
            <div class="profile-meta">
                Member since <?= h(date('M j, Y', strtotime((string) $user['created_at']))) ?>
                <?php if (!empty($user['last_login_at'])): ?> · Last active <?= h(time_ago($user['last_login_at'])) ?><?php endif; ?>
                <?php if ($isAdmin): ?> · <span class="profile-admin-info"><?= h($user['email']) ?></span><?php endif; ?>
            </div>
            <div class="profile-rep-note">
                Verified +<?= (int) $weights['approve'] ?> · Rejected <?= (int) $weights['reject'] ?>
                · Feedback +<?= (int) $rep['positive'] ?>/−<?= (int) $rep['negative'] ?>
                <?php if ($isAdmin): ?> · <a href="<?= BASE_PATH ?>/admin/community.php" target="_blank">Moderate</a><?php endif; ?>
            </div>
        </div>
        <div class="profile-points <?= $points > 0 ? 'is-positive' : ($points < 0 ? 'is-negative' : '') ?>" title="Reputation points">
            <span class="profile-points-num"><?= $points > 0 ? '+' : '' ?><?= $points ?></span>
            <span class="profile-points-label">Points</span>
        </div>
    </div>

    <div class="profile-stats" role="list">
        <div class="stat" role="listitem"><span class="num"><?= (int) $rep['reports'] ?></span><span class="label">Reports</span></div>
        <div class="stat" role="listitem"><span class="num num-safe"><?= (int) $rep['approved'] ?></span><span class="label">Verified</span></div>
        <div class="stat" role="listitem"><span class="num num-scam"><?= (int) $rep['rejected'] ?></span><span class="label">Rejected</span></div>
        <div class="stat" role="listitem"><span class="num"><?= (int) $rep['pending'] ?></span><span class="label">Pending</span></div>
        <div class="stat" role="listitem"><span class="num"><?= (int) $rep['comments'] ?></span><span class="label">Comments</span></div>
        <div class="stat" role="listitem"><span class="num num-safe"><?= (int) $rep['positive'] ?></span><span class="label">Positive</span></div>
        <div class="stat" role="listitem"><span class="num num-scam"><?= (int) $rep['negative'] ?></span><span class="label">Negative</span></div>
    </div>

    <h3 class="profile-section-title">Reports</h3>
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
                    <div class="forum-body">
                        <a class="forum-main" href="<?= BASE_PATH ?>/thread.php?id=<?= (int) $t['id'] ?>">
                            <div class="forum-title-row">
                                <?php if ($t['is_sticky']): ?><span class="forum-flag">Pinned</span><?php endif; ?>
                                <?php if ($t['is_locked']): ?><span class="forum-flag">Locked</span><?php endif; ?>
                                <span class="forum-title"><?= h($t['title']) ?></span>
                            </div>
                            <div class="forum-meta">
                                <?php if ($t['subject_type'] !== 'card'): ?>
                                    <span class="forum-subject"><?= h($t['subject_value']) ?></span>
                                    <span class="forum-sep" aria-hidden="true">·</span>
                                <?php endif; ?>
                                <span><?= h(report_category_label((string) $t['category'])) ?></span>
                                <span class="forum-sep" aria-hidden="true">·</span>
                                <span><?= h(thread_subject_label((string) $t['subject_type'])) ?></span>
                                <span class="forum-sep" aria-hidden="true">·</span>
                                <span>+<?= (int) $tv['up'] ?> / −<?= (int) $tv['down'] ?></span>
                            </div>
                        </a>
                        <div class="forum-foot">
                            <span class="forum-status <?= h($review['class']) ?>"><?= h($review['label']) ?></span>
                            <span class="forum-sep" aria-hidden="true">·</span>
                            <span><?= h(time_ago($t['created_at'])) ?></span>
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
