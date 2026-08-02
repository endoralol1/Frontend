from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
ver = "20260802-ui99"

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
    <div class="cf-admin-panel">
      <div class="cf-admin-toolbar">
        <input id="users-q" type="search" placeholder="Search name, email, id…">
        <button type="button" class="cf-admin-btn" id="users-search">Search</button>
      </div>
      <div class="cf-admin-msg" id="users-msg" hidden></div>
      <div style="overflow:auto;">
        <table class="cf-admin-table">
          <thead><tr><th>User</th><th>Role</th><th>Status</th><th></th></tr></thead>
          <tbody id="users-body"><tr><td colspan="4">Loading…</td></tr></tbody>
        </table>
      </div>
      <div class="cf-admin-drawer" id="user-drawer" hidden></div>
    </div>
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
            '<td><strong style="color:#fff">'+(u.name||'')+'</strong><div style="color:rgba(255,255,255,.45);font-size:.75rem">'+(u.email||'')+'</div></td>'+
            '<td><span class="cf-admin-role '+(u.role||'')+'">'+(u.role||'')+'</span></td>'+
            '<td>'+(u.status||'')+'</td>'+
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
        '<h3 style="margin:0 0 .75rem;color:#fff">Edit user</h3>'+
        '<label class="cf-admin-field"><span>Name</span><input id="eu-name" value="'+(u.name||'').replace(/"/g,'&quot;')+'"></label>'+
        '<label class="cf-admin-field"><span>Email</span><input id="eu-email" value="'+(u.email||'').replace(/"/g,'&quot;')+'"></label>'+
        '<label class="cf-admin-field"><span>Status</span><select id="eu-status"><option value="active">active</option><option value="suspended">suspended</option></select></label>'+
        (canRole ? '<label class="cf-admin-field"><span>Role</span><select id="eu-role"><option value="user">user</option><option value="moderator">moderator</option><option value="admin">admin</option></select></label>' : '')+
        '<label class="cf-admin-field"><span>New password</span><input id="eu-pass" type="password" placeholder="Leave blank to keep"></label>'+
        '<div style="display:flex;flex-wrap:wrap;gap:.5rem;margin:.4rem 0 1rem">'+
          '<button type="button" class="cf-admin-btn" id="eu-save">Save</button>'+
          (canRole ? '<button type="button" class="cf-admin-btn danger" id="eu-del">Delete</button>' : '')+
        '</div>'+
        '<p style="color:rgba(255,255,255,.5);font-size:.8rem;margin:0 0 .35rem">Favorites: '+((d.favorites||[]).length)+' · Continue: '+((d.continueWatching||[]).length)+'</p>'+
        '<pre style="max-height:14rem;overflow:auto;font-size:.72rem;color:rgba(255,255,255,.55);background:rgba(0,0,0,.25);padding:.7rem;border-radius:.8rem">'+(
          JSON.stringify({favorites:d.favorites||[], continueWatching:d.continueWatching||[]}, null, 2)
        )+'</pre>';
      drawer.querySelector('#eu-status').value = u.status || 'active';
      if (canRole) drawer.querySelector('#eu-role').value = u.role || 'user';
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

# Original ui88 content surfaces — force over later transparent/ui98 overrides
CSS = r'''
/* ui99: restore ORIGINAL Users/Sources content (ui88 panels/cards). Tabs stay new. */
.page-admin .cf-admin {
  --adm-ink: #f4f1ec;
  --adm-muted: rgba(244,241,236,.62);
  --adm-line: rgba(255,255,255,.1);
  --adm-hot: #db6937;
  --adm-panel: rgba(255,255,255,.035);
}

/* Keep NEW underline tabs */
.cf-admin-nav {
  display: flex !important;
  flex-wrap: wrap !important;
  gap: 0.15rem 1.1rem !important;
  margin: 0 0 1.35rem !important;
  border-bottom: 1px solid rgba(255,255,255,.08) !important;
}
.cf-admin-nav a {
  position: relative !important;
  display: inline-flex !important;
  align-items: center !important;
  min-height: 2.55rem !important;
  padding: 0.2rem 0 !important;
  border: 0 !important;
  border-radius: 0 !important;
  background: transparent !important;
  color: rgba(255,255,255,.55) !important;
  text-decoration: none !important;
  font-size: 0.82rem !important;
  font-weight: 700 !important;
  letter-spacing: 0.08em !important;
  text-transform: uppercase !important;
}
.cf-admin-nav a::after {
  content: "" !important;
  display: block !important;
  position: absolute !important;
  left: 0; right: 0; bottom: -1px;
  height: 2px;
  background: transparent;
}
.cf-admin-nav a.is-active,
.cf-admin-nav a:hover { color: #fff !important; }
.cf-admin-nav a.is-active::after {
  background: linear-gradient(90deg, #db6937, #dc3545) !important;
}

/* Original panel / table / source / drawer look */
.cf-admin-panel {
  border-radius: 1.25rem !important;
  border: 1px solid var(--adm-line, rgba(255,255,255,.1)) !important;
  background: var(--adm-panel, rgba(255,255,255,.035)) !important;
  padding: 1rem !important;
  box-shadow: none !important;
}
.cf-admin-work {
  /* neutralize ui98 wrapper if any leftover */
  margin: 0 !important;
  padding: 0 !important;
  border: 0 !important;
  background: transparent !important;
  box-shadow: none !important;
  border-radius: 0 !important;
}
.cf-admin-toolbar { display:flex; flex-wrap:wrap; gap:.55rem; margin-bottom:.85rem; }
.cf-admin-toolbar input,
.cf-admin-toolbar select,
.cf-admin-field input,
.cf-admin-field select,
.cf-admin-field textarea {
  min-height:2.5rem; padding:.55rem .75rem; border-radius:.85rem;
  border:1px solid var(--adm-line, rgba(255,255,255,.1));
  background:rgba(0,0,0,.28); color:#fff; font:inherit;
}
.cf-admin-toolbar input { flex:1; min-width:12rem; }
.cf-admin-btn {
  appearance:none; border:0; border-radius:.85rem; min-height:2.5rem; padding:.55rem .95rem;
  background:linear-gradient(135deg,#db6937,#dc3545); color:#fff; font:inherit; font-weight:750; cursor:pointer;
}
.cf-admin-btn.ghost {
  background:rgba(255,255,255,.06); border:1px solid var(--adm-line, rgba(255,255,255,.1));
}
.cf-admin-btn.danger { background:linear-gradient(135deg,#a11,#dc3545); }
.cf-admin-table { width:100%; border-collapse:collapse; }
.cf-admin-table th, .cf-admin-table td {
  text-align:left; padding:.65rem .45rem; border-bottom:1px solid rgba(255,255,255,.07);
  color:rgba(255,255,255,.86); font-size:.86rem; vertical-align:middle;
  background: transparent !important;
}
.cf-admin-table th {
  color:rgba(255,255,255,.45); font-size:.72rem; letter-spacing:.05em; text-transform:uppercase;
  background: transparent !important;
}
.cf-admin-table-wrap {
  overflow: visible !important;
  border: 0 !important;
  background: transparent !important;
  border-radius: 0 !important;
}
.cf-admin-role {
  display:inline-flex; padding:.15rem .5rem; border-radius:999px; font-size:.7rem; font-weight:750;
  border:1px solid rgba(255,255,255,.12); text-transform:uppercase; letter-spacing:.04em;
}
.cf-admin-role.admin { color:#ffd2b8; border-color:rgba(219,105,55,.45); background:rgba(219,105,55,.14); }
.cf-admin-role.moderator { color:#b8e0ff; border-color:rgba(80,160,255,.4); background:rgba(80,160,255,.12); }
.cf-admin-role.user { color:rgba(255,255,255,.7); }

.cf-admin-source {
  display:flex !important; flex-wrap:wrap !important; gap:.65rem !important; align-items:center !important;
  padding:.85rem !important; border-radius:1rem !important;
  border:1px solid var(--adm-line, rgba(255,255,255,.1)) !important;
  background:rgba(0,0,0,.18) !important; margin-bottom:.55rem !important; cursor:grab !important;
}
.cf-admin-source.is-off { opacity:.55; }
.cf-admin-source .meta { flex:1; min-width:10rem; }
.cf-admin-source .meta strong { display:block; color:#fff; }
.cf-admin-source .meta em { color:var(--adm-muted, rgba(244,241,236,.62)); font-style:normal; font-size:.78rem; }
.cf-admin-source-list { display:block !important; gap:0 !important; }
.cf-admin-source-handle { display:none !important; }

.cf-admin-switch {
  width:2.6rem; height:1.45rem; border-radius:999px; border:0; background:rgba(255,255,255,.16); position:relative; cursor:pointer;
}
.cf-admin-switch.on { background:linear-gradient(135deg,#db6937,#dc3545); }
.cf-admin-switch::after {
  content:""; position:absolute; top:.16rem; left:.16rem; width:1.12rem; height:1.12rem; border-radius:999px; background:#fff; transition:transform .15s ease;
}
.cf-admin-switch.on::after { transform:translateX(1.1rem); }

.cf-admin-drawer {
  margin-top:1rem !important; padding:1rem !important; border-radius:1.1rem !important;
  border:1px solid var(--adm-line, rgba(255,255,255,.1)) !important;
  background:rgba(0,0,0,.22) !important;
}
.cf-admin-drawer[hidden] { display:none !important; }
.cf-admin-field { display:flex; flex-direction:column; gap:.3rem; margin-bottom:.7rem; }
.cf-admin-field span { color:rgba(255,255,255,.5); font-size:.72rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
.cf-admin-msg { margin:.5rem 0; color:#ffd2b8; font-size:.85rem; }
.cf-admin-msg.ok { color:#7dffb0; }

/* Quiet page titles like original (not oversized dashboard hero type) */
body.page-admin:not(.page-admin-dashboard) .cf-admin-head h1 {
  font-size: clamp(1.5rem, 4vw, 1.9rem) !important;
  letter-spacing: -0.03em !important;
}
body.page-admin:not(.page-admin-dashboard) .cf-admin-head p {
  font-size: 0.92rem !important;
  max-width: none !important;
}
'''

(root / "app/Views/pages/admin/users.php").write_text(USERS)
(root / "app/Views/pages/admin/sources.php").write_text(SOURCES)
print("restored original users/sources markup")

css = root / "public/assets/css/app.css"
t = css.read_text()
# drop ui98 content chrome block so it can't fight us
for marker in ["/* ui98: Users/Sources content chrome", "/* ui99: restore ORIGINAL Users/Sources content"]:
    if marker in t:
        t = t[: t.find(marker)].rstrip() + "\n"
        print("stripped", marker[:40])

t = t.rstrip() + "\n\n" + CSS + "\n"
css.write_text(t)
print("appended ui99 original content css")

layout = root / "app/Views/layouts/main.php"
lt = layout.read_text()
lt2 = re.sub(r"(\?v=)2026080[12]-ui[0-9]+", r"\g<1>" + ver, lt)
layout.write_text(lt2)
print("assets", sorted(set(re.findall(r"\?v=([^\"&]+)", lt2)))[:8])
print("panel class", "cf-admin-panel" in USERS and "cf-admin-work" not in USERS)
print("source cards original", "cf-admin-source-handle" not in SOURCES)
