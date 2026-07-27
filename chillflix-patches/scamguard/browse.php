<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/DomainRepository.php';

$repo = new DomainRepository();
$status = trim((string) ($_GET['status'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = $repo->browse($page, 40, $status, $q);

$statusLabel = $status !== '' ? ucfirst($status) . ' ' : '';
$pageTitle = ($statusLabel ?: 'All ') . 'website checks — ' . get_setting('site_name', 'ScamGuard');
$pageDescription = 'Browse ScamGuard website safety checks. See trust scores for safe, caution, risky, and scam domains before you click.';
$canonicalUrl = absolute_url('browse.php' . ($page > 1 ? '?page=' . $page : ''));
if ($status !== '') {
    $canonicalUrl = absolute_url('browse.php?status=' . rawurlencode($status) . ($page > 1 ? '&page=' . $page : ''));
}

require __DIR__ . '/includes/header.php';

$statuses = ['', 'safe', 'caution', 'risky', 'scam', 'unknown'];
?>

<section class="section container">
    <h1 class="section-title">Browse checked websites</h1>
    <p style="color:var(--text-faint); margin-top:-8px;">
        Every scanned domain gets a public page Google can index — so people can find a safety check before visiting.
    </p>

    <form method="get" class="card" style="margin:18px 0; display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        <div class="field" style="margin:0; flex:1; min-width:180px;">
            <label>Search</label>
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="domain.com">
        </div>
        <div class="field" style="margin:0; min-width:160px;">
            <label>Status</label>
            <select name="status">
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s === '' ? 'All statuses' : ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Filter</button>
    </form>

    <div class="card" style="padding:0;">
        <div class="table-wrap"><table>
            <thead>
                <tr><th>Domain</th><th>Score</th><th>Status</th><th>Source</th><th>Last checked</th></tr>
            </thead>
            <tbody>
            <?php foreach ($result['rows'] as $r): $badge = status_badge($r['status']); ?>
                <tr>
                    <td><a href="<?= h(domain_page_path($r['domain'])) ?>"><?= h($r['domain']) ?></a></td>
                    <td><?= (int) $r['trust_score'] ?>/100</td>
                    <td><span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span></td>
                    <td style="color:var(--text-faint);"><?= h($r['discovered_via'] ?? '—') ?></td>
                    <td style="color:var(--text-faint);"><?= h($r['last_checked'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($result['rows'])): ?>
                <tr><td colspan="5" style="color:var(--text-faint);">No domains match.</td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <div style="margin-top:16px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <?php
        $qs = static function (int $p) use ($status, $q): string {
            $params = ['page' => $p];
            if ($status !== '') $params['status'] = $status;
            if ($q !== '') $params['q'] = $q;
            return '?' . http_build_query($params);
        };
        ?>
        <?php if ($result['page'] > 1): ?>
            <a class="btn btn-sm" href="<?= h($qs($result['page'] - 1)) ?>">← Prev</a>
        <?php endif; ?>
        <?php if ($result['page'] < $result['pages']): ?>
            <a class="btn btn-sm" href="<?= h($qs($result['page'] + 1)) ?>">Next →</a>
        <?php endif; ?>
        <span style="color:var(--text-faint); font-size:14px;">
            Page <?= (int) $result['page'] ?> / <?= (int) $result['pages'] ?> · <?= number_format($result['total']) ?> domains
        </span>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
