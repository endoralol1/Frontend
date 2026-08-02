from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
ver = "20260802-ui98"

USERS = r'''<?php
$headerClass = 'relative';
$adminUser = $adminUser ?? Auth::user();
$isAdmin = Auth::isAdmin($adminUser);
?>
<main class="cf-admin page-pad-top">
  <div class="container cf-admin-wrap">
    <nav class="cf-admin-nav" aria-label="Admin">
      <a href="<?= e(url('/admin')) ?>">Dashboard</a>
      <a class="is-active" href="<?= e(url('/admin/users')) ?>">Users</a>
      <a href="<?= e(url('/admin/sources')) ?>">Sources</a>
      <a href="<?= e(url('/home')) ?>">Site</a>
    </nav>
    <div class="cf-admin-head">
      <h1>Users</h1>
      <p>Preview, edit role/status, or remove accounts.</p>
    </div>

    <section class="cf-admin-work">
      <div class="cf-admin-toolbar">
        <input id="users-q" type="search" placeholder="Search name, email, id…">
        <button type="button" class="cf-admin-btn" id="users-search">Search</button>
      </div>
      <div class="cf-admin-msg" id="users-msg" hidden></div>
      <div class="cf-admin-table-wrap">
        <table class="cf-admin-table">
          <thead><tr><th>User</th><th>Role</th><th>Status</th><th></th></tr></thead>
          <tbody id="users-body"><tr><td colspan="4">Loading…</td></tr></tbody>
        </table>
      </div>
      <div class="cf-admin-drawer" id="user-drawer" hidden></div>
    </section>
  </div>
</main>
<script>
(function(){
  var API = <?= json_encode(url('/api/admin/users')) ?>;
  var canRole = <?= $isAdmin ? 'true' : 'false' ?>;
  var msg = document.getElementById('users-msg');
  var body = document.getElementById('users-body');
  var drawer = document.getElementById('user-drawer');
  function show(t, ok){ msg.hidden=false; msg.textContent=t; msg.className='cf-admin-msg'+(ok?' ok':''); }
  function load(q){
    fetch(API + (q ? ('?q='+encodeURIComponent(q)) : ''), {credentials:'same-origin'})
      .then(r=>r.json()).then(d=>{
        if(!d||!d.ok){ body.innerHTML='<tr><td colspan="4">Failed</td></tr>'; return; }
        body.innerHTML = (d.users||[]).map(function(u){
          return '<tr>'+
            '<td><div class="cf-admin-usercell"><span class="cf-admin-avatar">'+(String(u.name||'?').charAt(0).toUpperCase())+'</span><span><strong>'+(u.name||'')+'</strong><em>'+(u.email||'')+'</em></span></div></td>'+
            '<td><span class="cf-admin-role '+(u.role||'')+'">'+(u.role||'')+'</span></td>'+
            '<td><span class="cf-admin-status '+(u.status||'')+'">'+(u.status||'')+'</span></td>'+
            '<td><button type="button" class="cf-admin-btn ghost" data-open="'+(u.id||'')+'">Open</button></td>'+
          '</tr>';
        }).join('') || '<tr><td colspan="4">No users</td></tr>';
      });
  }
  function openUser(id){
    fetch(API+'/'+encodeURIComponent(id), {credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(!d||!d.ok){ show('Load failed'); return; }
      var u=d.user||{};
      drawer.hidden=false;
      drawer.innerHTML =
        '<div class="cf-admin-drawer-head"><h3>Edit user</h3><button type="button" class="cf-admin-btn ghost" id="eu-close">Close</button></div>'+
        '<div class="cf-admin-drawer-grid">'+
        '<label class="cf-admin-field"><span>Name</span><input id="eu-name" value="'+(u.name||'').replace(/"/g,'&quot;')+'"></label>'+
        '<label class="cf-admin-field"><span>Email</span><input id="eu-email" value="'+(u.email||'').replace(/"/g,'&quot;')+'"></label>'+
        '<label class="cf-admin-field"><span>Status</span><select id="eu-status"><option value="active">active</option><option value="suspended">suspended</option></select></label>'+
        (canRole ? '<label class="cf-admin-field"><span>Role</span><select id="eu-role"><option value="user">user</option><option value="moderator">moderator</option><option value="admin">admin</option></select></label>' : '')+
        '<label class="cf-admin-field cf-admin-field-wide"><span>New password</span><input id="eu-pass" type="password" placeholder="Leave blank to keep"></label>'+
        '</div>'+
        '<div class="cf-admin-drawer-actions">'+
          '<button type="button" class="cf-admin-btn" id="eu-save">Save</button>'+
          (canRole ? '<button type="button" class="cf-admin-btn danger" id="eu-del">Delete</button>' : '')+
        '</div>'+
        '<p class="cf-admin-drawer-meta">Favorites: '+((d.favorites||[]).length)+' · Continue: '+((d.continueWatching||[]).length)+'</p>'+
        '<pre class="cf-admin-pre">'+(
          JSON.stringify({favorites:d.favorites||[], continueWatching:d.continueWatching||[]}, null, 2)
        )+'</pre>';
      drawer.querySelector('#eu-status').value = u.status || 'active';
      if (canRole) drawer.querySelector('#eu-role').value = u.role || 'user';
      drawer.querySelector('#eu-close').onclick = function(){ drawer.hidden=true; };
      drawer.querySelector('#eu-save').onclick = function(){
        var payload = {
          name: drawer.querySelector('#eu-name').value,
          email: drawer.querySelector('#eu-email').value,
          status: drawer.querySelector('#eu-status').value,
          password: drawer.querySelector('#eu-pass').value
        };
        if (canRole) payload.role = drawer.querySelector('#eu-role').value;
        fetch(API+'/'+encodeURIComponent(id), {
          method:'POST', credentials:'same-origin',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify(payload)
        }).then(r=>r.json()).then(function(res){
          if(!res||!res.ok){ show((res&&res.error)||'Save failed'); return; }
          show('Saved', true); load(document.getElementById('users-q').value.trim()); openUser(id);
        });
      };
      var del = drawer.querySelector('#eu-del');
      if (del) del.onclick = function(){
        if(!confirm('Delete this user permanently?')) return;
        fetch(API+'/'+encodeURIComponent(id)+'/delete', {
          method:'POST', credentials:'same-origin',
          headers:{'Content-Type':'application/json'}, body:'{}'
        }).then(r=>r.json()).then(function(res){
          if(!res||!res.ok){ show((res&&res.error)||'Delete failed'); return; }
          show('Deleted', true); drawer.hidden=true; load('');
        });
      };
    });
  }
  document.getElementById('users-search').onclick = function(){ load(document.getElementById('users-q').value.trim()); };
  document.getElementById('users-q').addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); load(this.value.trim()); }});
  body.addEventListener('click', function(e){
    var btn = e.target.closest('[data-open]'); if(!btn) return;
    openUser(btn.getAttribute('data-open'));
  });
  load('');
})();
</script>
'''

SOURCES = r'''<?php
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

    <section class="cf-admin-work">
      <div class="cf-admin-toolbar cf-admin-toolbar-sources">
        <select id="test-type"><option value="movie">Movie</option><option value="tv">TV</option></select>
        <input id="test-tmdb" type="number" min="1" placeholder="TMDB id (e.g. 550)">
        <input id="test-season" type="number" min="1" value="1" placeholder="S" title="Season">
        <input id="test-episode" type="number" min="1" value="1" placeholder="E" title="Episode">
      </div>
      <div class="cf-admin-msg" id="src-msg" hidden></div>
      <div class="cf-admin-source-list" id="src-list">Loading…</div>
      <div class="cf-admin-work-foot">
        <button type="button" class="cf-admin-btn" id="src-save-order">Save order</button>
      </div>
    </section>
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
    list.innerHTML = (sources||[]).map(function(s, idx){
      return '<div class="cf-admin-source'+(s.enabled?'':' is-off')+'" draggable="true" data-id="'+s.id+'">'+
        '<span class="cf-admin-source-handle" aria-hidden="true"><i class="uil uil-draggabledots"></i><em>'+(idx+1)+'</em></span>'+
        '<div class="meta"><strong>'+s.name+' <span>('+s.id+')</span></strong>'+
        '<em>Public: '+s.publicLabel+(s.enabled?' · enabled':' · disabled')+'</em></div>'+
        '<button type="button" class="cf-admin-switch'+(s.enabled?' on':'')+'" data-toggle="'+s.id+'" aria-label="Toggle"></button>'+
        '<button type="button" class="cf-admin-btn ghost" data-test="'+s.id+'">Test</button>'+
        '<input class="cf-admin-label-input" data-label="'+s.id+'" value="'+(s.publicLabel||'').replace(/"/g,'&quot;')+'" title="Public label">'+
      '</div>';
    }).join('') || '<p class="cf-admin-empty">No sources configured.</p>';
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
    row.classList.add('is-dragging');
  });
  list.addEventListener('dragend', function(){
    var row=list.querySelector('.is-dragging'); if(row) row.classList.remove('is-dragging');
    dragId=null;
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
'''

CSS = r'''
/* ui98: Users/Sources content chrome (tabs stay new) */
.cf-admin-work {
  margin-top: 0.25rem;
  padding: 1rem;
  border-radius: 1.15rem;
  border: 1px solid rgba(255,255,255,.08);
  background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.015));
  box-shadow: 0 10px 28px rgba(0,0,0,.18);
}
.cf-admin-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem;
  margin-bottom: 0.85rem;
}
.cf-admin-toolbar input,
.cf-admin-toolbar select,
.cf-admin-field input,
.cf-admin-field select,
.cf-admin-field textarea,
.cf-admin-label-input {
  min-height: 2.5rem;
  padding: 0.55rem 0.75rem;
  border-radius: 0.85rem;
  border: 1px solid rgba(255,255,255,.1);
  background: rgba(0,0,0,.28);
  color: #fff;
  font: inherit;
}
.cf-admin-toolbar input { flex: 1; min-width: 12rem; }
.cf-admin-toolbar-sources #test-tmdb { flex: 1 1 9rem; min-width: 8rem; }
.cf-admin-toolbar-sources #test-season,
.cf-admin-toolbar-sources #test-episode { flex: 0 0 4.5rem; min-width: 4.5rem; }
.cf-admin-btn {
  appearance: none;
  border: 0;
  border-radius: 0.85rem;
  min-height: 2.5rem;
  padding: 0.55rem 0.95rem;
  background: linear-gradient(135deg, #db6937, #dc3545);
  color: #fff;
  font: inherit;
  font-weight: 750;
  cursor: pointer;
}
.cf-admin-btn.ghost {
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.1);
}
.cf-admin-btn.danger { background: linear-gradient(135deg, #a11, #dc3545); }
.cf-admin-msg { margin: 0.35rem 0 0.75rem; color: #ffd2b8; font-size: 0.85rem; }
.cf-admin-msg.ok { color: #7dffb0; }

.cf-admin-table-wrap {
  overflow: auto;
  border-radius: 0.95rem;
  border: 1px solid rgba(255,255,255,.07);
  background: rgba(0,0,0,.16);
}
.cf-admin-table { width: 100%; border-collapse: collapse; }
.cf-admin-table th,
.cf-admin-table td {
  text-align: left;
  padding: 0.85rem 0.9rem;
  border-bottom: 1px solid rgba(255,255,255,.07);
  color: rgba(255,255,255,.86);
  font-size: 0.86rem;
  vertical-align: middle;
}
.cf-admin-table th {
  color: rgba(255,255,255,.45);
  font-size: 0.72rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  background: rgba(255,255,255,.02);
}
.cf-admin-table tr:last-child td { border-bottom: 0; }
.cf-admin-table tr:hover td { background: rgba(219,105,55,.05); }
.cf-admin-usercell {
  display: flex;
  align-items: center;
  gap: 0.7rem;
}
.cf-admin-avatar {
  width: 2.2rem;
  height: 2.2rem;
  border-radius: 0.7rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(219,105,55,.18);
  color: #ffd2b8;
  font-weight: 800;
  flex-shrink: 0;
}
.cf-admin-usercell strong { display: block; color: #fff; font-weight: 700; }
.cf-admin-usercell em {
  display: block;
  color: rgba(255,255,255,.45);
  font-size: 0.75rem;
  font-style: normal;
}
.cf-admin-role {
  display: inline-flex;
  padding: 0.18rem 0.55rem;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 750;
  border: 1px solid rgba(255,255,255,.12);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.cf-admin-role.admin { color: #ffd2b8; border-color: rgba(219,105,55,.45); background: rgba(219,105,55,.14); }
.cf-admin-role.moderator { color: #b8e0ff; border-color: rgba(80,160,255,.4); background: rgba(80,160,255,.12); }
.cf-admin-role.user { color: rgba(255,255,255,.7); }
.cf-admin-status {
  display: inline-flex;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 650;
  text-transform: capitalize;
  background: rgba(255,255,255,.06);
  color: rgba(255,255,255,.75);
}
.cf-admin-status.active { color: #7dffb0; background: rgba(40,180,100,.12); }
.cf-admin-status.suspended { color: #ffb4b4; background: rgba(220,53,69,.12); }

.cf-admin-drawer {
  margin-top: 1rem;
  padding: 1rem;
  border-radius: 0.95rem;
  border: 1px solid rgba(219,105,55,.25);
  background: rgba(0,0,0,.22);
}
.cf-admin-drawer[hidden] { display: none !important; }
.cf-admin-drawer-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.85rem;
}
.cf-admin-drawer-head h3 { margin: 0; color: #fff; font-size: 1.05rem; }
.cf-admin-drawer-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.65rem;
}
@media (min-width: 700px) {
  .cf-admin-drawer-grid { grid-template-columns: 1fr 1fr; }
  .cf-admin-field-wide { grid-column: 1 / -1; }
}
.cf-admin-field { display: flex; flex-direction: column; gap: 0.3rem; margin: 0; }
.cf-admin-field span {
  color: rgba(255,255,255,.5);
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
.cf-admin-drawer-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0.85rem 0 0.65rem;
}
.cf-admin-drawer-meta {
  margin: 0 0 0.45rem;
  color: rgba(255,255,255,.5);
  font-size: 0.8rem;
}
.cf-admin-pre {
  max-height: 14rem;
  overflow: auto;
  margin: 0;
  font-size: 0.72rem;
  color: rgba(255,255,255,.55);
  background: rgba(0,0,0,.28);
  padding: 0.75rem;
  border-radius: 0.8rem;
  border: 1px solid rgba(255,255,255,.06);
}

.cf-admin-source-list {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}
.cf-admin-source {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  align-items: center;
  padding: 0.85rem 0.9rem;
  border-radius: 0.95rem;
  border: 1px solid rgba(255,255,255,.08);
  background: rgba(0,0,0,.18);
  cursor: grab;
  margin: 0;
}
.cf-admin-source.is-off { opacity: 0.62; }
.cf-admin-source.is-dragging {
  opacity: 0.85;
  border-color: rgba(219,105,55,.45);
  background: rgba(219,105,55,.1);
}
.cf-admin-source-handle {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.1rem;
  min-width: 1.8rem;
  color: rgba(255,255,255,.35);
}
.cf-admin-source-handle i { font-size: 1.1rem; line-height: 1; }
.cf-admin-source-handle em {
  font-style: normal;
  font-size: 0.68rem;
  font-weight: 750;
  color: rgba(255,255,255,.4);
}
.cf-admin-source .meta { flex: 1; min-width: 10rem; }
.cf-admin-source .meta strong { display: block; color: #fff; }
.cf-admin-source .meta strong span { color: rgba(255,255,255,.35); font-weight: 500; }
.cf-admin-source .meta em {
  color: rgba(255,255,255,.5);
  font-style: normal;
  font-size: 0.78rem;
}
.cf-admin-label-input {
  width: 7.5rem;
  min-height: 2.3rem !important;
  padding: 0.35rem 0.55rem !important;
}
.cf-admin-switch {
  width: 2.6rem;
  height: 1.45rem;
  border-radius: 999px;
  border: 0;
  background: rgba(255,255,255,.16);
  position: relative;
  cursor: pointer;
  flex-shrink: 0;
}
.cf-admin-switch.on { background: linear-gradient(135deg, #db6937, #dc3545); }
.cf-admin-switch::after {
  content: "";
  position: absolute;
  top: 0.16rem;
  left: 0.16rem;
  width: 1.12rem;
  height: 1.12rem;
  border-radius: 999px;
  background: #fff;
  transition: transform .15s ease;
}
.cf-admin-switch.on::after { transform: translateX(1.1rem); }
.cf-admin-work-foot {
  margin-top: 0.85rem;
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.cf-admin-empty {
  margin: 0;
  padding: 1rem;
  color: rgba(255,255,255,.5);
  text-align: center;
}

/* Don't let older transparent panel kills flatten the work surface */
.cf-admin-work,
.cf-admin-work .cf-admin-panel {
  background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.015)) !important;
  border: 1px solid rgba(255,255,255,.08) !important;
  border-radius: 1.15rem !important;
  padding: 1rem !important;
  box-shadow: 0 10px 28px rgba(0,0,0,.18) !important;
}
'''

(root / "app/Views/pages/admin/users.php").write_text(USERS)
(root / "app/Views/pages/admin/sources.php").write_text(SOURCES)
print("wrote users/sources views")

css = root / "public/assets/css/app.css"
t = css.read_text()
marker = "/* ui98: Users/Sources content chrome"
if marker in t:
    t = t[: t.find(marker)].rstrip() + "\n\n" + CSS + "\n"
    print("replaced ui98 css")
else:
    t = t.rstrip() + "\n\n" + CSS + "\n"
    print("appended ui98 css")
css.write_text(t)

layout = root / "app/Views/layouts/main.php"
lt = layout.read_text()
lt2 = re.sub(r"(\?v=)2026080[12]-ui[0-9]+", r"\g<1>" + ver, lt)
layout.write_text(lt2)
print("assets", sorted(set(re.findall(r"\?v=([^\"&]+)", lt2)))[:8])
print("users work", "cf-admin-work" in USERS)
print("sources work", "cf-admin-source-list" in SOURCES)
