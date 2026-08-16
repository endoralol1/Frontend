<?php
$headerClass = 'relative';
$adminUser = $adminUser ?? Auth::user();
$ads = $ads ?? SiteAds::get();
?>
<main class="cf-admin page-pad-top">
  <div class="container cf-admin-wrap">
    <nav class="cf-admin-nav" aria-label="Admin">
      <a href="<?= e(url('/admin')) ?>">Dashboard</a>
      <a href="<?= e(url('/admin/users')) ?>">Users</a>
      <a href="<?= e(url('/admin/sources')) ?>">Sources</a>
      <a href="<?= e(url('/admin/inbox')) ?>">Inbox</a>
      <a class="is-active" href="<?= e(url('/admin/ads')) ?>">Ads</a>
      <a href="<?= e(url('/home')) ?>">Site</a>
    </nav>

    <div class="cf-admin-head">
      <h1>Ads</h1>
      <p class="muted" style="margin:.35rem 0 0;color:rgba(255,255,255,.55);font-size:.88rem">
        Enable or disable AdCash units site-wide. Master off disables every ad below.
      </p>
    </div>

    <section class="cf-admin-panel" aria-label="Ad controls" style="margin-top:1rem">
      <div id="ads-msg" class="muted" style="min-height:1.2rem;margin-bottom:.75rem;font-size:.82rem;color:rgba(255,255,255,.55)"></div>

      <div class="cf-ads-row">
        <div class="cf-ads-copy">
          <strong>All ads</strong>
          <small>Master switch for banner, interstitial, and video slider</small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['enabled']) ? ' on' : '' ?>" id="ads-enabled" aria-label="Toggle all ads"></button>
      </div>

      <div class="cf-ads-row">
        <div class="cf-ads-copy">
          <strong>Banner</strong>
          <small>Zone <?= e((string) $ads['bannerZone']) ?> · above footer</small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['banner']) ? ' on' : '' ?>" id="ads-banner" aria-label="Toggle banner ad"></button>
      </div>

      <div class="cf-ads-row">
        <div class="cf-ads-copy">
          <strong>Interstitial</strong>
          <small>Zone <?= e((string) ($ads['interstitialZone'] ?? '11979762')) ?> · AdCash pop/interstitial</small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['interstitial']) ? ' on' : '' ?>" id="ads-interstitial" aria-label="Toggle interstitial ad"></button>
      </div>

      <div class="cf-ads-row">
        <div class="cf-ads-copy">
          <strong>Video slider</strong>
          <small>Zone <?= e((string) ($ads['videoSliderZone'] ?? '11979774')) ?> · AdCash video slider</small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['videoSlider']) ? ' on' : '' ?>" id="ads-video-slider" aria-label="Toggle video slider ad"></button>
      </div>

      <p class="muted" style="margin:1rem 0 0;font-size:.78rem;color:rgba(255,255,255,.45)">
        Admin pages never show ads. Changes apply on the next public page load.
      </p>
    </section>
  </div>
</main>
<style>
.cf-ads-row{
  display:flex;align-items:center;justify-content:space-between;gap:1rem;
  padding:.85rem 0;border-bottom:1px solid rgba(255,255,255,.08);
}
.cf-ads-row:last-of-type{border-bottom:0}
.cf-ads-copy{display:flex;flex-direction:column;gap:.2rem;min-width:0}
.cf-ads-copy strong{font-size:.95rem}
.cf-ads-copy small{color:rgba(255,255,255,.5);font-size:.78rem}
</style>
<script>
(function () {
  var API = <?= json_encode(url('/api/admin/ads'), JSON_UNESCAPED_SLASHES) ?>;
  var msg = document.getElementById('ads-msg');
  function show(t, ok) {
    if (!msg) return;
    msg.textContent = t || '';
    msg.style.color = ok ? 'rgba(120,220,160,.95)' : 'rgba(255,255,255,.55)';
  }
  function stateFromUi() {
    return {
      enabled: document.getElementById('ads-enabled').classList.contains('on'),
      banner: document.getElementById('ads-banner').classList.contains('on'),
      interstitial: document.getElementById('ads-interstitial').classList.contains('on'),
      videoSlider: document.getElementById('ads-video-slider').classList.contains('on')
    };
  }
  function paint(d) {
    if (!d) return;
    [
      ['ads-enabled', 'enabled'],
      ['ads-banner', 'banner'],
      ['ads-interstitial', 'interstitial'],
      ['ads-video-slider', 'videoSlider']
    ].forEach(function (pair) {
      var el = document.getElementById(pair[0]);
      if (!el) return;
      el.classList.toggle('on', !!d[pair[1]]);
    });
  }
  function save() {
    var body = stateFromUi();
    show('Saving…');
    fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !d.ok) { show((d && d.error) || 'Save failed'); return; }
      paint(d.ads);
      show('Saved', true);
    }).catch(function () { show('Save failed'); });
  }
  ['ads-enabled', 'ads-banner', 'ads-interstitial', 'ads-video-slider'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', function () {
      el.classList.toggle('on');
      save();
    });
  });
})();
</script>
