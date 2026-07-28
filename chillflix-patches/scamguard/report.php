<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/UserAuth.php';
require_once __DIR__ . '/includes/DomainRepository.php';
require_once __DIR__ . '/includes/PhoneChecker.php';
require_once __DIR__ . '/includes/CryptoChecker.php';
require_once __DIR__ . '/includes/IbanChecker.php';
require_once __DIR__ . '/includes/CardChecker.php';

UserAuth::start();

$error = null;
$type = strtolower(trim($_GET['type'] ?? $_POST['type'] ?? 'website'));
if (!in_array($type, ['website', 'phone', 'crypto', 'iban', 'card'], true)) {
    $type = 'website';
}
$prefill = trim($_GET['q'] ?? $_GET['d'] ?? '');
$selfUrl = '/report.php' . ($prefill !== '' ? '?type=' . rawurlencode($type) . '&q=' . rawurlencode($prefill) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && UserAuth::check()) {
    $type = strtolower(trim($_POST['type'] ?? 'website'));
    $input = trim($_POST['entity'] ?? '');
    $category = $_POST['category'] ?? 'other';
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['description'] ?? '');
    $commentsOpen = isset($_POST['comments_open']) ? 1 : 0;
    $userId = (int) UserAuth::id();

    if (!UserAuth::verifyCsrf($_POST['csrf'] ?? null)) {
        $error = 'Session expired — please submit again.';
    } elseif (!in_array($type, ['website', 'phone', 'crypto', 'iban', 'card'], true)) {
        $error = 'Please choose what you are reporting.';
    } elseif (!array_key_exists($category, report_categories())) {
        $error = 'Please choose a valid category.';
    } elseif (mb_strlen($title) < 8 || mb_strlen($title) > 150) {
        $error = 'Give your report a short title (8–150 characters).';
    } elseif (mb_strlen($body) < 20) {
        $error = 'Describe what happened in at least 20 characters — it helps others and our reviewers.';
    } elseif (mb_strlen($body) > 8000) {
        $error = 'Description is too long (max 8000 characters).';
    } else {
        $rate = UserAuth::canPostThread($userId);
        if (!$rate['ok']) {
            $error = $rate['error'];
        }
    }

    if ($error === null) {
        $normalized = null;
        if ($type === 'website') {
            $normalized = normalize_domain($input);
        } else {
            $normalized = match ($type) {
                'phone' => PhoneChecker::normalize($input),
                'crypto' => CryptoChecker::normalize($input),
                'iban' => IbanChecker::normalize($input),
                'card' => CardChecker::normalize($input),
                default => null,
            };
            if ($type === 'card' && $normalized) {
                $normalized = 'card:' . hash('sha256', $normalized);
            }
        }

        if (!$normalized) {
            $error = $type === 'website'
                ? 'Please enter a valid domain (e.g. example.com).'
                : 'Please enter a valid ' . $type . '.';
        } else {
            $db = Database::getConnection();
            $ipHash = UserAuth::ipHash();

            // Look up the reporter's email so existing admin tooling keeps working.
            $stmt = $db->prepare('SELECT email FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $userEmail = (string) $stmt->fetchColumn();

            $reportId = null;
            $entityReportId = null;
            $domainId = null;

            if ($type === 'website') {
                $repo = new DomainRepository();
                $existing = $repo->find($normalized);
                $domainId = $existing['id'] ?? null;
                $reportCategory = in_array($category, ['phishing','fake_shop','crypto_scam','tech_support_scam','identity_theft','other'], true) ? $category : 'other';
                $stmt = $db->prepare('INSERT INTO reports (domain_id, domain_text, reporter_email, category, description, ip_hash) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$domainId, $normalized, $userEmail, $reportCategory, $body, $ipHash]);
                $reportId = (int) $db->lastInsertId();
            } else {
                $stmt = $db->prepare('INSERT INTO entity_reports (entity_type, entity_value, reporter_email, category, description, ip_hash) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$type, $normalized, $userEmail, $category, $body, $ipHash]);
                $entityReportId = (int) $db->lastInsertId();
            }

            $stmt = $db->prepare(
                'INSERT INTO forum_threads
                    (user_id, subject_type, subject_value, domain_id, report_id, entity_report_id, category, title, body, comments_open)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId, $type, $normalized, $domainId, $reportId, $entityReportId,
                $category, $title, $body, $commentsOpen,
            ]);
            $threadId = (int) $db->lastInsertId();

            redirect('/thread.php?id=' . $threadId . '&new=1');
        }
    }
}

$pageTitle = 'Report a Scam — ' . get_setting('site_name', 'ScamGuard');
require __DIR__ . '/includes/header.php';
?>

<section class="section container" style="max-width:680px;">
    <h2 class="section-title">Report a scam</h2>
    <p style="color:var(--muted); margin-top:-6px;">Your report opens a public discussion thread that admins verify. Website, phone, bank card, crypto address, or IBAN.</p>

    <?php if (!UserAuth::check()): ?>
        <div class="card auth-gate">
            <div class="auth-gate-icon" aria-hidden="true">🔐</div>
            <h3 style="margin:0 0 6px;">Sign in to report</h3>
            <p style="color:var(--muted); margin:0 0 16px;">Reports require a free account so the community stays spam-free. It takes 20 seconds.</p>
            <div class="auth-gate-actions">
                <a class="btn btn-primary" href="<?= BASE_PATH ?>/login.php?next=<?= rawurlencode($selfUrl) ?>">Sign in</a>
                <a class="btn" href="<?= BASE_PATH ?>/register.php?next=<?= rawurlencode($selfUrl) ?>">Create account</a>
            </div>
        </div>
    <?php else: ?>
        <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

        <form method="post" class="card">
            <input type="hidden" name="csrf" value="<?= h(UserAuth::csrfToken()) ?>">
            <div class="field">
                <label>What are you reporting?</label>
                <select name="type" id="report-type">
                    <option value="website" <?= $type === 'website' ? 'selected' : '' ?>>Website</option>
                    <option value="phone" <?= $type === 'phone' ? 'selected' : '' ?>>Phone number</option>
                    <option value="card" <?= $type === 'card' ? 'selected' : '' ?>>Bank card</option>
                    <option value="crypto" <?= $type === 'crypto' ? 'selected' : '' ?>>Crypto address</option>
                    <option value="iban" <?= $type === 'iban' ? 'selected' : '' ?>>IBAN</option>
                </select>
            </div>
            <div class="field">
                <label id="entity-label">Value</label>
                <input type="text" name="entity" id="entity-input" placeholder="example.com" value="<?= h($_POST['entity'] ?? $prefill) ?>" required>
            </div>
            <div class="field">
                <label>Title of your report</label>
                <input type="text" name="title" placeholder="e.g. Fake shop — took payment, never shipped" value="<?= h($_POST['title'] ?? '') ?>" required minlength="8" maxlength="150">
            </div>
            <div class="field">
                <label>What kind of scam is this?</label>
                <select name="category">
                    <?php foreach (report_categories() as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= ($_POST['category'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Describe what happened</label>
                <textarea name="description" rows="6" placeholder="What did you see? Did you lose money or data? Include as much detail as possible." required minlength="20"><?= h($_POST['description'] ?? '') ?></textarea>
            </div>
            <label class="check-toggle">
                <input type="checkbox" name="comments_open" <?= isset($_POST['comments_open']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
                <span>Let other users join the discussion on this report</span>
            </label>
            <button type="submit" class="btn btn-primary" style="margin-top:14px;">Post report</button>
            <p style="color:var(--faint); font-size:12.5px; margin:10px 0 0;">
                Posting as <strong style="color:var(--muted);"><?= h(UserAuth::username()) ?></strong> — your report appears publicly in the
                <a href="<?= BASE_PATH ?>/community.php" style="color:var(--brand-2);">community</a> and is reviewed by admins.
            </p>
        </form>
    <?php endif; ?>
</section>

<script>
(() => {
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
