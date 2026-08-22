<?php
$headerClass = 'relative';
$adminUser = $adminUser ?? Auth::user();
$stats = $stats ?? [];
$blocks = $blocks ?? [];
$suspects = $suspects ?? [];
$events = $events ?? [];
?>
<main class="cf-admin page-pad-top">
  <div class="container cf-admin-wrap">
    <nav class="cf-admin-nav" aria-label="Admin">
      <a href="<?= e(url('/admin')) ?>">Dashboard</a>
      <a href="<?= e(url('/admin/users')) ?>">Users</a>
      <a href="<?= e(url('/admin/sources')) ?>">Sources</a>
      <a href="<?= e(url('/admin/inbox')) ?>">Inbox</a>
      <a href="<?= e(url('/admin/ads')) ?>">Ads</a>
      <a href="<?= e(url('/admin/storage')) ?>">Storage</a>
      <a class="is-active" href="<?= e(url('/admin/proxy-guard')) ?>">Proxy guard</a>
      <a href="<?= e(url('/home')) ?>">Site</a>
    </nav>

    <div class="cf-admin-head">
      <h1>Proxy guard</h1>
      <p class="muted" style="margin:.35rem 0 0;color:rgba(255,255,255,.55);font-size:.88rem">
        Track real scrapers (curl / no session / rate floods). Block IPs. Normal watchers with a player session are not listed here.
      </p>
    </div>

    <section class="cf-admin-metrics" aria-label="24h scraper signals" style="margin-top:1rem">
      <div class="cf-admin-metric"><div><strong><?= (int) ($stats['blocked_ua'] ?? 0) ?></strong><span>Blocked UA</span></div></div>
      <div class="cf-admin-metric"><div><strong><?= (int) ($stats['no_session'] ?? 0) ?></strong><span>No session</span></div></div>
      <div class="cf-admin-metric"><div><strong><?= (int) ($stats['rate_limit'] ?? 0) ?></strong><span>Rate limit</span></div></div>
      <div class="cf-admin-metric"><div><strong><?= (int) ($stats['blocks_active'] ?? 0) ?></strong><span>IPs blocked</span></div></div>
    </section>

    <section class="cf-admin-panel" style="margin-top:1rem;padding:1rem 1.1rem">
      <h2 style="margin:0 0 .6rem;font-size:.95rem">Block an IP</h2>
      <form id="pg-block-form" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end">
        <label style="display:flex;flex-direction:column;gap:.25rem;font-size:.8rem;color:rgba(255,255,255,.6)">
          IP
          <input name="ip" required placeholder="1.2.3.4" style="min-width:12rem;padding:.45rem .6rem;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(0,0,0,.35);color:#fff">
        </label>
        <label style="display:flex;flex-direction:column;gap:.25rem;font-size:.8rem;color:rgba(255,255,255,.6)">
          Hours (empty = forever)
          <input name="hours" type="number" min="1" placeholder="24" style="width:7rem;padding:.45rem .6rem;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(0,0,0,.35);color:#fff">
        </label>
        <label style="display:flex;flex-direction:column;gap:.25rem;font-size:.8rem;color:rgba(255,255,255,.6);flex:1">
          Reason
          <input name="reason" placeholder="scraper" style="padding:.45rem .6rem;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(0,0,0,.35);color:#fff">
        </label>
        <button type="submit" class="cf-admin-btn" style="min-height:2.4rem">Block</button>
      </form>
      <p id="pg-msg" style="min-height:1.2rem;margin:.7rem 0 0;font-size:.85rem;color:#fbbf24"></p>
    </section>

    <section class="cf-admin-panel" style="margin-top:1rem;padding:1rem 1.1rem">
      <h2 style="margin:0 0 .6rem;font-size:.95rem">Top suspects (24h)</h2>
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem">
          <thead>
            <tr style="text-align:left;color:rgba(255,255,255,.5)">
              <th style="padding:.35rem">IP</th>
              <th>Hits</th>
              <th>UA</th>
              <th>No sess</th>
              <th>Rate</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$suspects): ?>
              <tr><td colspan="6" style="padding:.6rem;color:rgba(255,255,255,.45)">No scraper signals yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($suspects as $s): ?>
              <tr style="border-top:1px solid rgba(255,255,255,.08)">
                <td style="padding:.4rem .35rem;font-family:ui-monospace,monospace"><?= e((string) ($s['ip'] ?? '')) ?></td>
                <td><?= (int) ($s['hits'] ?? 0) ?></td>
                <td><?= (int) ($s['ua'] ?? 0) ?></td>
                <td><?= (int) ($s['nosess'] ?? 0) ?></td>
                <td><?= (int) ($s['rate'] ?? 0) ?></td>
                <td><button type="button" class="cf-admin-btn ghost pg-block-ip" data-ip="<?= e((string) ($s['ip'] ?? '')) ?>" style="min-height:1.8rem;padding:.2rem .55rem">Block</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="cf-admin-panel" style="margin-top:1rem;padding:1rem 1.1rem">
      <h2 style="margin:0 0 .6rem;font-size:.95rem">Blocked IPs</h2>
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem">
          <thead>
            <tr style="text-align:left;color:rgba(255,255,255,.5)">
              <th style="padding:.35rem">IP</th>
              <th>Reason</th>
              <th>By</th>
              <th>Expires</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$blocks): ?>
              <tr><td colspan="5" style="padding:.6rem;color:rgba(255,255,255,.45)">None blocked.</td></tr>
            <?php endif; ?>
            <?php foreach ($blocks as $b): ?>
              <?php
                $exp = $b['expires_at'] ?? null;
                $expLabel = ($exp === null || $exp === '') ? 'forever' : date('Y-m-d H:i', (int) $exp);
              ?>
              <tr style="border-top:1px solid rgba(255,255,255,.08)">
                <td style="padding:.4rem .35rem;font-family:ui-monospace,monospace"><?= e((string) ($b['ip'] ?? '')) ?></td>
                <td><?= e((string) ($b['reason'] ?? '')) ?></td>
                <td><?= e((string) ($b['created_by'] ?? '')) ?></td>
                <td><?= e($expLabel) ?></td>
                <td><button type="button" class="cf-admin-btn ghost pg-unblock" data-ip="<?= e((string) ($b['ip'] ?? '')) ?>" style="min-height:1.8rem;padding:.2rem .55rem">Unblock</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="cf-admin-panel" style="margin-top:1rem;padding:1rem 1.1rem;margin-bottom:2rem">
      <h2 style="margin:0 0 .6rem;font-size:.95rem">Recent scraper events</h2>
      <div style="overflow:auto;max-height:28rem">
        <table style="width:100%;border-collapse:collapse;font-size:.78rem">
          <thead>
            <tr style="text-align:left;color:rgba(255,255,255,.5)">
              <th style="padding:.35rem">When</th>
              <th>IP</th>
              <th>Event</th>
              <th>Path</th>
              <th>Detail</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($events as $ev): ?>
              <tr style="border-top:1px solid rgba(255,255,255,.06)">
                <td style="padding:.35rem;white-space:nowrap"><?= e(date('m-d H:i:s', (int) ($ev['created_at'] ?? 0))) ?></td>
                <td style="font-family:ui-monospace,monospace"><?= e((string) ($ev['ip'] ?? '')) ?></td>
                <td><?= e((string) ($ev['event'] ?? '')) ?></td>
                <td><?= e((string) ($ev['path'] ?? '')) ?></td>
                <td style="color:rgba(255,255,255,.55)"><?= e((string) ($ev['detail'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</main>
<script>
(function () {
  var api = <?= json_encode(url('/api/admin/proxy-guard')) ?>;
  var msg = document.getElementById('pg-msg');
  function say(t, ok) {
    if (!msg) return;
    msg.style.color = ok ? '#86efac' : '#fbbf24';
    msg.textContent = t || '';
  }
  function post(body) {
    return fetch(api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }
  var form = document.getElementById('pg-block-form');
  if (form) form.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(form);
    var hours = String(fd.get('hours') || '').trim();
    post({
      action: 'block',
      ip: String(fd.get('ip') || ''),
      reason: String(fd.get('reason') || 'manual'),
      hours: hours === '' ? null : Number(hours)
    }).then(function (d) {
      if (d && d.ok) { say('Blocked.', true); setTimeout(function () { location.reload(); }, 500); }
      else say((d && d.error) || 'Failed', false);
    }).catch(function () { say('Failed', false); });
  });
  document.querySelectorAll('.pg-block-ip').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var ip = btn.getAttribute('data-ip') || '';
      if (!ip || !confirm('Block ' + ip + ' for 24h?')) return;
      post({ action: 'block', ip: ip, reason: 'suspect list', hours: 24 }).then(function (d) {
        if (d && d.ok) location.reload();
        else say((d && d.error) || 'Failed', false);
      });
    });
  });
  document.querySelectorAll('.pg-unblock').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var ip = btn.getAttribute('data-ip') || '';
      if (!ip) return;
      post({ action: 'unblock', ip: ip }).then(function (d) {
        if (d && d.ok) location.reload();
        else say((d && d.error) || 'Failed', false);
      });
    });
  });
})();
</script>
