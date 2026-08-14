<?php
$adminUser = $adminUser ?? Auth::user();
?>
<main class="cf-admin page-pad-top">
  <div class="container cf-admin-wrap">
    <nav class="cf-admin-nav" aria-label="Admin">
      <a href="<?= e(url('/admin')) ?>">Dashboard</a>
      <a href="<?= e(url('/admin/users')) ?>">Users</a>
      <a href="<?= e(url('/admin/sources')) ?>">Sources</a>
      <a class="is-active" href="<?= e(url('/admin/inbox')) ?>">Inbox</a>
      <a href="<?= e(url('/home')) ?>">Site</a>
    </nav>

    <div class="cf-admin-head">
      <h1>Inbox · Polls &amp; notifications</h1>
      <p>Create polls and site notifications. Set vote counts and like/dislike totals from here. The header bell appears only while something is active.</p>
    </div>

    <div class="cf-admin-panel" id="inbox-create">
      <h2 style="margin:0 0 .75rem;font-size:1rem">Create</h2>
      <div class="cf-admin-msg" id="inbox-msg" hidden></div>
      <div style="display:grid;gap:.65rem">
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
          <label>Type
            <select id="in-type" style="min-height:2.3rem;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.35rem .5rem;margin-left:.35rem">
              <option value="notification">Notification</option>
              <option value="poll">Poll</option>
            </select>
          </label>
          <label>Status
            <select id="in-status" style="min-height:2.3rem;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.35rem .5rem;margin-left:.35rem">
              <option value="active">Publish now (active)</option>
              <option value="draft">Draft</option>
            </select>
          </label>
        </div>
        <input id="in-title" type="text" maxlength="200" placeholder="Title" style="width:100%;min-height:2.5rem;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.45rem .7rem">
        <textarea id="in-body" rows="3" placeholder="Message / poll description (optional)" style="width:100%;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.55rem .7rem;resize:vertical"></textarea>

        <div id="in-poll-opts" hidden>
          <p class="muted" style="margin:0 0 .35rem;font-size:.82rem;color:rgba(255,255,255,.5)">Poll options (one per line, min 2). Optional seed votes: <code style="color:rgba(255,255,255,.7)">Option A|24</code></p>
          <textarea id="in-options" rows="4" placeholder="Option A|10&#10;Option B|4&#10;Option C" style="width:100%;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.55rem .7rem"></textarea>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:.65rem;align-items:center">
          <label>Likes
            <input id="in-likes" type="number" min="0" value="0" style="width:5.5rem;min-height:2.2rem;margin-left:.35rem;border-radius:.55rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.25rem .45rem">
          </label>
          <label>Dislikes
            <input id="in-dislikes" type="number" min="0" value="0" style="width:5.5rem;min-height:2.2rem;margin-left:.35rem;border-radius:.55rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.25rem .45rem">
          </label>
        </div>

        <details open style="border:1px solid rgba(255,255,255,.08);border-radius:.85rem;padding:.65rem .8rem;background:rgba(255,255,255,.02)">
          <summary style="cursor:pointer;font-weight:650">Advanced settings</summary>
          <div style="display:grid;gap:.45rem;margin-top:.65rem;font-size:.86rem">
            <label style="display:flex;gap:.45rem;align-items:center"><input type="checkbox" id="in-allow-guests" checked> Allow guests (session)</label>
            <label style="display:flex;gap:.45rem;align-items:center"><input type="checkbox" id="in-allow-react" checked> Allow like / dislike</label>
            <label style="display:flex;gap:.45rem;align-items:center"><input type="checkbox" id="in-pin"> Pin to top of dropdown</label>
            <label style="display:flex;gap:.45rem;align-items:center"><input type="checkbox" id="in-req-auth-vote"> Require sign-in to vote</label>
            <label style="display:flex;gap:.45rem;align-items:center"><input type="checkbox" id="in-req-auth-react"> Require sign-in to react</label>
            <label style="display:flex;gap:.45rem;align-items:center" id="in-multi-wrap" hidden><input type="checkbox" id="in-multi"> Allow multiple poll answers</label>
            <label>Show results
              <select id="in-show-results" style="margin-left:.35rem;min-height:2.1rem;border-radius:.55rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.25rem .4rem">
                <option value="after_vote">After vote</option>
                <option value="always">Always</option>
                <option value="after_close">After close</option>
                <option value="never">Never</option>
              </select>
            </label>
            <label>Ends at (optional)
              <input id="in-ends" type="datetime-local" style="margin-left:.35rem;min-height:2.1rem;border-radius:.55rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.25rem .4rem">
            </label>
          </div>
        </details>

        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button type="button" class="cf-admin-btn" id="in-save">Create</button>
          <button type="button" class="cf-admin-btn ghost" id="in-refresh">Refresh list</button>
        </div>
      </div>
    </div>

    <div class="cf-admin-panel" style="margin-top:1.25rem">
      <h2 style="margin:0 0 .75rem;font-size:1rem">All items</h2>
      <div id="inbox-list">Loading…</div>
    </div>
  </div>
</main>
<style>
  .cf-inbox-admin-opts { display:grid; gap:.45rem; margin-top:.55rem; }
  .cf-inbox-admin-opt {
    display:grid; grid-template-columns:1fr auto auto; gap:.45rem .55rem; align-items:center;
    padding:.55rem .65rem; border-radius:.75rem; border:1px solid rgba(255,255,255,.08);
    background:rgba(0,0,0,.18);
  }
  .cf-inbox-admin-opt-label { color:#fff; font-size:.86rem; font-weight:650; }
  .cf-inbox-admin-opt-meta { color:rgba(255,255,255,.5); font-size:.75rem; margin-top:.15rem; }
  .cf-inbox-admin-bar {
    grid-column:1 / -1; height:.42rem; border-radius:999px; background:rgba(255,255,255,.08); overflow:hidden;
  }
  .cf-inbox-admin-bar > i {
    display:block; height:100%; background:linear-gradient(90deg,var(--cf-orange,#db6937),var(--cf-orange-deep,#c43c2e));
  }
  .cf-inbox-admin-num {
    width:4.6rem; min-height:2.1rem; border-radius:.55rem; border:1px solid rgba(255,255,255,.12);
    background:rgba(0,0,0,.28); color:#fff; padding:.25rem .4rem; text-align:center;
  }
  .cf-inbox-admin-reacts {
    display:flex; flex-wrap:wrap; gap:.55rem; align-items:center; margin-top:.55rem;
    font-size:.84rem; color:rgba(255,255,255,.7);
  }
  .cf-inbox-admin-total {
    margin-top:.35rem; font-size:.78rem; color:rgba(255,255,255,.48);
  }
  .cf-inbox-admin-ramp {
    margin-top:.55rem; padding:.7rem .75rem; border-radius:.85rem;
    border:1px dashed rgba(255,255,255,.14); background:rgba(255,255,255,.02);
    display:grid; gap:.55rem;
  }
  .cf-inbox-admin-ramp h3 {
    margin:0; font-size:.82rem; font-weight:750; color:rgba(255,255,255,.85);
    letter-spacing:.04em; text-transform:uppercase;
  }
  .cf-inbox-admin-ramp-row {
    display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; font-size:.84rem;
  }
  .cf-inbox-admin-ramp-status {
    font-size:.78rem; color:rgba(255,255,255,.55); line-height:1.35;
  }
  .cf-inbox-admin-ramp-status strong { color:#ffd2b8; font-weight:700; }
</style>
<script>
(function(){
  var API = <?= json_encode(url('/api/admin/inbox')) ?>;
  var msg = document.getElementById('inbox-msg');
  var list = document.getElementById('inbox-list');
  function show(t, ok){ msg.hidden=false; msg.textContent=t; msg.className='cf-admin-msg'+(ok?' ok':''); }
  function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
  function fmtRemain(sec){
    sec = Math.max(0, sec|0);
    if(sec < 60) return sec+'s';
    if(sec < 3600) return Math.round(sec/60)+'m';
    var h = Math.floor(sec/3600), m = Math.round((sec%3600)/60);
    return h+'h'+(m?(' '+m+'m'):'');
  }
  function settingsFromForm(){
    return {
      allowGuests: document.getElementById('in-allow-guests').checked,
      allowReactions: document.getElementById('in-allow-react').checked,
      pin: document.getElementById('in-pin').checked,
      requireAuthToVote: document.getElementById('in-req-auth-vote').checked,
      requireAuthToReact: document.getElementById('in-req-auth-react').checked,
      allowMultiple: document.getElementById('in-multi').checked,
      showResults: document.getElementById('in-show-results').value
    };
  }
  function syncType(){
    var poll = document.getElementById('in-type').value === 'poll';
    document.getElementById('in-poll-opts').hidden = !poll;
    document.getElementById('in-multi-wrap').hidden = !poll;
  }
  document.getElementById('in-type').onchange = syncType;
  syncType();

  function parseOptionLines(text){
    return String(text||'').split(/\n+/).map(function(line){
      line = line.trim();
      if(!line) return null;
      var m = line.match(/^(.*)\|(\d+)\s*$/);
      if(m) return { label: m[1].trim(), voteCount: parseInt(m[2],10)||0 };
      return { label: line, voteCount: 0 };
    }).filter(Boolean);
  }

  function render(items){
    if(!items || !items.length){ list.innerHTML = '<p class="muted" style="color:rgba(255,255,255,.45)">No inbox items yet.</p>'; return; }
    list.innerHTML = items.map(function(it){
      var totalVotes = 0;
      (it.options||[]).forEach(function(o){ totalVotes += (o.voteCount||0); });
      var opts = (it.options||[]).map(function(o){
        var vc = o.voteCount||0;
        var pct = totalVotes > 0 ? Math.round((vc/totalVotes)*100) : 0;
        return '<div class="cf-inbox-admin-opt" data-opt="'+esc(o.id)+'">'+
          '<div><div class="cf-inbox-admin-opt-label">'+esc(o.label)+'</div>'+
          '<div class="cf-inbox-admin-opt-meta">'+vc+' vote'+(vc===1?'':'s')+' · '+pct+'%</div></div>'+
          '<label style="font-size:.7rem;color:rgba(255,255,255,.45);display:grid;gap:.15rem;justify-items:center">Now<input class="cf-inbox-admin-num" type="number" min="0" value="'+vc+'" data-vote-input aria-label="Votes for '+esc(o.label)+'"></label>'+
          '<label style="font-size:.7rem;color:rgba(255,255,255,.45);display:grid;gap:.15rem;justify-items:center">Add+<input class="cf-inbox-admin-num" type="number" min="0" value="0" data-ramp-add aria-label="Gradual add for '+esc(o.label)+'"></label>'+
          '<div class="cf-inbox-admin-bar" aria-hidden="true" style="grid-column:1/-1"><i style="width:'+pct+'%"></i></div>'+
        '</div>';
      }).join('');
      var ramps = it.ramps || [];
      var rampStatus = ramps.length
        ? ramps.map(function(r){
            var label = r.kind === 'option' ? 'option' : r.kind;
            if(r.kind==='option' && r.optionId){
              var match = (it.options||[]).find(function(o){ return o.id===r.optionId; });
              if(match) label = match.label;
            }
            return '<div>· <strong>'+esc(label)+'</strong>: '+r.lastApplied+' → '+r.targetCount+
              ' ('+Math.round((r.progress||0)*100)+'%, '+fmtRemain(r.remainingSec)+' left, '+esc(r.curve)+')</div>';
          }).join('')
        : '<div>No active gradual ramps.</div>';
      var ends = it.endsAt ? new Date(it.endsAt*1000).toLocaleString() : '—';
      return '<div class="cf-admin-source" data-id="'+esc(it.id)+'" style="flex-direction:column;align-items:stretch;gap:.45rem">'+
        '<div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between">'+
          '<div><strong>'+esc(it.title)+'</strong> '+
          '<span style="color:rgba(255,255,255,.4)">('+esc(it.type)+' · '+esc(it.status)+')</span>'+
          (it.settings && it.settings.pin ? ' · pinned' : '')+
          '<div style="font-size:.78rem;color:rgba(255,255,255,.45);margin-top:.15rem">Ends '+esc(ends)+'</div></div>'+
          '<div style="display:flex;gap:.35rem;flex-wrap:wrap">'+
            (it.status!=='active'?'<button type="button" class="cf-admin-btn ghost" data-act="publish">Publish</button>':'')+
            (it.status==='active'?'<button type="button" class="cf-admin-btn ghost" data-act="close">Close</button>':'')+
            '<button type="button" class="cf-admin-btn ghost" data-act="archive">Archive</button>'+
            '<button type="button" class="cf-admin-btn ghost" data-act="delete">Delete</button>'+
          '</div>'+
        '</div>'+
        (it.body ? '<div style="font-size:.86rem;color:rgba(255,255,255,.7)">'+esc(it.body)+'</div>' : '')+
        (opts ? '<div class="cf-inbox-admin-opts">'+opts+'</div><div class="cf-inbox-admin-total">Total poll votes: <strong style="color:#fff">'+(it.totalVotes!=null?it.totalVotes:totalVotes)+'</strong></div>' : '')+
        '<div class="cf-inbox-admin-reacts">'+
          '<label>Likes <input class="cf-inbox-admin-num" type="number" min="0" value="'+(it.likeCount||0)+'" data-like-input></label>'+
          '<label>Dislikes <input class="cf-inbox-admin-num" type="number" min="0" value="'+(it.dislikeCount||0)+'" data-dislike-input></label>'+
          '<button type="button" class="cf-admin-btn" data-act="save-counts">Save counts now</button>'+
        '</div>'+
        '<div class="cf-inbox-admin-ramp">'+
          '<h3>Gradual add (looks realistic)</h3>'+
          '<div class="cf-inbox-admin-ramp-row">'+
            '<label>Over '+
              '<select data-ramp-duration style="min-height:2.1rem;border-radius:.55rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.25rem .4rem;margin-left:.25rem">'+
                '<option value="900">15 minutes</option>'+
                '<option value="1800">30 minutes</option>'+
                '<option value="3600" selected>1 hour</option>'+
                '<option value="10800">3 hours</option>'+
                '<option value="21600">6 hours</option>'+
                '<option value="43200">12 hours</option>'+
                '<option value="86400">24 hours</option>'+
              '</select>'+
            '</label>'+
            '<label>Style '+
              '<select data-ramp-curve style="min-height:2.1rem;border-radius:.55rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.25rem .4rem;margin-left:.25rem">'+
                '<option value="bursty" selected>Bursty (most realistic)</option>'+
                '<option value="linear">Linear</option>'+
                '<option value="ease_out">Fast then slow</option>'+
                '<option value="ease_in">Slow then fast</option>'+
              '</select>'+
            '</label>'+
          '</div>'+
          '<div class="cf-inbox-admin-ramp-row">'+
            '<label>Add likes <input class="cf-inbox-admin-num" type="number" min="0" value="0" data-ramp-likes></label>'+
            '<label>Add dislikes <input class="cf-inbox-admin-num" type="number" min="0" value="0" data-ramp-dislikes></label>'+
            '<button type="button" class="cf-admin-btn" data-act="start-ramp">Start gradual</button>'+
            '<button type="button" class="cf-admin-btn ghost" data-act="cancel-ramp">Cancel ramps</button>'+
          '</div>'+
          '<p style="margin:0;font-size:.75rem;color:rgba(255,255,255,.45)">Use <em>Add+</em> on each poll option (e.g. 15) plus duration (e.g. 1 hour). Votes drip in over time instead of jumping at once.</p>'+
          '<div class="cf-inbox-admin-ramp-status">'+rampStatus+'</div>'+
        '</div>'+
      '</div>';
    }).join('');
  }

  function load(){
    fetch(API,{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(!d||!d.ok){ list.textContent='Failed'; return; }
      render(d.items||[]);
    }).catch(function(){ list.textContent='Failed'; });
  }

  document.getElementById('in-refresh').onclick = load;
  document.getElementById('in-save').onclick = function(){
    var type = document.getElementById('in-type').value;
    var payload = {
      type: type,
      title: document.getElementById('in-title').value.trim(),
      body: document.getElementById('in-body').value.trim(),
      status: document.getElementById('in-status').value,
      settings: settingsFromForm(),
      likeCount: parseInt(document.getElementById('in-likes').value,10)||0,
      dislikeCount: parseInt(document.getElementById('in-dislikes').value,10)||0
    };
    var ends = document.getElementById('in-ends').value;
    if(ends) payload.endsAt = Math.floor(new Date(ends).getTime()/1000);
    if(type==='poll'){
      payload.options = parseOptionLines(document.getElementById('in-options').value);
    }
    if(!payload.title){ show('Title required'); return; }
    show('Saving…');
    fetch(API,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
      .then(r=>r.json()).then(function(d){
        if(!d||!d.ok){ show((d&&d.error)||'Save failed'); return; }
        show('Created', true);
        document.getElementById('in-title').value='';
        document.getElementById('in-body').value='';
        document.getElementById('in-options').value='';
        document.getElementById('in-likes').value='0';
        document.getElementById('in-dislikes').value='0';
        load();
      }).catch(function(){ show('Save failed'); });
  };

  list.addEventListener('click', function(e){
    var btn = e.target.closest('[data-act]');
    if(!btn) return;
    var row = btn.closest('[data-id]');
    if(!row) return;
    var id = row.getAttribute('data-id');
    var act = btn.getAttribute('data-act');
    if(act==='delete'){
      if(!confirm('Delete this item?')) return;
      fetch(API+'/'+encodeURIComponent(id),{method:'DELETE',credentials:'same-origin'})
        .then(r=>r.json()).then(function(d){ if(d&&d.ok){ show('Deleted', true); load(); } else show('Delete failed'); });
      return;
    }
    if(act==='save-counts'){
      var optionVotes = [];
      row.querySelectorAll('.cf-inbox-admin-opt').forEach(function(opt){
        var oid = opt.getAttribute('data-opt');
        var inp = opt.querySelector('[data-vote-input]');
        if(!oid || !inp) return;
        optionVotes.push({ id: oid, voteCount: parseInt(inp.value,10)||0 });
      });
      var likeEl = row.querySelector('[data-like-input]');
      var dislikeEl = row.querySelector('[data-dislike-input]');
      var body = {
        likeCount: likeEl ? (parseInt(likeEl.value,10)||0) : 0,
        dislikeCount: dislikeEl ? (parseInt(dislikeEl.value,10)||0) : 0
      };
      if(optionVotes.length) body.optionVotes = optionVotes;
      btn.disabled = true;
      fetch(API+'/'+encodeURIComponent(id),{
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify(body)
      }).then(r=>r.json()).then(function(d){
        btn.disabled = false;
        if(d&&d.ok){ show('Counts saved', true); load(); }
        else show((d&&d.error)||'Save failed');
      }).catch(function(){ btn.disabled=false; show('Save failed'); });
      return;
    }
    if(act==='start-ramp'){
      var options = [];
      row.querySelectorAll('.cf-inbox-admin-opt').forEach(function(opt){
        var oid = opt.getAttribute('data-opt');
        var addInp = opt.querySelector('[data-ramp-add]');
        var add = addInp ? (parseInt(addInp.value,10)||0) : 0;
        if(oid && add > 0) options.push({ id: oid, add: add });
      });
      var durEl = row.querySelector('[data-ramp-duration]');
      var curveEl = row.querySelector('[data-ramp-curve]');
      var likesEl = row.querySelector('[data-ramp-likes]');
      var dislikesEl = row.querySelector('[data-ramp-dislikes]');
      var payload = {
        durationSec: durEl ? (parseInt(durEl.value,10)||3600) : 3600,
        curve: curveEl ? curveEl.value : 'bursty',
        options: options,
        likes: likesEl ? (parseInt(likesEl.value,10)||0) : 0,
        dislikes: dislikesEl ? (parseInt(dislikesEl.value,10)||0) : 0
      };
      if(!payload.options.length && !payload.likes && !payload.dislikes){
        show('Set Add+ on options and/or likes/dislikes first');
        return;
      }
      btn.disabled = true;
      fetch(API+'/'+encodeURIComponent(id)+'/ramp',{
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify(payload)
      }).then(r=>r.json()).then(function(d){
        btn.disabled = false;
        if(d&&d.ok){ show('Gradual ramp started', true); load(); }
        else show((d&&d.error)||'Ramp failed');
      }).catch(function(){ btn.disabled=false; show('Ramp failed'); });
      return;
    }
    if(act==='cancel-ramp'){
      btn.disabled = true;
      fetch(API+'/'+encodeURIComponent(id)+'/ramp/cancel',{
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: '{}'
      }).then(r=>r.json()).then(function(d){
        btn.disabled = false;
        if(d&&d.ok){ show('Ramps cancelled', true); load(); }
        else show('Cancel failed');
      }).catch(function(){ btn.disabled=false; show('Cancel failed'); });
      return;
    }
    var status = act==='publish' ? 'active' : (act==='close' ? 'closed' : 'archived');
    fetch(API+'/'+encodeURIComponent(id),{
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({status:status})
    }).then(r=>r.json()).then(function(d){ if(d&&d.ok){ show('Updated', true); load(); } else show('Update failed'); });
  });

  load();
  // Keep ramp progress fresh while admin page is open.
  setInterval(load, 30000);
})();
</script>
