<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/DomainRepository.php';
require_once __DIR__ . '/includes/EntityRepository.php';
require_once __DIR__ . '/includes/PhoneChecker.php';
require_once __DIR__ . '/includes/CryptoChecker.php';
require_once __DIR__ . '/includes/IbanChecker.php';

$success = false;
$error = null;
$type = strtolower(trim($_GET['type'] ?? $_POST['type'] ?? 'website'));
if (!in_array($type, ['website', 'phone', 'crypto', 'iban'], true)) {
    $type = 'website';
}
$prefill = trim($_GET['q'] ?? $_GET['d'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = strtolower(trim($_POST['type'] ?? 'website'));
    $input = trim($_POST['entity'] ?? $_POST['domain'] ?? '');
    $category = $_POST['category'] ?? 'other';
    $description = trim($_POST['description'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $validCategories = ['phishing','fake_shop','crypto_scam','tech_support_scam','identity_theft','phone_spam','other'];

    if (!in_array($type, ['website', 'phone', 'crypto', 'iban'], true)) {
        $error = 'Please choose what you are reporting.';
    } elseif (!in_array($category, $validCategories, true)) {
        $error = 'Please choose a valid category.';
    } else {
        $db = Database::getConnection();
        if ($type === 'website') {
            $normalized = normalize_domain($input);
            if (!$normalized) {
                $error = 'Please enter a valid domain (e.g. example.com).';
            } else {
                $repo = new DomainRepository();
                $existing = $repo->find($normalized);
                $stmt = $db->prepare('INSERT INTO reports (domain_id, domain_text, reporter_email, category, description, ip_hash) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $existing['id'] ?? null,
                    $normalized,
                    $email !== '' ? $email : null,
                    $category,
                    $description !== '' ? $description : null,
                    hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . APP_SECRET),
                ]);
                $success = true;
            }
        } else {
            $normalized = match ($type) {
                'phone' => PhoneChecker::normalize($input),
                'crypto' => CryptoChecker::normalize($input),
                'iban' => IbanChecker::normalize($input),
                default => null,
            };
            if (!$normalized) {
                $error = 'Please enter a valid ' . $type . '.';
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO entity_reports (entity_type, entity_value, reporter_email, category, description, ip_hash)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $type,
                    $normalized,
                    $email !== '' ? $email : null,
                    $category,
                    $description !== '' ? $description : null,
                    hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . APP_SECRET),
                ]);
                $success = true;
            }
        }
    }
}

$pageTitle = 'Report a Scam — ' . get_setting('site_name', 'ScamGuard');
require __DIR__ . '/includes/header.php';
?>

<section class="section container" style="max-width:640px;">
    <h2 class="section-title">Report a scam</h2>
    <p style="color:var(--muted); margin-top:-6px;">Website, phone number, crypto address, or IBAN.</p>

    <?php if ($success): ?>
        <div class="alert alert-success">Thanks — your report has been submitted and will be reviewed by our team.</div>
        <a href="<?= BASE_PATH ?>/" class="btn">&larr; Back to home</a>
    <?php else: ?>
        <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

        <form method="post" class="card">
            <div class="field">
                <label>What are you reporting?</label>
                <select name="type" id="report-type">
                    <option value="website" <?= $type === 'website' ? 'selected' : '' ?>>Website</option>
                    <option value="phone" <?= $type === 'phone' ? 'selected' : '' ?>>Phone number</option>
                    <option value="crypto" <?= $type === 'crypto' ? 'selected' : '' ?>>Crypto address</option>
                    <option value="iban" <?= $type === 'iban' ? 'selected' : '' ?>>IBAN</option>
                </select>
            </div>
            <div class="field">
                <label id="entity-label">Value</label>
                <input type="text" name="entity" id="entity-input" placeholder="example.com" value="<?= h($prefill) ?>" required>
            </div>
            <div class="field">
                <label>What kind of scam is this?</label>
                <select name="category">
                    <option value="phishing">Phishing</option>
                    <option value="fake_shop">Fake online shop</option>
                    <option value="crypto_scam">Crypto / investment scam</option>
                    <option value="tech_support_scam">Tech support scam</option>
                    <option value="phone_spam">Phone spam / scam call</option>
                    <option value="identity_theft">Identity theft attempt</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="field">
                <label>Describe what happened (optional)</label>
                <textarea name="description" rows="4" placeholder="What did you see? Did you lose money or data?"></textarea>
            </div>
            <div class="field">
                <label>Your email (optional)</label>
                <input type="email" name="email" placeholder="you@example.com">
            </div>
            <button type="submit" class="btn btn-primary">Submit Report</button>
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
