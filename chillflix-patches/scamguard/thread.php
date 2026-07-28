<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/UserAuth.php';
require_once __DIR__ . '/includes/Auth.php';

UserAuth::start();
$db = Database::getConnection();

$threadId = (int) ($_GET['id'] ?? 0);
$userId = UserAuth::id();
// Moderation works with an admin-panel session OR a community account
// holding the moderator/admin role — one login for everything.
$isAdmin = Auth::check() || UserAuth::isModerator();
$modDetails = Auth::id() === null ? 'via community account ' . (UserAuth::username() ?? '?') : null;
$flash = null;
$error = null;

function load_thread(PDO $db, int $id): ?array
{
    $stmt = $db->prepare(
        'SELECT t.*, u.username, u.is_banned AS author_banned, u.role AS author_role
         FROM forum_threads t JOIN users u ON u.id = t.user_id
         WHERE t.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$thread = load_thread($db, $threadId);
if (!$thread) {
    http_response_code(404);
    $pageTitle = 'Thread not found — ' . get_setting('site_name', 'ScamGuard');
    $robotsMeta = 'noindex,follow';
    require __DIR__ . '/includes/header.php';
    echo '<section class="section container"><div class="alert alert-error">This discussion does not exist or was removed.</div>
    <a href="' . h(BASE_PATH) . '/community.php" class="btn">&larr; Back to community</a></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$isOwner = $userId !== null && $userId === (int) $thread['user_id'];

// Rejected threads: only the author and admins can still open them.
if ($thread['review_status'] === 'rejected' && !$isOwner && !$isAdmin) {
    http_response_code(404);
    $pageTitle = 'Thread not available — ' . get_setting('site_name', 'ScamGuard');
    $robotsMeta = 'noindex,follow';
    require __DIR__ . '/includes/header.php';
    echo '<section class="section container"><div class="alert alert-error">This report was reviewed and is no longer public.</div>
    <a href="' . h(BASE_PATH) . '/community.php" class="btn">&larr; Back to community</a></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!UserAuth::verifyCsrf($_POST['csrf'] ?? null)) {
        $error = 'Session expired — please try again.';
    } elseif ($action === 'comment') {
        if (!$userId) {
            redirect('/login.php?next=' . rawurlencode('/thread.php?id=' . $threadId));
        }
        $body = trim($_POST['body'] ?? '');
        if ($thread['is_locked']) {
            $error = 'This discussion is locked.';
        } elseif (!$thread['comments_open'] && !$isOwner && !$isAdmin) {
            $error = 'The reporter closed this discussion to other users.';
        } elseif (mb_strlen($body) < 2) {
            $error = 'Write something first.';
        } elseif (mb_strlen($body) > 4000) {
            $error = 'Comment is too long (max 4000 characters).';
        } else {
            $rate = UserAuth::canPostComment($userId);
            if (!$rate['ok']) {
                $error = $rate['error'];
            } else {
                $db->prepare('INSERT INTO forum_comments (thread_id, user_id, body) VALUES (?, ?, ?)')
                   ->execute([$threadId, $userId, $body]);
                $db->prepare('UPDATE forum_threads SET comment_count = comment_count + 1, last_activity_at = NOW() WHERE id = ?')
                   ->execute([$threadId]);
                redirect('/thread.php?id=' . $threadId . '#comments');
            }
        }
    } elseif ($action === 'toggle_comments' && $isOwner) {
        $db->prepare('UPDATE forum_threads SET comments_open = IF(comments_open = 1, 0, 1) WHERE id = ?')
           ->execute([$threadId]);
        redirect('/thread.php?id=' . $threadId);
    } elseif ($action === 'vote') {
        if (!$userId) {
            redirect('/login.php?next=' . rawurlencode('/thread.php?id=' . $threadId));
        }
        $voteErr = forum_cast_vote(
            $db,
            $userId,
            (string) ($_POST['subject'] ?? ''),
            (int) ($_POST['subject_id'] ?? 0),
            ($_POST['dir'] ?? '') === 'up' ? 1 : -1
        );
        if ($voteErr !== null) {
            $error = $voteErr;
        } else {
            $anchor = ($_POST['subject'] ?? '') === 'comment' ? '#comments' : '';
            redirect('/thread.php?id=' . $threadId . $anchor);
        }
    } elseif ($isAdmin && in_array($action, ['approve', 'reject', 'sticky', 'unsticky', 'lock', 'unlock', 'delete_thread', 'delete_comment'], true)) {
        if ($action === 'approve' || $action === 'reject') {
            $status = $action === 'approve' ? 'approved' : 'rejected';
            $db->prepare('UPDATE forum_threads SET review_status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
               ->execute([$status, Auth::id() ?? $userId, $threadId]);
            if (!empty($thread['report_id'])) {
                $db->prepare('UPDATE reports SET status = ?, admin_id = ?, reviewed_at = NOW() WHERE id = ?')
                   ->execute([$status, Auth::id(), $thread['report_id']]);
            }
            if (!empty($thread['entity_report_id'])) {
                $db->prepare('UPDATE entity_reports SET status = ?, admin_id = ?, reviewed_at = NOW() WHERE id = ?')
                   ->execute([$status, Auth::id(), $thread['entity_report_id']]);
            }
            log_admin_activity(Auth::id(), 'forum_' . $action, 'thread:' . $threadId, $modDetails);
        } elseif ($action === 'sticky' || $action === 'unsticky') {
            $db->prepare('UPDATE forum_threads SET is_sticky = ? WHERE id = ?')
               ->execute([$action === 'sticky' ? 1 : 0, $threadId]);
            log_admin_activity(Auth::id(), 'forum_' . $action, 'thread:' . $threadId, $modDetails);
        } elseif ($action === 'lock' || $action === 'unlock') {
            $db->prepare('UPDATE forum_threads SET is_locked = ? WHERE id = ?')
               ->execute([$action === 'lock' ? 1 : 0, $threadId]);
            log_admin_activity(Auth::id(), 'forum_' . $action, 'thread:' . $threadId, $modDetails);
        } elseif ($action === 'delete_thread') {
            $db->prepare('DELETE FROM forum_threads WHERE id = ?')->execute([$threadId]);
            log_admin_activity(Auth::id(), 'forum_delete_thread', 'thread:' . $threadId, $modDetails);
            redirect('/community.php');
        } elseif ($action === 'delete_comment') {
            $commentId = (int) ($_POST['comment_id'] ?? 0);
            $db->prepare('UPDATE forum_comments SET is_deleted = 1 WHERE id = ? AND thread_id = ?')
               ->execute([$commentId, $threadId]);
            $db->prepare('UPDATE forum_threads SET comment_count = GREATEST(comment_count - 1, 0) WHERE id = ?')
               ->execute([$threadId]);
            log_admin_activity(Auth::id(), 'forum_delete_comment', 'comment:' . $commentId, $modDetails);
        }
        $thread = load_thread($db, $threadId);
        if (!$thread) {
            redirect('/community.php');
        }
    }
    // Reload after any state change so the page reflects it.
    $thread = load_thread($db, $threadId) ?? $thread;
    $isOwner = $userId !== null && $userId === (int) $thread['user_id'];
}

$stmt = $db->prepare(
    'SELECT c.*, u.username, u.role AS author_role FROM forum_comments c
     JOIN users u ON u.id = c.user_id
     WHERE c.thread_id = ? ORDER BY c.created_at ASC LIMIT 500'
);
$stmt->execute([$threadId]);
$comments = $stmt->fetchAll();

// Linked domain snapshot for website reports.
$domainRow = null;
if ($thread['subject_type'] === 'website') {
    $stmt = $db->prepare('SELECT domain, trust_score, status FROM domains WHERE domain = ?');
    $stmt->execute([$thread['subject_value']]);
    $domainRow = $stmt->fetch() ?: null;
}

$review = thread_review_badge((string) $thread['review_status']);
$canComment = !$thread['is_locked'] && ($thread['comments_open'] || $isOwner || $isAdmin);

// Feedback votes for the thread and its comments.
$threadVotes = forum_vote_counts($db, 'thread', [$threadId])[$threadId] ?? ['up' => 0, 'down' => 0];
$myThreadVote = forum_user_votes($db, $userId, 'thread', [$threadId])[$threadId] ?? 0;
$commentIds = array_map(static fn($c) => (int) $c['id'], $comments);
$commentVotes = forum_vote_counts($db, 'comment', $commentIds);
$myCommentVotes = forum_user_votes($db, $userId, 'comment', $commentIds);

/** Compact up/down vote widget */
function render_vote_widget(string $subject, int $subjectId, array $counts, int $myVote, bool $small = false): void
{
    ?>
    <div class="vote-widget <?= $small ? 'vote-widget-sm' : '' ?>">
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= h(UserAuth::csrfToken()) ?>">
            <input type="hidden" name="action" value="vote">
            <input type="hidden" name="subject" value="<?= h($subject) ?>">
            <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
            <input type="hidden" name="dir" value="up">
            <button type="submit" class="vote-btn vote-up <?= $myVote === 1 ? 'is-active' : '' ?>" title="Helpful">
                ▲ <span><?= (int) ($counts['up'] ?? 0) ?></span>
            </button>
        </form>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= h(UserAuth::csrfToken()) ?>">
            <input type="hidden" name="action" value="vote">
            <input type="hidden" name="subject" value="<?= h($subject) ?>">
            <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
            <input type="hidden" name="dir" value="down">
            <button type="submit" class="vote-btn vote-down <?= $myVote === -1 ? 'is-active' : '' ?>" title="Not helpful">
                ▼ <span><?= (int) ($counts['down'] ?? 0) ?></span>
            </button>
        </form>
    </div>
    <?php
}

$pageTitle = $thread['title'] . ' — Community — ' . get_setting('site_name', 'ScamGuard');
$pageDescription = mb_substr(trim(preg_replace('/\s+/', ' ', (string) $thread['body'])), 0, 155);
$canonicalUrl = absolute_url('thread.php?id=' . $threadId);
$robotsMeta = $thread['review_status'] === 'rejected' ? 'noindex,follow' : 'index,follow';
require __DIR__ . '/includes/header.php';
?>

<section class="section container thread-page">
    <p class="thread-breadcrumb"><a href="<?= BASE_PATH ?>/community.php">&larr; Community reports</a></p>

    <?php if (isset($_GET['new'])): ?>
        <div class="alert alert-success">Your report is live. Admins will review it — you can keep discussing below.</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <article class="card thread-card">
        <div class="thread-kicker">
            <span class="forum-status <?= h($review['class']) ?>"><?= h($review['label']) ?></span>
            <?php if ($thread['is_sticky']): ?><span class="forum-flag">Pinned</span><?php endif; ?>
            <?php if ($thread['is_locked']): ?><span class="forum-flag">Locked</span><?php endif; ?>
            <span class="forum-sep" aria-hidden="true">·</span>
            <span><?= h(report_category_label((string) $thread['category'])) ?></span>
            <span class="forum-sep" aria-hidden="true">·</span>
            <span><?= h(thread_subject_label((string) $thread['subject_type'])) ?></span>
        </div>

        <h1 class="thread-title"><?= h($thread['title']) ?></h1>
        <div class="thread-byline">
            Reported by <a class="user-link" href="<?= h(profile_path((string) $thread['username'])) ?>"><strong><?= h($thread['username']) ?></strong></a><?= role_chip($thread['author_role'] ?? null) ?> · <?= h(time_ago($thread['created_at'])) ?>
        </div>

        <?php if ($thread['subject_type'] !== 'card'): ?>
        <div class="thread-subject-card">
            <div class="thread-subject-main">
                <span class="thread-subject-label">Reported <?= h(strtolower(thread_subject_label((string) $thread['subject_type']))) ?></span>
                <span class="thread-subject-value"><?= h($thread['subject_value']) ?></span>
                <?php if ($domainRow): $b = status_badge((string) $domainRow['status']); ?>
                    <span class="badge badge-sm <?= h($b['class']) ?>"><?= h($b['label']) ?> · <?= (int) $domainRow['trust_score'] ?>/100</span>
                <?php endif; ?>
            </div>
            <?php if ($thread['subject_type'] === 'website'): ?>
                <a class="btn btn-sm" href="<?= h(domain_page_path((string) $thread['subject_value'])) ?>">View scan report</a>
            <?php else: ?>
                <a class="btn btn-sm" href="<?= BASE_PATH ?>/check-entity.php?type=<?= h($thread['subject_type']) ?>&q=<?= rawurlencode((string) $thread['subject_value']) ?>">Run a check</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="thread-body"><?= nl2br(h($thread['body'])) ?></div>

        <div class="thread-feedback">
            <span class="thread-feedback-label">Was this report helpful?</span>
            <?php render_vote_widget('thread', $threadId, $threadVotes, $myThreadVote); ?>
            <?php if (!$userId): ?>
                <a class="thread-feedback-signin" href="<?= BASE_PATH ?>/login.php?next=<?= rawurlencode('/thread.php?id=' . $threadId) ?>">Sign in to vote</a>
            <?php endif; ?>
        </div>

        <?php if ($isOwner || $isAdmin): ?>
        <div class="thread-controls">
            <?php if ($isOwner): ?>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= h(UserAuth::csrfToken()) ?>">
                <input type="hidden" name="action" value="toggle_comments">
                <button type="submit" class="btn btn-sm"><?= $thread['comments_open'] ? 'Close discussion to others' : 'Open discussion to everyone' ?></button>
            </form>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
                <span class="thread-admin-label">Moderation:</span>
                <?php
                $adminActions = [];
                $adminActions[] = $thread['review_status'] !== 'approved' ? ['approve', '✓ Approve'] : null;
                $adminActions[] = $thread['review_status'] !== 'rejected' ? ['reject', '✕ Reject'] : null;
                $adminActions[] = $thread['is_sticky'] ? ['unsticky', 'Unpin'] : ['sticky', '📌 Pin'];
                $adminActions[] = $thread['is_locked'] ? ['unlock', 'Unlock'] : ['lock', '🔒 Lock'];
                $adminActions[] = ['delete_thread', 'Delete'];
                foreach (array_filter($adminActions) as [$act, $label]):
                ?>
                <form method="post" style="display:inline;" <?= $act === 'delete_thread' ? 'onsubmit="return confirm(\'Delete this thread permanently?\');"' : '' ?>>
                    <input type="hidden" name="csrf" value="<?= h(UserAuth::csrfToken()) ?>">
                    <input type="hidden" name="action" value="<?= h($act) ?>">
                    <button type="submit" class="btn btn-sm <?= $act === 'delete_thread' ? 'btn-danger' : '' ?>"><?= h($label) ?></button>
                </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </article>

    <div class="thread-comments" id="comments">
        <h3 class="thread-comments-title">Discussion <span class="thread-comments-count"><?= count(array_filter($comments, static fn($c) => !$c['is_deleted'])) ?></span></h3>

        <?php if ($thread['is_locked']): ?>
            <div class="alert alert-info">🔒 This discussion has been locked by admins — no new comments.</div>
        <?php elseif (!$thread['comments_open']): ?>
            <div class="alert alert-info">The reporter closed this discussion — only they<?= $isAdmin ? ' and admins' : '' ?> can comment.</div>
        <?php endif; ?>

        <?php if (!$comments): ?>
            <p class="check-empty" style="padding:14px 0;">No comments yet<?= $canComment ? ' — start the discussion below' : '' ?>.</p>
        <?php else: ?>
            <ul class="comment-list">
                <?php foreach ($comments as $c): ?>
                <li class="comment-item <?= $c['is_deleted'] ? 'is-deleted' : '' ?>">
                    <div class="comment-avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr((string) $c['username'], 0, 1))) ?></div>
                    <div class="comment-content">
                        <div class="comment-head">
                            <a class="comment-author user-link" href="<?= h(profile_path((string) $c['username'])) ?>"><?= h($c['username']) ?></a>
                            <?= role_chip($c['author_role'] ?? null) ?>
                            <?php if ((int) $c['user_id'] === (int) $thread['user_id']): ?><span class="comment-op">Reporter</span><?php endif; ?>
                            <span class="comment-when"><?= h(time_ago($c['created_at'])) ?></span>
                            <?php if ($isAdmin && !$c['is_deleted']): ?>
                            <form method="post" class="comment-delete" onsubmit="return confirm('Remove this comment?');">
                                <input type="hidden" name="csrf" value="<?= h(UserAuth::csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_comment">
                                <input type="hidden" name="comment_id" value="<?= (int) $c['id'] ?>">
                                <button type="submit" title="Remove comment">✕</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <div class="comment-body">
                            <?= $c['is_deleted'] ? '<em style="color:var(--faint);">Comment removed by moderators.</em>' : nl2br(h($c['body'])) ?>
                        </div>
                        <?php if (!$c['is_deleted']): ?>
                            <div class="comment-votes">
                                <?php render_vote_widget('comment', (int) $c['id'], $commentVotes[(int) $c['id']] ?? ['up' => 0, 'down' => 0], $myCommentVotes[(int) $c['id']] ?? 0, true); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!$thread['is_locked'] && $canComment): ?>
            <?php if ($userId): ?>
            <form method="post" class="card comment-form">
                <input type="hidden" name="csrf" value="<?= h(UserAuth::csrfToken()) ?>">
                <input type="hidden" name="action" value="comment">
                <div class="field" style="margin-bottom:10px;">
                    <label>Add to the discussion as <strong><?= h(UserAuth::username()) ?></strong></label>
                    <textarea name="body" rows="3" placeholder="Share what you know about this…" required minlength="2" maxlength="4000"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Post comment</button>
            </form>
            <?php else: ?>
            <div class="card auth-gate auth-gate-inline">
                <p style="margin:0 0 12px; color:var(--muted);">Sign in to join this discussion.</p>
                <div class="auth-gate-actions">
                    <a class="btn btn-primary btn-sm" href="<?= BASE_PATH ?>/login.php?next=<?= rawurlencode('/thread.php?id=' . $threadId) ?>">Sign in</a>
                    <a class="btn btn-sm" href="<?= BASE_PATH ?>/register.php?next=<?= rawurlencode('/thread.php?id=' . $threadId) ?>">Create account</a>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
