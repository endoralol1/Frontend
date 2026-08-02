<?php
$headerClass = 'relative';
$adminUser = $adminUser ?? Auth::user();
?>
<main class="cf-admin page-pad-top">
  <div class="container cf-admin-wrap">
    <nav class="cf-admin-nav" aria-label="Admin">
      <a class="is-active" href="<?= e(url('/admin')) ?>">Dashboard</a>
      <a href="<?= e(url('/admin/users')) ?>">Users</a>
      <a href="<?= e(url('/admin/sources')) ?>">Sources</a>
      <a href="<?= e(url('/home')) ?>">← Site</a>
    </nav>
    <div class="cf-admin-grid" id="admin-stats">
      <div class="cf-admin-stat"><strong>—</strong><span>Users</span></div>
      <div class="cf-admin-stat"><strong>—</strong><span>Favorites</span></div>
      <div class="cf-admin-stat"><strong>—</strong><span>Continue</span></div>
      <div class="cf-admin-stat"><strong>—</strong><span>Sources on</span></div>
    </div>
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
      el.innerHTML =
        '<div class="cf-admin-stat"><strong>'+(s.users||0)+'</strong><span>Users</span></div>'+
        '<div class="cf-admin-stat"><strong>'+(s.favorites||0)+'</strong><span>Favorites</span></div>'+
        '<div class="cf-admin-stat"><strong>'+(s.continue||0)+'</strong><span>Continue</span></div>'+
        '<div class="cf-admin-stat"><strong>'+(s.sourcesEnabled||0)+'</strong><span>Sources on</span></div>';
    }).catch(function(){});
})();
</script>
