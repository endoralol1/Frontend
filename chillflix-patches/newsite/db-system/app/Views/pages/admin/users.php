<?php
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
