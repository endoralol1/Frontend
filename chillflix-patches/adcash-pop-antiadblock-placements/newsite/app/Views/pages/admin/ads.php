<?php
$headerClass = 'relative';
$adminUser = $adminUser ?? Auth::user();
$ads = $ads ?? SiteAds::get();
$placementLabels = SiteAds::placementLabels();
$selected = $ads['placements'] ?? ['everywhere'];
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
        Enable units and choose where they appear. Master off disables everything.
      </p>
    </div>

    <section class="cf-admin-panel" aria-label="Ad controls" style="margin-top:1rem">
      <div id="ads-msg" class="muted" style="min-height:1.2rem;margin-bottom:.75rem;font-size:.82rem;color:rgba(255,255,255,.55)"></div>

      <div class="cf-ads-row">
        <div class="cf-ads-copy">
          <strong>All ads</strong>
          <small>Master switch for every AdCash unit</small>
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
          <small>Zone <?= e((string) $ads['interstitialZone']) ?></small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['interstitial']) ? ' on' : '' ?>" id="ads-interstitial" aria-label="Toggle interstitial ad"></button>
      </div>

      <div class="cf-ads-row">
        <div class="cf-ads-copy">
          <strong>Video slider</strong>
          <small>Zone <?= e((string) $ads['videoSliderZone']) ?></small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['videoSlider']) ? ' on' : '' ?>" id="ads-video-slider" aria-label="Toggle video slider ad"></button>
      </div>

      <div class="cf-ads-row">
        <div class="cf-ads-copy">
          <strong>Pop / Pop-under</strong>
          <small>Zone <?= e((string) ($ads['popZone'] ?? '11979882')) ?> · anti-adblock lib</small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['pop']) ? ' on' : '' ?>" id="ads-pop" aria-label="Toggle pop ad"></button>
      </div>
    </section>

    <section class="cf-admin-panel" aria-label="Ad placements" style="margin-top:1rem">
      <h2 style="margin:0 0 .35rem;font-size:.95rem">Show ads on</h2>
      <p class="muted" style="margin:0 0 .85rem;font-size:.78rem;color:rgba(255,255,255,.45)">
        Pick one or more. “Everywhere” overrides the rest. Applies to all enabled units.
      </p>
      <div class="cf-ads-places" id="ads-placements">
        <?php foreach ($placementLabels as $key => $label): ?>
        <label class="cf-ads-place">
          <input type="checkbox" value="<?= e($key) ?>" <?= in_array($key, $selected, true) ? 'checked' : '' ?>>
          <span><?= e($label) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:1rem">
        <button type="button" class="cf-admin-btn" id="ads-save-places">Save placements</button>
      </div>
      <p class="muted" style="margin:1rem 0 0;font-size:.78rem;color:rgba(255,255,255,.45)">
        Anti-adblock script: <code><?= e((string) ($ads['antiAdblockSrc'] ?? '')) ?></code> (auto-refreshed every 5 min). Admin pages never show ads.
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
.cf-ads-places{display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:.55rem}
.cf-ads-place{
  display:flex;align-items:center;gap:.55rem;padding:.65rem .75rem;border-radius:.7rem;
  border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);cursor:pointer;font-size:.86rem
}
.cf-ads-place input{accent-color:var(--cf-orange,#db6937)}
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
  function placementsFromUi() {
    var boxes = document.querySelectorAll('#ads-placements input[type="checkbox"]');
    var out = [];
    boxes.forEach(function (b) { if (b.checked) out.push(b.value); });
    if (out.indexOf('everywhere') >= 0) return ['everywhere'];
    return out.length ? out : ['everywhere'];
  }
  function stateFromUi() {
    return {
      enabled: document.getElementById('ads-enabled').classList.contains('on'),
      banner: document.getElementById('ads-banner').classList.contains('on'),
      interstitial: document.getElementById('ads-interstitial').classList.contains('on'),
      videoSlider: document.getElementById('ads-video-slider').classList.contains('on'),
      pop: document.getElementById('ads-pop').classList.contains('on'),
      placements: placementsFromUi()
    };
  }
  function paint(d) {
    if (!d) return;
    [
      ['ads-enabled', 'enabled'],
      ['ads-banner', 'banner'],
      ['ads-interstitial', 'interstitial'],
      ['ads-video-slider', 'videoSlider'],
      ['ads-pop', 'pop']
    ].forEach(function (pair) {
      var el = document.getElementById(pair[0]);
      if (!el) return;
      el.classList.toggle('on', !!d[pair[1]]);
    });
    var places = d.placements || ['everywhere'];
    document.querySelectorAll('#ads-placements input[type="checkbox"]').forEach(function (b) {
      b.checked = places.indexOf(b.value) >= 0;
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
  ['ads-enabled', 'ads-banner', 'ads-interstitial', 'ads-video-slider', 'ads-pop'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', function () {
      el.classList.toggle('on');
      save();
    });
  });
  var everywhere = document.querySelector('#ads-placements input[value="everywhere"]');
  document.querySelectorAll('#ads-placements input[type="checkbox"]').forEach(function (b) {
    b.addEventListener('change', function () {
      if (b.value === 'everywhere' && b.checked) {
        document.querySelectorAll('#ads-placements input[type="checkbox"]').forEach(function (x) {
          if (x.value !== 'everywhere') x.checked = false;
        });
      } else if (b.value !== 'everywhere' && b.checked && everywhere) {
        everywhere.checked = false;
      }
    });
  });
  var saveBtn = document.getElementById('ads-save-places');
  if (saveBtn) saveBtn.addEventListener('click', save);
})();
</script>
