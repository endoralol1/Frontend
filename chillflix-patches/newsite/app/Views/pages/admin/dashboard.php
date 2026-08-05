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

    <section class="cf-admin-panel" id="cf-workers-panel" aria-label="Cloudflare Worker analytics" style="margin:1.25rem 0">
      <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.6rem">
        <div>
          <h2 style="margin:0;font-size:.95rem">CF Workers</h2>
          <p id="cf-workers-summary" class="muted" style="margin:.2rem 0 0;font-size:.78rem;color:rgba(255,255,255,.5)">Loading…</p>
        </div>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap">
          <button type="button" class="cf-admin-btn ghost" id="cf-workers-refresh" style="min-height:2rem;padding:.3rem .7rem">Refresh</button>
          <a class="cf-admin-btn ghost" href="<?= e(url('/admin/sources')) ?>#relay-panel" style="min-height:2rem;padding:.3rem .7rem">Manage</a>
        </div>
      </div>
      <div id="cf-workers-list" style="font-size:.82rem"></div>
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

  /* ---- CF Worker analytics (compact) ---- */
  var RELAY = <?= json_encode(url('/api/admin/worker-relay')) ?>;
  var cfList = document.getElementById('cf-workers-list');
  var cfSummary = document.getElementById('cf-workers-summary');
  var cfRefresh = document.getElementById('cf-workers-refresh');
  function cfEsc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
  function loadCfStats(force){
    if(cfSummary) cfSummary.textContent = 'Loading…';
    fetch(RELAY, {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'stats', force:!!force})
    }).then(function(r){ return r.json(); }).then(function(d){
      var rows = (d && (d.ok||d.success) && d.stats) ? d.stats : [];
      var total = 0, limit = 0;
      rows.forEach(function(s){ if(s.ok){ total += Number(s.todayRequests||0); limit += Number(s.freeDailyLimit||0); } });
      if(cfSummary){
        cfSummary.textContent = rows.length
          ? ('Combined today: ' + total.toLocaleString() + (limit?(' / '+limit.toLocaleString()):'') + ' · ' + new Date().toLocaleTimeString())
          : 'No Workers configured — add on Sources';
      }
      if(!cfList) return;
      if(!rows.length){
        cfList.innerHTML = '<p class="muted" style="margin:0;color:rgba(255,255,255,.45)">No Workers yet.</p>';
        return;
      }
      cfList.innerHTML = rows.map(function(s){
        if(!s.ok){
          return '<div style="display:flex;gap:.75rem;padding:.45rem 0;border-top:1px solid rgba(255,255,255,.08)">'+
            '<div style="flex:1;min-width:7rem"><strong>'+cfEsc(s.label||s.id)+'</strong>'+
            '<div style="font-size:.72rem;color:rgba(255,255,255,.4)">'+cfEsc(s.scriptName||'—')+'</div></div>'+
            '<div style="flex:2;color:#f87171;font-size:.75rem">'+cfEsc(s.error||'Add CF Account ID + API token')+'</div></div>';
        }
        var pct = Math.min(100, Math.round((Number(s.todayRequests||0)/Number(s.freeDailyLimit||100000))*100));
        var color = pct>=90?'#ef4444':(pct>=70?'#f59e0b':'#22c55e');
        return '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:.65rem;padding:.45rem 0;border-top:1px solid rgba(255,255,255,.08)">'+
          '<div style="flex:1;min-width:7rem"><strong>'+cfEsc(s.label||s.id)+'</strong>'+
          '<div style="font-size:.72rem;color:rgba(255,255,255,.4)">'+cfEsc(s.scriptName||'—')+'</div></div>'+
          '<div style="flex:1.5;min-width:9rem">'+
            '<div style="display:flex;justify-content:space-between;font-size:.72rem;color:rgba(255,255,255,.55);margin-bottom:.2rem">'+
              '<span style="color:#fff">'+Number(s.todayRequests||0).toLocaleString()+' / '+Number(s.freeDailyLimit||100000).toLocaleString()+'</span>'+
              '<span>'+pct+'%</span></div>'+
            '<div style="height:.28rem;border-radius:999px;background:rgba(255,255,255,.1);overflow:hidden"><div style="height:100%;width:'+pct+'%;background:'+color+'"></div></div>'+
          '</div>'+
          '<div style="font-size:.72rem;color:rgba(255,255,255,.45);white-space:nowrap">24h '+Number(s.last24hRequests||0).toLocaleString()+
            (s.todayErrors?(' · err '+s.todayErrors):'')+'</div></div>';
      }).join('');
    }).catch(function(){
      if(cfSummary) cfSummary.textContent = 'Failed to load Worker stats';
    });
  }
  if (cfRefresh) cfRefresh.addEventListener('click', function(){ loadCfStats(true); });
  loadCfStats(false);
  setInterval(function(){ loadCfStats(false); }, 60000);

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
