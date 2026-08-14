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
      <p>Create polls and site notifications. The header bell appears only when something is active. Guests are tracked for the browser session; signed-in users keep votes/reads on their account.</p>
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
          <p class="muted" style="margin:0 0 .35rem;font-size:.82rem;color:rgba(255,255,255,.5)">Poll options (one per line, min 2)</p>
          <textarea id="in-options" rows="4" placeholder="Option A&#10;Option B&#10;Option C" style="width:100%;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.55rem .7rem"></textarea>
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
<script>
(function(){
  var API = <?= json_encode(url('/api/admin/inbox')) ?>;
  var msg = document.getElementById('inbox-msg');
  var list = document.getElementById('inbox-list');
  function show(t, ok){ msg.hidden=false; msg.textContent=t; msg.className='cf-admin-msg'+(ok?' ok':''); }
  function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
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

  function render(items){
    if(!items || !items.length){ list.innerHTML = '<p class="muted" style="color:rgba(255,255,255,.45)">No inbox items yet.</p>'; return; }
    list.innerHTML = items.map(function(it){
      var opts = (it.options||[]).map(function(o){
        return '<li>'+esc(o.label)+' <span style="color:rgba(255,255,255,.45)">('+(o.voteCount||0)+')</span></li>';
      }).join('');
      var ends = it.endsAt ? new Date(it.endsAt*1000).toLocaleString() : '—';
      return '<div class="cf-admin-source" data-id="'+esc(it.id)+'" style="flex-direction:column;align-items:stretch;gap:.45rem">'+
        '<div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between">'+
          '<div><strong>'+esc(it.title)+'</strong> '+
          '<span style="color:rgba(255,255,255,.4)">('+esc(it.type)+' · '+esc(it.status)+')</span>'+
          (it.settings && it.settings.pin ? ' · pinned' : '')+
          '<div style="font-size:.78rem;color:rgba(255,255,255,.45);margin-top:.15rem">Likes '+(it.likeCount||0)+' · Dislikes '+(it.dislikeCount||0)+' · ends '+esc(ends)+'</div></div>'+
          '<div style="display:flex;gap:.35rem;flex-wrap:wrap">'+
            (it.status!=='active'?'<button type="button" class="cf-admin-btn ghost" data-act="publish">Publish</button>':'')+
            (it.status==='active'?'<button type="button" class="cf-admin-btn ghost" data-act="close">Close</button>':'')+
            '<button type="button" class="cf-admin-btn ghost" data-act="archive">Archive</button>'+
            '<button type="button" class="cf-admin-btn ghost" data-act="delete">Delete</button>'+
          '</div>'+
        '</div>'+
        (it.body ? '<div style="font-size:.86rem;color:rgba(255,255,255,.7)">'+esc(it.body)+'</div>' : '')+
        (opts ? '<ul style="margin:.2rem 0 0;padding-left:1.1rem;font-size:.84rem">'+opts+'</ul>' : '')+
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
      settings: settingsFromForm()
    };
    var ends = document.getElementById('in-ends').value;
    if(ends) payload.endsAt = Math.floor(new Date(ends).getTime()/1000);
    if(type==='poll'){
      payload.options = document.getElementById('in-options').value.split(/\n+/).map(function(s){ return s.trim(); }).filter(Boolean);
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
    var status = act==='publish' ? 'active' : (act==='close' ? 'closed' : 'archived');
    fetch(API+'/'+encodeURIComponent(id),{
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({status:status})
    }).then(r=>r.json()).then(function(d){ if(d&&d.ok){ show('Updated', true); load(); } else show('Update failed'); });
  });

  load();
})();
</script>
