<?php
$site = $site ?? (string) config('site_name');

$headerClass = 'relative';
$adminUser = $adminUser ?? Auth::user();
$name = (string) ($adminUser['name'] ?? 'Admin');
$role = (string) ($adminUser['role'] ?? 'admin');
?>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>
<main class="cf-admin page-pad-top">
  <div class="container cf-admin-wrap">
    <nav class="cf-admin-nav" aria-label="Admin">
      <a class="is-active" href="<?= e(url('/admin')) ?>">Dashboard</a>
      <a href="<?= e(url('/admin/users')) ?>">Users</a>
      <a href="<?= e(url('/admin/sources')) ?>">Sources</a>
      <a href="<?= e(url('/home')) ?>">Site</a>
    </nav>

    <section class="cf-admin-hero">
      <div class="cf-admin-hero-copy">
        <p class="cf-admin-kicker">Newsite control</p>
        <h1>Welcome back, <?= e($name) ?></h1>
        <p class="cf-admin-signed">Signed in as <span><?= e($role) ?></span> · manage accounts, sync, and playback sources</p>
      </div>
    </section>

    <section class="cf-admin-metrics" id="admin-stats" aria-label="Site metrics">
      <div class="cf-admin-metric">
        <span class="cf-admin-metric-ico"><i class="uil uil-users-alt" aria-hidden="true"></i></span>
        <div><strong>—</strong><span>Users</span></div>
      </div>
      <div class="cf-admin-metric">
        <span class="cf-admin-metric-ico"><i class="uil uil-heart" aria-hidden="true"></i></span>
        <div><strong>—</strong><span>Favorites</span></div>
      </div>
      <div class="cf-admin-metric">
        <span class="cf-admin-metric-ico"><i class="uil uil-history" aria-hidden="true"></i></span>
        <div><strong>—</strong><span>Continue</span></div>
      </div>
      <div class="cf-admin-metric">
        <span class="cf-admin-metric-ico"><i class="uil uil-server" aria-hidden="true"></i></span>
        <div><strong>—</strong><span>Sources on</span></div>
      </div>
    </section>

    <section class="cf-admin-actions" aria-label="Quick actions">
      <h2>Quick actions</h2>
      <div class="cf-admin-action-list">
        <a class="cf-admin-action" href="<?= e(url('/admin/users')) ?>">
          <span class="cf-admin-action-ico"><i class="uil uil-user-circle" aria-hidden="true"></i></span>
          <span class="cf-admin-action-copy">
            <strong>Manage users</strong>
            <small>Roles, status, and account cleanup</small>
          </span>
          <i class="uil uil-angle-right" aria-hidden="true"></i>
        </a>
        <a class="cf-admin-action" href="<?= e(url('/admin/sources')) ?>">
          <span class="cf-admin-action-ico"><i class="uil uil-sliders-v" aria-hidden="true"></i></span>
          <span class="cf-admin-action-copy">
            <strong>Control sources</strong>
            <small>Enable, reorder, label Alpha/Beta, run tests</small>
          </span>
          <i class="uil uil-angle-right" aria-hidden="true"></i>
        </a>
        <a class="cf-admin-action" href="<?= e(url('/home')) ?>">
          <span class="cf-admin-action-ico"><i class="uil uil-play" aria-hidden="true"></i></span>
          <span class="cf-admin-action-copy">
            <strong>Open site</strong>
            <small>Return to browsing <?= e($site) ?></small>
          </span>
          <i class="uil uil-angle-right" aria-hidden="true"></i>
        </a>
        <button type="button" class="cf-admin-action" id="admin-games-sync-btn">
          <span class="cf-admin-action-ico"><i class="uil uil-gamepad" aria-hidden="true"></i></span>
          <span class="cf-admin-action-copy">
            <strong>Sync Games catalog</strong>
            <small id="admin-games-status">Loading status…</small>
          </span>
          <i class="uil uil-refresh" aria-hidden="true" id="admin-games-sync-icon"></i>
        </button>
      </div>
    </section>

    <section class="cf-admin-note" id="admin-role-note" aria-live="polite">
      <p><i class="uil uil-shield-check" aria-hidden="true"></i> Staff tools stay on newsite only — public viewers still see Alpha/Beta source names.</p>
    </section>
  </div>
</main>
<script>
(function(){
  fetch(<?= json_encode(url('/api/admin/stats')) ?>, {credentials:'same-origin'})
    .then(r=>r.json()).then(d=>{
      if(!d||!d.ok) return;
      var s=d.stats||{};
      var el=document.getElementById('admin-stats');
      if(!el) return;
      var items=[
        ['uil-users-alt','Users',s.users||0],
        ['uil-heart','Favorites',s.favorites||0],
        ['uil-history','Continue',s.continue||0],
        ['uil-server','Sources on',s.sourcesEnabled||0]
      ];
      el.innerHTML = items.map(function(it){
        return '<div class="cf-admin-metric">'+
          '<span class="cf-admin-metric-ico"><i class="uil '+it[0]+'" aria-hidden="true"></i></span>'+
          '<div><strong>'+it[2]+'</strong><span>'+it[1]+'</span></div></div>';
      }).join('');
    }).catch(function(){});

  // Games sync
  var statusEl = document.getElementById('admin-games-status');
  var syncBtn  = document.getElementById('admin-games-sync-btn');
  var syncIcon = document.getElementById('admin-games-sync-icon');

  function loadGamesStatus() {
    fetch(<?= json_encode(url('/api/admin/games/status')) ?>, {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok || !statusEl) return;
        var m = d.meta || {};
        var total = m.totalGames ? m.totalGames.toLocaleString() + ' games' : 'unknown';
        var when  = m.lastSyncAt ? new Date(m.lastSyncAt).toLocaleString() : 'never';
        statusEl.textContent = total + ' · last synced ' + when;
      }).catch(function(){ if(statusEl) statusEl.textContent = 'Status unavailable'; });
  }

  if (syncBtn) {
    loadGamesStatus();
    syncBtn.addEventListener('click', function(){
      if (syncBtn.disabled) return;
      syncBtn.disabled = true;
      if(statusEl) statusEl.textContent = 'Syncing…';
      if(syncIcon) syncIcon.style.animation = 'spin 1s linear infinite';
      fetch(<?= json_encode(url('/api/admin/games/sync')) ?>, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type':'application/json'}, body: '{}'
      }).then(function(r){ return r.json(); })
        .then(function(d){
          if(syncIcon) syncIcon.style.animation = '';
          if (d.ok) {
            if(statusEl) statusEl.textContent = (d.total||0).toLocaleString() + ' games · synced just now';
          } else {
            if(statusEl) statusEl.textContent = 'Sync failed: ' + (d.error||'unknown');
          }
          syncBtn.disabled = false;
        }).catch(function(){
          if(syncIcon) syncIcon.style.animation = '';
          if(statusEl) statusEl.textContent = 'Sync failed — network error';
          syncBtn.disabled = false;
        });
    });
  }

})();
</script>
