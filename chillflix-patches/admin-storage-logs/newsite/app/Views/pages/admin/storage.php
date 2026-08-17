<?php
$headerClass = 'relative';
$adminUser = $adminUser ?? Auth::user();
$disk = $disk ?? SiteStorage::disk();
$items = $items ?? SiteStorage::inventory();
?>
<main class="cf-admin page-pad-top">
  <div class="container cf-admin-wrap">
    <nav class="cf-admin-nav" aria-label="Admin">
      <a href="<?= e(url('/admin')) ?>">Dashboard</a>
      <a href="<?= e(url('/admin/users')) ?>">Users</a>
      <a href="<?= e(url('/admin/sources')) ?>">Sources</a>
      <a href="<?= e(url('/admin/inbox')) ?>">Inbox</a>
      <a href="<?= e(url('/admin/ads')) ?>">Ads</a>
      <a class="is-active" href="<?= e(url('/admin/storage')) ?>">Storage</a>
      <a href="<?= e(url('/home')) ?>">Site</a>
    </nav>

    <div class="cf-admin-head">
      <h1>Storage &amp; logs</h1>
      <p class="muted" style="margin:.35rem 0 0;color:rgba(255,255,255,.55);font-size:.88rem">
        Clean leftovers safely. Live Chillflix <code>.next</code> is never touched.
      </p>
    </div>

    <section class="cf-admin-panel" style="margin-top:1rem;padding:1rem 1.1rem">
      <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between">
        <div>
          <strong>Disk</strong>
          <div id="storage-disk-text" class="muted" style="font-size:.82rem;color:rgba(255,255,255,.5);margin-top:.2rem">
            <?= e(SiteStorage::fmt((int) ($disk['usedBytes'] ?? 0))) ?>
            / <?= e(SiteStorage::fmt((int) ($disk['totalBytes'] ?? 0))) ?>
            used (<?= e((string) ($disk['usedPercent'] ?? 0)) ?>%)
          </div>
        </div>
        <button type="button" class="cf-admin-btn ghost" id="storage-refresh" style="min-height:2rem;padding:.3rem .7rem">Refresh</button>
      </div>
      <div style="margin-top:.7rem;height:.35rem;border-radius:999px;background:rgba(255,255,255,.1);overflow:hidden">
        <div id="storage-disk-bar" style="height:100%;width:<?= min(100, (float) ($disk['usedPercent'] ?? 0)) ?>%;background:linear-gradient(90deg,#db6937,#f59e0b)"></div>
      </div>
      <p id="storage-msg" style="min-height:1.35rem;margin:.85rem 0 0;font-size:.9rem;font-weight:600;color:#fbbf24">Ready — tap Clean on a row.</p>
    </section>

    <section class="cf-admin-panel" style="margin-top:1rem;padding:0" aria-label="Cleanup targets">
      <div id="storage-list">
        <?php foreach ($items as $item): ?>
          <?php
            $id = (string) ($item['id'] ?? '');
            $isLog = str_starts_with($id, 'log:');
            $logName = $isLog ? substr($id, 4) : '';
          ?>
          <div class="cf-storage-row" data-id="<?= e($id) ?>">
            <div class="cf-storage-copy">
              <strong><?= e((string) ($item['label'] ?? $id)) ?></strong>
              <small><?= e((string) ($item['note'] ?? '')) ?></small>
              <small class="cf-storage-meta">
                <?= e(SiteStorage::fmt((int) ($item['bytes'] ?? 0))) ?>
                <?php if (!empty($item['count'])): ?> · <?= (int) $item['count'] ?> file(s)<?php endif; ?>
                · <code><?= e((string) ($item['path'] ?? '')) ?></code>
              </small>
            </div>
            <div class="cf-storage-actions">
              <?php if ($isLog): ?>
                <button type="button" class="cf-admin-btn ghost storage-view" data-log="<?= e($logName) ?>">View</button>
              <?php endif; ?>
              <?php if (!empty($item['safe'])): ?>
                <button type="button" class="cf-admin-btn storage-clean" data-id="<?= e($id) ?>" data-label="<?= e((string) ($item['label'] ?? $id)) ?>">Clean</button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="cf-admin-panel" id="storage-log-panel" hidden style="margin-top:1rem;padding:1rem 1.1rem">
      <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:center;margin-bottom:.6rem">
        <strong id="storage-log-title">Nginx log</strong>
        <button type="button" class="cf-admin-btn ghost" id="storage-log-close" style="min-height:2rem;padding:.3rem .7rem">Close</button>
      </div>
      <pre id="storage-log-body" style="max-height:28rem;overflow:auto;margin:0;padding:.85rem;border-radius:.7rem;background:rgba(0,0,0,.35);font-size:.72rem;line-height:1.4;white-space:pre-wrap;word-break:break-all;color:rgba(255,255,255,.78)"></pre>
    </section>
  </div>
</main>
<style>
.cf-storage-row{
  display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:.85rem;
  padding:1rem 1.1rem;border-bottom:1px solid rgba(255,255,255,.08);
}
.cf-storage-row:last-child{border-bottom:0}
.cf-storage-copy{display:flex;flex-direction:column;gap:.25rem;min-width:0;flex:1}
.cf-storage-copy strong{font-size:.95rem}
.cf-storage-copy small{color:rgba(255,255,255,.5);font-size:.78rem;line-height:1.4}
.cf-storage-meta code{font-size:.72rem;color:rgba(255,255,255,.45)}
.cf-storage-actions{display:flex;gap:.4rem;flex-wrap:wrap}
.cf-storage-actions .cf-admin-btn:disabled{opacity:.55;cursor:wait}
</style>
<script>
(function () {
  var API = <?= json_encode(url('/api/admin/storage'), JSON_UNESCAPED_SLASHES) ?>;
  var LOG_API = <?= json_encode(url('/api/admin/storage/log'), JSON_UNESCAPED_SLASHES) ?>;
  var msg = document.getElementById('storage-msg');
  var list = document.getElementById('storage-list');
  var bar = document.getElementById('storage-disk-bar');
  var diskText = document.getElementById('storage-disk-text');
  var logPanel = document.getElementById('storage-log-panel');
  var logTitle = document.getElementById('storage-log-title');
  var logBody = document.getElementById('storage-log-body');
  var busy = false;

  function show(t, ok) {
    if (!msg) return;
    msg.textContent = t || '';
    msg.style.color = ok === false ? '#f87171' : (ok === true ? '#4ade80' : '#fbbf24');
  }
  function fmt(bytes) {
    bytes = Number(bytes) || 0;
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
    return (bytes / 1073741824).toFixed(2) + ' GB';
  }
  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
  }
  function setBusy(on) {
    busy = !!on;
    if (!list) return;
    list.querySelectorAll('.storage-clean,.storage-view,#storage-refresh').forEach(function (el) {
      el.disabled = !!on;
    });
    var refresh = document.getElementById('storage-refresh');
    if (refresh) refresh.disabled = !!on;
  }
  function render(data) {
    var disk = (data && data.disk) || {};
    var items = (data && data.items) || [];
    if (bar) bar.style.width = Math.min(100, Number(disk.usedPercent || 0)) + '%';
    if (diskText && disk.totalBytes != null) {
      diskText.textContent = fmt(disk.usedBytes) + ' / ' + fmt(disk.totalBytes) + ' used (' + disk.usedPercent + '%)';
    }
    if (!list) return;
    list.innerHTML = items.map(function (item) {
      var id = item.id || '';
      var isLog = id.indexOf('log:') === 0;
      var logName = isLog ? id.slice(4) : '';
      var actions = '';
      if (isLog) actions += '<button type="button" class="cf-admin-btn ghost storage-view" data-log="' + esc(logName) + '">View</button>';
      if (item.safe) actions += '<button type="button" class="cf-admin-btn storage-clean" data-id="' + esc(id) + '" data-label="' + esc(item.label || id) + '">Clean</button>';
      return '<div class="cf-storage-row" data-id="' + esc(id) + '">' +
        '<div class="cf-storage-copy"><strong>' + esc(item.label) + '</strong>' +
        '<small>' + esc(item.note || '') + '</small>' +
        '<small class="cf-storage-meta">' + esc(fmt(item.bytes)) +
        (item.count ? (' · ' + item.count + ' file(s)') : '') +
        ' · <code>' + esc(item.path || '') + '</code></small></div>' +
        '<div class="cf-storage-actions">' + actions + '</div></div>';
    }).join('');
  }
  function load() {
    show('Refreshing sizes…');
    return fetch(API, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json().then(function (d) { return { okHttp: r.ok, d: d }; }); })
      .then(function (pack) {
        var d = pack.d;
        if (!pack.okHttp || !d || !d.ok) {
          show((d && (d.error || d.message)) || 'Failed to load', false);
          return;
        }
        render(d);
        show('Ready — tap Clean on a row.');
      })
      .catch(function (err) {
        show('Network error: ' + (err && err.message ? err.message : 'failed'), false);
      });
  }
  function clean(id, label) {
    if (!id || busy) return;
    if (!window.confirm('Clean \"' + (label || id) + '\"?\n\nThis only removes safe leftovers / logs.')) return;
    setBusy(true);
    show('Cleaning ' + (label || id) + '… please wait', null);
    fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: id })
    }).then(function (r) {
      return r.text().then(function (text) {
        var d = null;
        try { d = JSON.parse(text); } catch (e) {}
        return { okHttp: r.ok, status: r.status, d: d, text: text };
      });
    }).then(function (pack) {
      var d = pack.d;
      if (!pack.okHttp || !d || !d.ok) {
        var err = (d && (d.message || d.error)) || ('HTTP ' + pack.status + ' ' + (pack.text || '').slice(0, 120));
        show('Clean failed: ' + err, false);
        alert('Clean failed:\n' + err);
        return;
      }
      var done = (d.message || 'Cleaned') + (d.freedBytes ? (' · freed ' + fmt(d.freedBytes)) : '');
      show(done, true);
      alert(done);
      return load();
    }).catch(function (err) {
      var m = (err && err.message) ? err.message : 'network error';
      show('Clean failed: ' + m, false);
      alert('Clean failed:\n' + m);
    }).finally(function () {
      setBusy(false);
    });
  }
  function viewLog(name) {
    if (!logPanel || !logBody || busy) return;
    logPanel.hidden = false;
    if (logTitle) logTitle.textContent = name;
    logBody.textContent = 'Loading…';
    fetch(LOG_API + '?name=' + encodeURIComponent(name), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { logBody.textContent = (d && d.error) || 'Failed to read log'; return; }
        logBody.textContent = d.content || '(empty)';
      })
      .catch(function () { logBody.textContent = 'Network error'; });
  }

  document.getElementById('storage-refresh') && document.getElementById('storage-refresh').addEventListener('click', function () {
    if (busy) return;
    setBusy(true);
    load().finally(function () { setBusy(false); });
  });
  document.getElementById('storage-log-close') && document.getElementById('storage-log-close').addEventListener('click', function () {
    if (logPanel) logPanel.hidden = true;
  });
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var cleanBtn = t.closest('.storage-clean');
    if (cleanBtn) {
      e.preventDefault();
      clean(cleanBtn.getAttribute('data-id'), cleanBtn.getAttribute('data-label'));
      return;
    }
    var viewBtn = t.closest('.storage-view');
    if (viewBtn) {
      e.preventDefault();
      viewLog(viewBtn.getAttribute('data-log'));
    }
  });
})();
</script>
