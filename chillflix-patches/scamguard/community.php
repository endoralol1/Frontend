<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/UserAuth.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/DomainRepository.php';
require_once __DIR__ . '/includes/PhoneChecker.php';
require_once __DIR__ . '/includes/CryptoChecker.php';
require_once __DIR__ . '/includes/IbanChecker.php';
require_once __DIR__ . '/includes/CardChecker.php';

UserAuth::start();
$db = Database::getConnection();
$canAnnounce = can_post_announcement();

// ---- Inline report / announcement composer ----------------------------
$composeError = null;
$composeMode = ($_POST['post_mode'] ?? $_GET['mode'] ?? 'report') === 'announcement' ? 'announcement' : 'report';
if ($composeMode === 'announcement' && !$canAnnounce) {
    $composeMode = 'report';
}
$composeType = strtolower(trim($_GET['type'] ?? $_POST['type'] ?? 'website'));
if (!in_array($composeType, ['website', 'phone', 'crypto', 'iban', 'card'], true)) {
    $composeType = 'website';
}
$composePrefill = trim($_GET['q_prefill'] ?? $_GET['d'] ?? '');
$composeOpen = isset($_GET['compose']) || $composePrefill !== '' || isset($_GET['mode']);
$selfComposeUrl = '/community.php?compose=1'
    . ($composeMode === 'announcement' ? '&mode=announcement' : '')
    . ($composePrefill !== '' ? '&type=' . rawurlencode($composeType) . '&q_prefill=' . rawurlencode($composePrefill) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && UserAuth::check()) {
    $action = $_POST['action'] ?? '';
    $composeOpen = true;
    $userId = (int) UserAuth::id();

    if ($action === 'create_announcement') {
        $composeMode = 'announcement';
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['description'] ?? '');
        $commentsOpen = isset($_POST['comments_open']) ? 1 : 0;
        $pin = isset($_POST['is_sticky']) ? 1 : 0;

        if (!$canAnnounce) {
            $composeError = 'Only admins can post announcements.';
        } elseif (!UserAuth::verifyCsrf($_POST['csrf'] ?? null)) {
            $composeError = 'Session expired — please submit again.';
        } elseif (mb_strlen($title) < 5 || mb_strlen($title) > 150) {
            $composeError = 'Announcement title must be 5–150 characters.';
        } elseif (mb_strlen($body) < 10) {
            $composeError = 'Write a bit more for the announcement (at least 10 characters).';
        } elseif (mb_strlen($body) > 8000) {
            $composeError = 'Announcement is too long (max 8000 characters).';
        } else {
            $stmt = $db->prepare(
                'INSERT INTO forum_threads
                    (user_id, subject_type, subject_value, category, is_announcement, title, body,
                     comments_open, is_sticky, review_status, reviewed_by, reviewed_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $userId,
                'announcement',
                'announcement',
                'announcement',
                $title,
                $body,
                $commentsOpen,
                $pin,
                'approved',
                Auth::id() ?? $userId,
            ]);
            $threadId = (int) $db->lastInsertId();
            log_admin_activity(Auth::id(), 'forum_announcement', 'thread:' . $threadId, UserAuth::username());
            redirect('/thread.php?id=' . $threadId . '&new=1');
        }
    } elseif ($action === 'create_report') {
        $composeMode = 'report';
        $composeType = strtolower(trim($_POST['type'] ?? 'website'));
        $input = trim($_POST['entity'] ?? '');
        $category = $_POST['category'] ?? 'other';
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['description'] ?? '');
        $commentsOpen = isset($_POST['comments_open']) ? 1 : 0;

        if (!UserAuth::verifyCsrf($_POST['csrf'] ?? null)) {
            $composeError = 'Session expired — please submit again.';
        } elseif (!in_array($composeType, ['website', 'phone', 'crypto', 'iban', 'card'], true)) {
            $composeError = 'Please choose what you are reporting.';
        } elseif (!array_key_exists($category, report_categories())) {
            $composeError = 'Please choose a valid category.';
        } elseif (mb_strlen($title) < 8 || mb_strlen($title) > 150) {
            $composeError = 'Give your report a short title (8–150 characters).';
        } elseif (mb_strlen($body) < 20) {
            $composeError = 'Describe what happened in at least 20 characters — it helps others and our reviewers.';
        } elseif (mb_strlen($body) > 8000) {
            $composeError = 'Description is too long (max 8000 characters).';
        } else {
            $rate = UserAuth::canPostThread($userId);
            if (!$rate['ok']) {
                $composeError = $rate['error'];
            }
        }

        if ($composeError === null) {
            if ($composeType === 'website') {
                $normalized = normalize_domain($input);
            } else {
                $normalized = match ($composeType) {
                    'phone' => PhoneChecker::normalize($input),
                    'crypto' => CryptoChecker::normalize($input),
                    'iban' => IbanChecker::normalize($input),
                    'card' => CardChecker::normalize($input),
                    default => null,
                };
                if ($composeType === 'card' && $normalized) {
                    $normalized = 'card:' . hash('sha256', $normalized);
                }
            }

            if (!$normalized) {
                $composeError = $composeType === 'website'
                    ? 'Please enter a valid domain (e.g. example.com).'
                    : 'Please enter a valid ' . $composeType . '.';
            } else {
                $ipHash = UserAuth::ipHash();
                $stmt = $db->prepare('SELECT email FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $userEmail = (string) $stmt->fetchColumn();

                $reportId = null;
                $entityReportId = null;
                $domainId = null;

                if ($composeType === 'website') {
                    $repo = new DomainRepository();
                    $existing = $repo->find($normalized);
                    $domainId = $existing['id'] ?? null;
                    $reportCategory = in_array($category, ['phishing','fake_shop','crypto_scam','tech_support_scam','identity_theft','other'], true) ? $category : 'other';
                    $stmt = $db->prepare('INSERT INTO reports (domain_id, domain_text, reporter_email, category, description, ip_hash) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$domainId, $normalized, $userEmail, $reportCategory, $body, $ipHash]);
                    $reportId = (int) $db->lastInsertId();
                } else {
                    $stmt = $db->prepare('INSERT INTO entity_reports (entity_type, entity_value, reporter_email, category, description, ip_hash) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$composeType, $normalized, $userEmail, $category, $body, $ipHash]);
                    $entityReportId = (int) $db->lastInsertId();
                }

                $stmt = $db->prepare(
                    'INSERT INTO forum_threads
                        (user_id, subject_type, subject_value, domain_id, report_id, entity_report_id, category, is_announcement, title, body, comments_open)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
                );
                $stmt->execute([
                    $userId, $composeType, $normalized, $domainId, $reportId, $entityReportId,
                    $category, $title, $body, $commentsOpen,
                ]);
                redirect('/thread.php?id=' . (int) $db->lastInsertId() . '&new=1');
            }
        }
    }
}

$q = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'verified', 'pending', 'mine', 'announcements'], true)) {
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
    $where[] = "t.review_status = 'approved' AND t.is_announcement = 0";
} elseif ($filter === 'pending') {
    $where[] = "t.review_status = 'pending' AND t.is_announcement = 0";
} elseif ($filter === 'announcements') {
    $where[] = 't.is_announcement = 1';
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
            <h2 class="section-title" style="margin:0;">Community</h2>
            <p style="color:var(--muted); margin:6px 0 0; font-size:14px;">Reports from users and announcements from admins.</p>
        </div>
        <button type="button" class="btn btn-primary" id="composer-toggle" aria-haspopup="dialog" aria-controls="composer-modal">+ New post</button>
    </div>

    <form class="forum-toolbar" method="get" action="<?= BASE_PATH ?>/community.php">
        <div class="forum-tabs">
            <?php
            $tabs = ['all' => 'All', 'announcements' => 'Announcements', 'verified' => 'Verified', 'pending' => 'Pending'];
            if ($userId) {
                $tabs['mine'] = 'Mine';
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
                <a href="<?= BASE_PATH ?>/community.php?compose=1" style="color:var(--brand-2);">Be the first to report a scam</a>.</p>
        <?php else: ?>
            <ul class="forum-list">
                <?php foreach ($threads as $t):
                    $isAnnounce = !empty($t['is_announcement']);
                    $review = thread_review_badge((string) $t['review_status']);
                ?>
                <li class="forum-item <?= $t['is_sticky'] ? 'is-sticky' : '' ?> <?= $isAnnounce ? 'is-announcement' : '' ?>">
                    <div class="forum-body">
                        <a class="forum-main" href="<?= BASE_PATH ?>/thread.php?id=<?= (int) $t['id'] ?>">
                            <div class="forum-title-row">
                                <?php if ($isAnnounce || $t['is_sticky'] || $t['is_locked']): ?>
                                <div class="forum-flags">
                                    <?php if ($isAnnounce): ?><span class="forum-flag forum-flag-announce">Announcement</span><?php endif; ?>
                                    <?php if ($t['is_sticky']): ?><span class="forum-flag">Pinned</span><?php endif; ?>
                                    <?php if ($t['is_locked']): ?><span class="forum-flag">Locked</span><?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <span class="forum-title"><?= h($t['title']) ?></span>
                            </div>
                            <div class="forum-meta">
                                <?php if ($isAnnounce): ?>
                                    <span>Official update</span>
                                <?php else: ?>
                                    <?php if ($t['subject_type'] !== 'card'): ?>
                                        <span class="forum-subject"><?= h($t['subject_value']) ?></span>
                                        <span class="forum-sep" aria-hidden="true">·</span>
                                    <?php endif; ?>
                                    <span><?= h(report_category_label((string) $t['category'])) ?></span>
                                    <span class="forum-sep" aria-hidden="true">·</span>
                                    <span><?= h(thread_subject_label((string) $t['subject_type'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <div class="forum-foot">
                            <?php if ($isAnnounce): ?>
                                <span class="forum-status status-verified">Official</span>
                            <?php else: ?>
                                <span class="forum-status <?= h($review['class']) ?>"><?= h($review['label']) ?></span>
                            <?php endif; ?>
                            <span class="forum-sep" aria-hidden="true">·</span>
                            <a class="user-link" href="<?= h(profile_path((string) $t['username'])) ?>"><?= h($t['username']) ?></a>
                            <span class="forum-sep" aria-hidden="true">·</span>
                            <span><?= h(time_ago($t['last_activity_at'])) ?></span>
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

    <?php /* pager below list */ ?>
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

<div class="composer-modal <?= $composeOpen ? 'is-open' : '' ?>" id="composer-modal" role="dialog" aria-modal="true" aria-labelledby="composer-title" aria-hidden="<?= $composeOpen ? 'false' : 'true' ?>">
    <div class="composer-backdrop" data-composer-close></div>
    <div class="composer-dialog card">
        <div class="composer-dialog-head">
            <div>
                <h3 id="composer-title" class="composer-dialog-title"><?= $composeMode === 'announcement' ? 'New announcement' : 'New report' ?></h3>
                <p class="composer-dialog-sub" id="composer-sub"><?= $composeMode === 'announcement'
                    ? 'Admin-only official post — visible to everyone in the community.'
                    : 'Opens a public discussion — verified by admins.' ?></p>
            </div>
            <button type="button" class="composer-close" data-composer-close aria-label="Close">✕</button>
        </div>

        <?php if (!UserAuth::check()): ?>
            <div class="auth-gate auth-gate-inline" style="margin:0; border:0; padding:8px 0 4px; background:transparent;">
                <p style="margin:0 0 14px; color:var(--muted);"><strong>Sign in to report.</strong> Reports need a free account so the community stays spam-free.</p>
                <div class="auth-gate-actions">
                    <a class="btn btn-primary" href="<?= BASE_PATH ?>/login.php?next=<?= rawurlencode($selfComposeUrl) ?>">Sign in</a>
                    <a class="btn" href="<?= BASE_PATH ?>/register.php?next=<?= rawurlencode($selfComposeUrl) ?>">Create account</a>
                </div>
            </div>
        <?php else: ?>
            <?php if ($canAnnounce): ?>
            <div class="composer-modes" role="tablist">
                <button type="button" class="composer-mode <?= $composeMode === 'report' ? 'is-active' : '' ?>" data-mode="report">Report scam</button>
                <button type="button" class="composer-mode <?= $composeMode === 'announcement' ? 'is-active' : '' ?>" data-mode="announcement">Announcement</button>
            </div>
            <?php endif; ?>

            <?php if ($composeError): ?><div class="alert alert-error"><?= h($composeError) ?></div><?php endif; ?>

            <form method="post" class="composer-form" id="form-report" action="<?= BASE_PATH ?>/community.php" <?= $composeMode === 'announcement' ? 'hidden' : '' ?>>
                <input type="hidden" name="csrf" value="<?= h(UserAuth::csrfToken()) ?>">
                <input type="hidden" name="action" value="create_report">
                <input type="hidden" name="post_mode" value="report">
                <div class="composer-grid">
                    <div class="field">
                        <label>What are you reporting?</label>
                        <select name="type" id="report-type">
                            <option value="website" <?= $composeType === 'website' ? 'selected' : '' ?>>Website</option>
                            <option value="phone" <?= $composeType === 'phone' ? 'selected' : '' ?>>Phone number</option>
                            <option value="card" <?= $composeType === 'card' ? 'selected' : '' ?>>Bank card</option>
                            <option value="crypto" <?= $composeType === 'crypto' ? 'selected' : '' ?>>Crypto address</option>
                            <option value="iban" <?= $composeType === 'iban' ? 'selected' : '' ?>>IBAN</option>
                        </select>
                    </div>
                    <div class="field">
                        <label id="entity-label">Value</label>
                        <input type="text" name="entity" id="entity-input" placeholder="example.com" value="<?= h($_POST['entity'] ?? $composePrefill) ?>" required>
                    </div>
                    <div class="field">
                        <label>Category</label>
                        <select name="category">
                            <?php foreach (report_categories() as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= ($_POST['category'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label>Title of your report</label>
                    <input type="text" name="title" placeholder="e.g. Fake shop — took payment, never shipped" value="<?= h(($_POST['action'] ?? '') === 'create_report' ? ($_POST['title'] ?? '') : '') ?>" required minlength="8" maxlength="150">
                </div>
                <div class="field">
                    <label>Describe what happened</label>
                    <textarea name="description" rows="5" placeholder="What did you see? Did you lose money or data? Include as much detail as possible." required minlength="20"><?= h(($_POST['action'] ?? '') === 'create_report' ? ($_POST['description'] ?? '') : '') ?></textarea>
                </div>
                <div class="composer-footer">
                    <label class="check-toggle" style="margin:0;">
                        <input type="checkbox" name="comments_open" <?= !isset($_POST['action']) || isset($_POST['comments_open']) ? 'checked' : '' ?>>
                        <span>Let others join the discussion</span>
                    </label>
                    <div class="composer-submit">
                        <span class="composer-as">Posting as <strong><?= h(UserAuth::username()) ?></strong></span>
                        <button type="submit" class="btn btn-primary">Post report</button>
                    </div>
                </div>
            </form>

            <?php if ($canAnnounce): ?>
            <form method="post" class="composer-form" id="form-announcement" action="<?= BASE_PATH ?>/community.php" <?= $composeMode !== 'announcement' ? 'hidden' : '' ?>>
                <input type="hidden" name="csrf" value="<?= h(UserAuth::csrfToken()) ?>">
                <input type="hidden" name="action" value="create_announcement">
                <input type="hidden" name="post_mode" value="announcement">
                <div class="field">
                    <label>Announcement title</label>
                    <input type="text" name="title" placeholder="e.g. New phishing wave targeting banks" value="<?= h(($_POST['action'] ?? '') === 'create_announcement' ? ($_POST['title'] ?? '') : '') ?>" required minlength="5" maxlength="150">
                </div>
                <div class="field">
                    <label>Message</label>
                    <textarea name="description" rows="6" placeholder="Write the announcement for the community…" required minlength="10"><?= h(($_POST['action'] ?? '') === 'create_announcement' ? ($_POST['description'] ?? '') : '') ?></textarea>
                </div>
                <div class="composer-footer">
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <label class="check-toggle" style="margin:0;">
                            <input type="checkbox" name="comments_open" <?= !isset($_POST['action']) || isset($_POST['comments_open']) ? 'checked' : '' ?>>
                            <span>Allow discussion</span>
                        </label>
                        <label class="check-toggle" style="margin:0;">
                            <input type="checkbox" name="is_sticky" <?= !isset($_POST['action']) || isset($_POST['is_sticky']) ? 'checked' : '' ?>>
                            <span>Pin to top</span>
                        </label>
                    </div>
                    <div class="composer-submit">
                        <span class="composer-as">Admin post as <strong><?= h(UserAuth::username()) ?></strong></span>
                        <button type="submit" class="btn btn-primary">Post announcement</button>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {
  const modal = document.getElementById('composer-modal');
  const toggle = document.getElementById('composer-toggle');
  if (!modal) return;

  const open = () => {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('composer-open');
    const first = modal.querySelector('input[name="entity"], a.btn, button.composer-close');
    if (first) setTimeout(() => first.focus(), 40);
  };
  const close = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('composer-open');
    // Drop compose query params so a refresh doesn't reopen the popup.
    try {
      const url = new URL(window.location.href);
      if (url.searchParams.has('compose') || url.searchParams.has('q_prefill') || url.searchParams.has('d')) {
        url.searchParams.delete('compose');
        url.searchParams.delete('q_prefill');
        url.searchParams.delete('d');
        url.searchParams.delete('type');
        history.replaceState({}, '', url.pathname + (url.search || '') + url.hash);
      }
    } catch (_) {}
  };

  if (toggle) toggle.addEventListener('click', open);
  modal.querySelectorAll('[data-composer-close]').forEach((el) => {
    el.addEventListener('click', close);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
  });

  // Empty-state / deep links that include ?compose=1 already open via PHP class.
  document.querySelectorAll('a[href*="compose=1"]').forEach((a) => {
    a.addEventListener('click', (e) => {
      // Same-page compose links: open popup instead of navigating.
      const href = a.getAttribute('href') || '';
      if (!href.includes('community.php')) return;
      try {
        const u = new URL(href, window.location.href);
        if (u.pathname !== window.location.pathname && !u.pathname.endsWith('/community.php')) return;
        e.preventDefault();
        open();
      } catch (_) {}
    });
  });

  const titleEl = document.getElementById('composer-title');
  const subEl = document.getElementById('composer-sub');
  const formReport = document.getElementById('form-report');
  const formAnnounce = document.getElementById('form-announcement');
  const setMode = (mode) => {
    document.querySelectorAll('.composer-mode').forEach((b) => b.classList.toggle('is-active', b.dataset.mode === mode));
    if (formReport) formReport.hidden = mode !== 'report';
    if (formAnnounce) formAnnounce.hidden = mode !== 'announcement';
    if (titleEl) titleEl.textContent = mode === 'announcement' ? 'New announcement' : 'New report';
    if (subEl) {
      subEl.textContent = mode === 'announcement'
        ? 'Admin-only official post — visible to everyone in the community.'
        : 'Opens a public discussion — verified by admins.';
    }
  };
  document.querySelectorAll('.composer-mode').forEach((btn) => {
    btn.addEventListener('click', () => setMode(btn.dataset.mode || 'report'));
  });

  const type = document.getElementById('report-type');
  const input = document.getElementById('entity-input');
  const label = document.getElementById('entity-label');
  if (!type || !input) return;
  const map = {
    website: ['Website domain', 'example.com'],
    phone: ['Phone number', '+491721094066'],
    card: ['Card number', '4111 1111 1111 1111'],
    crypto: ['Crypto address', '0x… or bc1…'],
    iban: ['IBAN', 'DE89 3704 0044 0532 0130 00'],
  };
  const sync = () => {
    const m = map[type.value] || map.website;
    label.textContent = m[0];
    input.placeholder = m[1];
  };
  type.addEventListener('change', sync);
  sync();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
