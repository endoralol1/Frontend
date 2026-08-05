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
    <p class="muted" style="margin:.35rem 0 0;font-size:.82rem;color:rgba(255,255,255,.45)">
      Experimental: <b>UpCloud</b> (Byse/gn1r5n PoW→HLS) and <b>Vidmoly</b> scrapes HiMovies/0123movie → Vidmoly HLS (movies; HTTP only). Uses media-proxy; CDN may 403 datacenter IPs.
      <b>Stremify</b> (Hayduk) remains a Vuflix-only test scrape. Keep both disabled for normal traffic.
    </p>
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

    <div class="cf-admin-head" style="margin-top:2rem">
      <h1>Worker relay</h1>
      <p>Shared Cloudflare Workers for Chillflix + Vuflix (VAPlayer + Cineplay Yoru only — VixSrc is not on the Worker). Edit here or in Chillflix Stream Sources → Worker relay — same file.</p>
    </div>
    <div class="cf-admin-panel" id="relay-panel">
      <div class="cf-admin-msg" id="relay-msg" hidden></div>
      <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:center;margin-bottom:1rem">
        <label style="display:flex;align-items:center;gap:.45rem;cursor:pointer">
          <input type="checkbox" id="relay-enabled"> Worker relay enabled
        </label>
        <label style="display:flex;align-items:center;gap:.45rem;cursor:pointer">
          <input type="checkbox" id="relay-prefer"> Prefer Worker first
        </label>
        <label style="display:flex;align-items:center;gap:.45rem">
          Cache hours
          <input id="relay-ttl" type="number" min="0.1" step="0.5" value="2" style="width:4.5rem;min-height:2.3rem;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.35rem .5rem">
        </label>
      </div>
      <div id="relay-workers"></div>
      <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" class="cf-admin-btn ghost" id="relay-add">Add worker</button>
        <button type="button" class="cf-admin-btn" id="relay-save">Save relay settings</button>
      </div>
      <div style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="cf-admin-btn" id="relay-open" href="<?= e(url('/api/admin/worker-relay?download=view')) ?>" target="_blank" rel="noreferrer">Open yoru-relay.js</a>
        <button type="button" class="cf-admin-btn ghost" id="relay-copy">Copy script</button>
      </div>
      <p class="muted" style="margin:.75rem 0 0;font-size:.82rem;color:rgba(255,255,255,.45)">
        Second CF account: create Worker → open
        <a href="<?= e(url('/api/admin/worker-relay?download=view')) ?>" target="_blank" rel="noreferrer" style="color:#fff;text-decoration:underline">yoru-relay.js</a>
        → Ctrl+A / Ctrl+C → paste → set encrypted <code>YORU_RELAY_SECRET</code> → Deploy → Add URL + secret above → Test → Save.
        Config path: <code id="relay-path">/var/www/cinepro/config/worker-relay.json</code>
      </p>
    </div>
  </div>
</main>
<script>
(function(){
  var API = <?= json_encode(url('/api/admin/sources')) ?>;
  var RELAY = <?= json_encode(url('/api/admin/worker-relay')) ?>;
  var list = document.getElementById('src-list');
  var msg = document.getElementById('src-msg');
  var dragId = null;
  function show(t, ok){ msg.hidden=false; msg.textContent=t; msg.className='cf-admin-msg'+(ok?' ok':''); }
  function render(sources){
    list.innerHTML = (sources||[]).map(function(s){
      return '<div class="cf-admin-source'+(s.enabled?'':' is-off')+'" draggable="true" data-id="'+s.id+'">'+
        '<div class="meta"><strong>'+s.name+' <span style="color:rgba(255,255,255,.4)">('+s.id+')</span></strong>'+
        '<em>Public: '+s.publicLabel+(s.enabled?' · enabled':' · disabled')+'</em></div>'+
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
    });
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
    var inp=e.target.closest('[data-label]');
    if(!inp) return;
    var id=inp.getAttribute('data-label');
    fetch(API+'/'+encodeURIComponent(id), {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({publicLabel:inp.value})
    }).then(r=>r.json()).then(function(d){ if(d&&d.ok) show('Label saved', true); else show('Label save failed'); });
  });
  document.getElementById('src-save-order').onclick=function(){
    fetch(API+'/reorder', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({order:orderIds()})
    }).then(r=>r.json()).then(function(d){ if(d&&d.ok){ render(d.sources||[]); show('Order saved', true);} else show('Order save failed'); });
  };
  load();

  /* ---- Worker relay ---- */
  var relayMsg = document.getElementById('relay-msg');
  var relayWorkers = document.getElementById('relay-workers');
  var relayState = { enabled:true, preferWorker:true, cacheTtlSeconds:7200, workers:[] };
  function rshow(t, ok){ relayMsg.hidden=false; relayMsg.textContent=t; relayMsg.className='cf-admin-msg'+(ok?' ok':''); }
  function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
  function renderRelay(){
    document.getElementById('relay-enabled').checked = !!relayState.enabled;
    document.getElementById('relay-prefer').checked = !!relayState.preferWorker;
    document.getElementById('relay-ttl').value = String(Math.round((relayState.cacheTtlSeconds||7200)/3600*10)/10);
    relayWorkers.innerHTML = (relayState.workers||[]).map(function(w,i){
      return '<div class="cf-admin-source" data-relay-i="'+i+'" style="flex-wrap:wrap;gap:.5rem">'+
        '<input data-f="label" value="'+esc(w.label)+'" placeholder="Label" style="width:8rem;min-height:2.3rem;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.35rem .5rem">'+
        '<input data-f="url" value="'+esc(w.url)+'" placeholder="https://….workers.dev" style="flex:1 1 16rem;min-height:2.3rem;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.35rem .5rem">'+
        '<input data-f="secret" value="'+esc(w.secret)+'" placeholder="Secret" style="flex:1 1 12rem;min-height:2.3rem;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.35rem .5rem">'+
        '<button type="button" class="cf-admin-switch'+(w.enabled?' on':'')+'" data-relay-toggle="'+i+'" aria-label="Toggle"></button>'+
        '<button type="button" class="cf-admin-btn ghost" data-relay-test="'+i+'">Test</button>'+
        '<button type="button" class="cf-admin-btn ghost" data-relay-remove="'+i+'">Remove</button>'+
      '</div>';
    }).join('') || '<p class="muted" style="color:rgba(255,255,255,.45)">No workers yet — add one.</p>';
  }
  function readRelayForm(){
    relayState.enabled = document.getElementById('relay-enabled').checked;
    relayState.preferWorker = document.getElementById('relay-prefer').checked;
    var hours = parseFloat(document.getElementById('relay-ttl').value)||2;
    relayState.cacheTtlSeconds = Math.round(Math.max(60, hours*3600));
    Array.prototype.forEach.call(relayWorkers.querySelectorAll('[data-relay-i]'), function(row){
      var i = parseInt(row.getAttribute('data-relay-i'),10);
      if(!relayState.workers[i]) return;
      var label = row.querySelector('[data-f="label"]');
      var url = row.querySelector('[data-f="url"]');
      var secret = row.querySelector('[data-f="secret"]');
      relayState.workers[i].label = label ? label.value : relayState.workers[i].label;
      relayState.workers[i].url = url ? url.value : relayState.workers[i].url;
      relayState.workers[i].secret = secret ? secret.value : relayState.workers[i].secret;
    });
  }
  function loadRelay(){
    fetch(RELAY,{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(!d||!(d.ok||d.success)||!d.config){ rshow('Failed to load worker relay'); return; }
      relayState = d.config;
      if(d.config.path) document.getElementById('relay-path').textContent = d.config.path;
      renderRelay();
    }).catch(function(){ rshow('Failed to load worker relay'); });
  }
  document.getElementById('relay-add').onclick=function(){
    readRelayForm();
    relayState.workers = relayState.workers||[];
    relayState.workers.push({
      id:'worker-'+Math.random().toString(36).slice(2,8),
      label:'New worker', url:'', secret:'', enabled:true
    });
    renderRelay();
  };
  document.getElementById('relay-save').onclick=function(){
    readRelayForm();
    var body = {
      enabled: relayState.enabled,
      preferWorker: relayState.preferWorker,
      cacheTtlSeconds: relayState.cacheTtlSeconds,
      workers: (relayState.workers||[]).map(function(w){
        return {
          id:w.id, label:w.label, url:w.url, enabled:!!w.enabled,
          secret: (w.secret&&String(w.secret).indexOf('…')>=0) ? '' : (w.secret||'')
        };
      })
    };
    rshow('Saving…');
    fetch(RELAY, {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(body)
    }).then(r=>r.json()).then(function(d){
      if(!d||!(d.ok||d.success)){ rshow(d&&d.error?d.error:'Save failed'); return; }
      relayState = d.config;
      renderRelay();
      rshow(d.restart&&d.restart.restarted ? 'Saved. Cinepro restarted.' : 'Saved.', true);
    }).catch(function(){ rshow('Save failed'); });
  };
  relayWorkers.addEventListener('click', function(e){
    var tog = e.target.closest('[data-relay-toggle]');
    if(tog){
      readRelayForm();
      var i = parseInt(tog.getAttribute('data-relay-toggle'),10);
      if(relayState.workers[i]) relayState.workers[i].enabled = !relayState.workers[i].enabled;
      renderRelay();
      return;
    }
    var rem = e.target.closest('[data-relay-remove]');
    if(rem){
      readRelayForm();
      var ri = parseInt(rem.getAttribute('data-relay-remove'),10);
      relayState.workers.splice(ri,1);
      renderRelay();
      return;
    }
    var test = e.target.closest('[data-relay-test]');
    if(test){
      readRelayForm();
      var ti = parseInt(test.getAttribute('data-relay-test'),10);
      var w = relayState.workers[ti];
      if(!w) return;
      rshow('Testing '+ (w.label||w.id) +'…');
      fetch(RELAY, {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
          action:'test', id:w.id, url:w.url,
          secret:(w.secret&&String(w.secret).indexOf('…')>=0)?'':w.secret
        })
      }).then(r=>r.json()).then(function(d){
        var result = d&&d.result;
        if(!d||!(d.ok||d.success)){ rshow(d&&d.error?d.error:'Test failed'); return; }
        rshow(result&&result.ok
          ? ('OK '+(w.label||w.id)+' (HTTP '+result.status+(result.cache?', cache '+result.cache:'')+')')
          : ('Failed: '+(result&&result.error||'unknown')), !!(result&&result.ok));
      }).catch(function(){ rshow('Test failed'); });
    }
  });
  document.getElementById('relay-copy').onclick=function(){
    rshow('Copying script…');
    fetch(RELAY+'?download=view',{credentials:'same-origin'}).then(function(r){
      if(!r.ok) throw new Error('open failed');
      return r.text();
    }).then(function(text){
      return navigator.clipboard.writeText(text).then(function(){
        rshow('yoru-relay.js copied — paste into Cloudflare Worker editor.', true);
      });
    }).catch(function(){ rshow('Copy failed'); });
  };
  loadRelay();
})();
</script>
