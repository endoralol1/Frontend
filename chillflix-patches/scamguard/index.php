<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/DomainRepository.php';

$repo = new DomainRepository();
$stats = $repo->stats();
$recent = $repo->recentlyCheckedMixed(12);

$type = strtolower(trim($_GET['type'] ?? 'website'));
if (!in_array($type, ['website', 'phone', 'crypto', 'iban'], true)) {
    $type = 'website';
}
$prefill = trim($_GET['q'] ?? '');
$error = isset($_GET['error']);

$pageTitle = get_setting('site_name', 'ScamGuard') . ' — Check websites, phones, crypto & IBAN';
$pageDescription = 'Free scam checker for websites, phone numbers, crypto addresses, and IBANs. Scan before you click, call, or send money.';
$canonicalUrl = absolute_url('/');
$jsonLd = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => get_setting('site_name', 'ScamGuard'),
    'url' => absolute_url('/'),
    'description' => $pageDescription,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

require __DIR__ . '/includes/header.php';

$placeholders = [
    'website' => 'Enter website, e.g. example.com',
    'phone' => 'Enter phone, e.g. +491721094066',
    'crypto' => 'Enter crypto address (BTC / ETH / TRX…)',
    'iban' => 'Enter IBAN, e.g. DE89 3704 0044 0532 0130 00',
];
?>

<section class="hero">
    <div class="container">
        <h1>Quick check for scams</h1>
        <p>Scan a website, phone number, crypto address, or IBAN — then report scams to help others.</p>

        <?php if ($error): ?>
            <div class="alert alert-error" style="max-width:640px;margin:0 auto 14px;">That input didn’t look valid. Try again.</div>
        <?php endif; ?>

        <form class="multi-search" action="<?= BASE_PATH ?>/check-entity.php" method="get" id="scam-search">
            <input type="hidden" name="type" id="search-type" value="<?= h($type) ?>">

            <div class="search-box search-box-phone" id="search-box">
                <span class="phone-cc" id="phone-cc" hidden>
                    <span class="phone-cc-flag" aria-hidden="true">🌐</span>
                    <span class="phone-cc-code">+</span>
                </span>
                <input type="text" name="q" id="search-q"
                       placeholder="<?= h($placeholders[$type]) ?>"
                       value="<?= h($prefill) ?>"
                       autofocus required
                       autocomplete="off"
                       inputmode="<?= $type === 'phone' ? 'tel' : 'text' ?>">
                <button type="submit">Check scam</button>
            </div>

            <div class="type-row" role="tablist" aria-label="Check type">
                <span class="type-label">Type :</span>
                <?php
                $types = [
                    'website' => 'Website',
                    'phone' => 'Phone',
                    'crypto' => 'Crypto',
                    'iban' => 'IBAN',
                ];
                foreach ($types as $key => $label):
                ?>
                    <button type="button"
                            class="type-chip<?= $type === $key ? ' is-active' : '' ?>"
                            data-type="<?= h($key) ?>"
                            role="tab"
                            aria-selected="<?= $type === $key ? 'true' : 'false' ?>">
                        <?= h($label) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </form>

        <div class="report-cta-card">
            <div>
                <strong>Report scams to help others</strong>
                <p>Share your experience and protect the community.</p>
            </div>
            <a class="btn btn-danger" href="<?= BASE_PATH ?>/report.php">Report</a>
        </div>

        <div class="stats-row">
            <div class="stat">
                <div class="num"><?= number_format($stats['total_domains']) ?></div>
                <div class="label">Domains tracked</div>
            </div>
            <div class="stat">
                <div class="num"><?= number_format($stats['likely_safe']) ?></div>
                <div class="label">Likely safe</div>
            </div>
            <div class="stat">
                <div class="num"><?= number_format($stats['flagged_scams']) ?></div>
                <div class="label">Flagged as scams</div>
            </div>
            <div class="stat">
                <div class="num"><?= number_format($stats['checked_today']) ?></div>
                <div class="label">Checked today</div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div style="display:flex; justify-content:space-between; align-items:end; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
            <h2 class="section-title" style="margin:0;">Recently checked websites</h2>
            <a class="btn btn-sm" href="<?= BASE_PATH ?>/browse.php">Browse all checks</a>
        </div>
        <p style="color:var(--text-faint); margin:0 0 14px; font-size:14px;">
            Shows a mix of safe and risky results — not only threat-feed hits.
        </p>
        <div class="card" style="padding:0;">
            <div class="table-wrap"><table>
                <thead>
                    <tr><th>Domain</th><th>Score</th><th>Status</th><th>Last checked</th></tr>
                </thead>
                <tbody>
                <?php if (empty($recent)): ?>
                    <tr><td colspan="4" style="color:var(--text-faint);">No domains checked yet — be the first to search one above.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $r): $badge = status_badge($r['status']); ?>
                    <tr>
                        <td><a href="<?= h(domain_page_path($r['domain'])) ?>"><?= h($r['domain']) ?></a></td>
                        <td><?= (int) $r['trust_score'] ?>/100</td>
                        <td><span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span></td>
                        <td style="color:var(--text-faint);"><?= h($r['last_checked'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</section>

<script>
(() => {
  const placeholders = <?= json_encode($placeholders, JSON_UNESCAPED_SLASHES) ?>;
  const typeInput = document.getElementById('search-type');
  const q = document.getElementById('search-q');
  const phoneCc = document.getElementById('phone-cc');
  const chips = document.querySelectorAll('.type-chip');

  function setType(t) {
    typeInput.value = t;
    q.placeholder = placeholders[t] || placeholders.website;
    q.inputMode = t === 'phone' ? 'tel' : 'text';
    phoneCc.hidden = t !== 'phone';
    document.getElementById('search-box').classList.toggle('has-phone-cc', t === 'phone');
    chips.forEach((c) => {
      const on = c.dataset.type === t;
      c.classList.toggle('is-active', on);
      c.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    const url = new URL(window.location.href);
    url.searchParams.set('type', t);
    history.replaceState(null, '', url.pathname + '?' + url.searchParams.toString());
  }

  chips.forEach((c) => c.addEventListener('click', () => setType(c.dataset.type)));
  setType(typeInput.value || 'website');
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
