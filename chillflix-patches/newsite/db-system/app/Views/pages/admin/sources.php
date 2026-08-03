<?php
$headerClass = 'relative';
$adminUser = $adminUser ?? Auth::user();
?>
<main class="cf-admin page-pad-top">
  <div class="container cf-admin-wrap">
    <nav class="cf-admin-nav" aria-label="Admin">
      <a href="<?= e(url('/admin')) ?>">Dashboard</a>
      <a href="<?= e(url('/admin/users')) ?>">Users</a>
      <a class="is-active" href="<?= e(url('/admin/sources')) ?>">Sources</a>
      <a href="<?= e(url('/home')) ?>">Site</a>
    </nav>
    <div class="cf-admin-head">
      <h1>Sources</h1>
      <p>Enable/disable, drag order (top = tested first), and probe with any movie/TV TMDB id. Users see Alpha/Beta labels.</p>
    </div>
    <div class="cf-admin-panel" id="rd-panel" hidden>
      <h2 style="margin:0 0 .35rem;font-size:1.05rem">RealDebrid (Vuflix)</h2>
      <p style="margin:0 0 .75rem;color:rgba(255,255,255,.55);font-size:.86rem;line-height:1.4">
        <strong style="color:#fbbf24">Save your API key first</strong> — enabling RealDebrid without a saved key makes the player say sources failed.
        Learning flow: Torrentio finds torrents → RealDebrid unlocks HTTP links → your player plays them.
        Cached titles start fast; uncached ones wait on RD’s side (not this server).
        Get a key at <a href="https://real-debrid.com/apitoken" target="_blank" rel="noopener" style="color:#ffb3bb">real-debrid.com/apitoken</a>.
      </p>
      <div class="cf-admin-toolbar" style="align-items:center">
        <input id="rd-api-key" type="password" placeholder="RealDebrid API key" style="flex:1 1 16rem;min-height:2.4rem;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.45rem .65rem" autocomplete="off">
        <button type="button" class="cf-admin-btn" id="rd-save">Save key</button>
        <button type="button" class="cf-admin-btn ghost" id="rd-clear">Clear</button>
      </div>
      <p id="rd-status" style="margin:.55rem 0 0;font-size:.82rem;color:rgba(255,255,255,.5)"></p>
    </div>
    <div class="cf-admin-panel">
      <div class="cf-admin-toolbar">
        <select id="test-type"><option value="movie">Movie</option><option value="tv">TV</option></select>
        <input id="test-tmdb" type="number" min="1" placeholder="TMDB id (e.g. 550)" style="flex:0 1 10rem">
        <input id="test-season" type="number" min="1" value="1" placeholder="S" style="flex:0 0 4.5rem" title="Season">
        <input id="test-episode" type="number" min="1" value="1" placeholder="E" style="flex:0 0 4.5rem" title="Episode">
      </div>
      <div class="cf-admin-msg" id="src-msg" hidden></div>
      <div id="src-list">Loading…</div>
      <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" class="cf-admin-btn" id="src-save-order">Save order</button>
      </div>
    </div>
  </div>
</main>
<script>
(function(){
  var API = <?= json_encode(url('/api/admin/sources')) ?>;
  var list = document.getElementById('src-list');
  var msg = document.getElementById('src-msg');
  var dragId = null;
  function show(t, ok){ msg.hidden=false; msg.textContent=t; msg.className='cf-admin-msg'+(ok?' ok':''); }
  function render(sources){
    list.innerHTML = (sources||[]).map(function(s){
      var needsKey = (s.id === 'realdebrid' && window.__rdConfigured === false);
      return '<div class="cf-admin-source'+(s.enabled?'':' is-off')+'" draggable="true" data-id="'+s.id+'">'+
        '<div class="meta"><strong>'+s.name+' <span style="color:rgba(255,255,255,.4)">('+s.id+')</span></strong>'+
        (needsKey ? '<em style="color:#fbbf24">API key missing — Save key above before testing</em>' : '<em>Public: '+s.publicLabel+(s.enabled?' · enabled':' · disabled')+'</em>')+'</div>'+
        '<button type="button" class="cf-admin-switch'+(s.enabled?' on':'')+'" data-toggle="'+s.id+'" aria-label="Toggle"></button>'+
        '<button type="button" class="cf-admin-btn ghost" data-test="'+s.id+'">Test</button>'+
        '<input data-label="'+s.id+'" value="'+(s.publicLabel||'').replace(/"/g,'&quot;')+'" style="width:7rem;min-height:2.3rem;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.35rem .5rem" title="Public label">'+
      '</div>';
    }).join('');
  }
  function load(){
    fetch(API,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(!d||!d.ok){ list.textContent='Failed'; return; }
      render(d.sources||[]);
      var rd = d.realdebrid;
      window.__rdConfigured = !!(rd && rd.configured);
      var panel = document.getElementById('rd-panel');
      var status = document.getElementById('rd-status');
      if (panel && rd && rd.hostAllowed) {
        panel.hidden = false;
        status.textContent = rd.configured
          ? ('Key saved: ' + (rd.maskedKey || '••••') + ' — enable RealDebrid below and Test with a TMDB id')
          : 'No API key yet — paste one and Save, then enable RealDebrid in the list';
        // re-render so the missing-key badge updates after load/save
        render(d.sources||[]);
      } else if (panel) {
        panel.hidden = true;
      }
    });
  }
  var rdSave = document.getElementById('rd-save');
  var rdClear = document.getElementById('rd-clear');
  var rdKey = document.getElementById('rd-api-key');
  var rdApi = <?= json_encode(url('/api/admin/realdebrid')) ?>;
  if (rdSave && rdKey) {
    rdSave.onclick = function () {
      var key = (rdKey.value || '').trim();
      if (!key) { show('Paste an API key first'); return; }
      show('Validating RealDebrid key…');
      fetch(rdApi, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({apiKey: key})
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok) { show((d && d.error) || 'Save failed'); return; }
        rdKey.value = '';
        show('RealDebrid key saved' + (d.username ? (' · ' + d.username) : ''), true);
        load();
      }).catch(function () { show('Save failed'); });
    };
  }
  if (rdClear) {
    rdClear.onclick = function () {
      fetch(rdApi, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({apiKey: ''})
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.ok) { show('RealDebrid key cleared', true); load(); }
        else show('Clear failed');
      });
    };
  }
  function orderIds(){
    return Array.prototype.map.call(list.querySelectorAll('.cf-admin-source'), function(el){ return el.getAttribute('data-id'); });
  }
  list.addEventListener('dragstart', function(e){
    var row=e.target.closest('.cf-admin-source'); if(!row) return;
    dragId=row.getAttribute('data-id'); e.dataTransfer.effectAllowed='move';
  });
  list.addEventListener('dragover', function(e){
    e.preventDefault();
    var row=e.target.closest('.cf-admin-source'); if(!row||!dragId) return;
    var dragging=list.querySelector('[data-id="'+dragId+'"]');
    if(!dragging||dragging===row) return;
    var rect=row.getBoundingClientRect();
    var before=(e.clientY-rect.top) < rect.height/2;
    list.insertBefore(dragging, before?row:row.nextSibling);
  });
  list.addEventListener('click', function(e){
    var t=e.target.closest('[data-toggle]');
    if(t){
      var id=t.getAttribute('data-toggle');
      var on=!t.classList.contains('on');
      fetch(API+'/'+encodeURIComponent(id), {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({enabled:on})
      }).then(r=>r.json()).then(function(d){ if(d&&d.ok){ render(d.sources); show('Updated', true);} else show('Toggle failed'); });
      return;
    }
    var test=e.target.closest('[data-test]');
    if(test){
      var id=test.getAttribute('data-test');
      var type=document.getElementById('test-type').value;
      var tmdbId=parseInt(document.getElementById('test-tmdb').value,10)||0;
      if(!tmdbId){ show('Enter a TMDB id'); return; }
      show('Testing '+id+'…');
      fetch(API+'/'+encodeURIComponent(id)+'/test', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
          type:type, tmdbId:tmdbId,
          season:parseInt(document.getElementById('test-season').value,10)||1,
          episode:parseInt(document.getElementById('test-episode').value,10)||1
        })
      }).then(r=>r.json()).then(function(d){
        if(!d){ show('Test failed'); return; }
        show((d.message|| (d.ok?'OK':'Fail')) + (d.sourceCount!=null?(' · '+d.sourceCount+' streams'):''), !!d.ok);
      }).catch(function(){ show('Test failed'); });
    }
  });
  list.addEventListener('change', function(e){
    var inp=e.target.closest('[data-label]'); if(!inp) return;
    var id=inp.getAttribute('data-label');
    fetch(API+'/'+encodeURIComponent(id), {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({publicLabel: inp.value})
    }).then(r=>r.json()).then(function(d){ if(d&&d.ok) show('Label saved', true); });
  });
  document.getElementById('src-save-order').onclick=function(){
    fetch(API+'/reorder', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({order: orderIds()})
    }).then(r=>r.json()).then(function(d){
      if(d&&d.ok){ render(d.sources); show('Order saved — top is tested first', true); }
      else show('Save order failed');
    });
  };
  load();
})();
</script>
