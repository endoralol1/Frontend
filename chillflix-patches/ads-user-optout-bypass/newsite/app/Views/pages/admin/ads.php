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
        Guests + users see ads. Staff never do. User settings can turn off Pop only — unless you bypass that below.
      </p>
    </div>

    <section class="cf-admin-panel cf-ads-panel" aria-label="Ad units" style="margin-top:1rem">
      <div id="ads-msg" class="muted" style="min-height:1.2rem;margin-bottom:.75rem;font-size:.82rem;color:rgba(255,255,255,.55)"></div>

      <div class="cf-ads-row">
        <div class="cf-ads-copy">
          <strong>All ads</strong>
          <small>Master switch — off disables every unit below</small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['enabled']) ? ' on' : '' ?>" id="ads-enabled" role="switch" aria-checked="<?= !empty($ads['enabled']) ? 'true' : 'false' ?>" aria-label="Toggle all ads"></button>
      </div>

      <div class="cf-ads-row cf-ads-row--banner" id="ads-banner-row">
        <div class="cf-ads-copy">
          <strong>Banner (above footer)</strong>
          <small>Zone <?= e((string) $ads['bannerZone']) ?> · site-wide when on · ignore user settings</small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['banner']) ? ' on' : '' ?>" id="ads-banner" role="switch" aria-checked="<?= !empty($ads['banner']) ? 'true' : 'false' ?>" aria-label="Toggle banner ad above footer" data-unit="banner"></button>
      </div>

      <div class="cf-ads-row">
        <div class="cf-ads-copy">
          <strong>Interstitial</strong>
          <small>Zone <?= e((string) $ads['interstitialZone']) ?> · site-wide when on · ignore user settings</small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['interstitial']) ? ' on' : '' ?>" id="ads-interstitial" role="switch" aria-checked="<?= !empty($ads['interstitial']) ? 'true' : 'false' ?>" aria-label="Toggle interstitial ad"></button>
      </div>

      <div class="cf-ads-row">
        <div class="cf-ads-copy">
          <strong>Video slider</strong>
          <small>Zone <?= e((string) $ads['videoSliderZone']) ?> · site-wide when on · ignore user settings</small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['videoSlider']) ? ' on' : '' ?>" id="ads-video-slider" role="switch" aria-checked="<?= !empty($ads['videoSlider']) ? 'true' : 'false' ?>" aria-label="Toggle video slider ad"></button>
      </div>
    </section>

    <section class="cf-admin-panel cf-ads-panel cf-ads-pop-panel" aria-label="Pop ads" style="margin-top:1rem">
      <div class="cf-ads-row" style="border-bottom:0;padding-bottom:.35rem">
        <div class="cf-ads-copy">
          <strong>Pop / Pop-under</strong>
          <small>Zone <?= e((string) ($ads['popZone'] ?? '11979882')) ?> · anti-adblock · users can disable in Settings</small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['pop']) ? ' on' : '' ?>" id="ads-pop" role="switch" aria-checked="<?= !empty($ads['pop']) ? 'true' : 'false' ?>" aria-label="Toggle pop ad"></button>
      </div>

      <div class="cf-ads-row cf-ads-row--bypass">
        <div class="cf-ads-copy">
          <strong>Bypass user “disable Pop”</strong>
          <small>When on, Pop still shows even if a user turned ads off in Settings</small>
        </div>
        <button type="button" class="cf-admin-switch<?= !empty($ads['popBypassUserOptOut']) ? ' on' : '' ?>" id="ads-pop-bypass" role="switch" aria-checked="<?= !empty($ads['popBypassUserOptOut']) ? 'true' : 'false' ?>" aria-label="Bypass user pop opt-out"></button>
      </div>

      <div class="cf-ads-pop-places" id="ads-pop-block">
        <h3 class="cf-ads-subhead">Show Pop on</h3>
        <p class="muted" style="margin:0 0 .75rem;font-size:.78rem;color:rgba(255,255,255,.45)">
          Only for Pop. Banner / interstitial / video slider ignore these.
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
          <button type="button" class="cf-admin-btn" id="ads-save-places">Save Pop placements</button>
        </div>
      </div>

      <p class="muted" style="margin:1rem 0 0;font-size:.78rem;color:rgba(255,255,255,.45)">
        Anti-adblock: <code><?= e((string) ($ads['antiAdblockSrc'] ?? '')) ?></code> · Admin pages &amp; staff accounts never show ads.
      </p>
    </section>
  </div>
</main>
<style>
.cf-ads-panel{padding:1rem 1.1rem}
.cf-ads-row{
  display:flex;align-items:center;justify-content:space-between;gap:1rem;
  padding:.9rem 0;border-bottom:1px solid rgba(255,255,255,.08);
}
.cf-ads-row:last-child{border-bottom:0}
.cf-ads-row--banner{
  border:1px solid rgba(219,105,55,.35);
  border-radius:.85rem;padding:.9rem 1rem;margin:.35rem 0 .55rem;
  background:rgba(219,105,55,.08);
}
.cf-ads-row--bypass{
  border:1px dashed rgba(255,255,255,.18);
  border-radius:.85rem;padding:.85rem 1rem;margin:.35rem 0 .75rem;
  background:rgba(255,255,255,.03);
}
.cf-ads-copy{display:flex;flex-direction:column;gap:.22rem;min-width:0}
.cf-ads-copy strong{font-size:.98rem}
.cf-ads-copy small{color:rgba(255,255,255,.5);font-size:.78rem;line-height:1.35}
.cf-ads-subhead{margin:.85rem 0 .35rem;font-size:.82rem;letter-spacing:.04em;text-transform:uppercase;color:rgba(255,255,255,.72)}
.cf-ads-pop-places{
  margin-top:.35rem;padding:.85rem .9rem;border-radius:.85rem;
  border:1px solid rgba(255,255,255,.1);background:rgba(0,0,0,.18);
}
.cf-ads-places{display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:.55rem}
.cf-ads-place{
  display:flex;align-items:center;gap:.55rem;padding:.65rem .75rem;border-radius:.7rem;
  border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);cursor:pointer;font-size:.86rem
}
.cf-ads-place input{accent-color:var(--cf-orange,#db6937);width:1rem;height:1rem}
/* Ensure switches are visible even if global admin CSS missed this page */
.cf-ads-panel .cf-admin-switch{
  flex:0 0 auto;width:2.75rem;height:1.55rem;border-radius:999px;border:0;
  background:rgba(255,255,255,.18);position:relative;cursor:pointer;padding:0;
}
.cf-ads-panel .cf-admin-switch.on{
  background:linear-gradient(135deg,var(--cf-orange,#db6937),var(--cf-orange-deep,#c43c2e));
}
.cf-ads-panel .cf-admin-switch::after{
  content:"";position:absolute;top:.18rem;left:.18rem;width:1.18rem;height:1.18rem;
  border-radius:999px;background:#fff;transition:transform .15s ease;box-shadow:0 1px 2px rgba(0,0,0,.25);
}
.cf-ads-panel .cf-admin-switch.on::after{transform:translateX(1.2rem)}
.cf-ads-pop-panel .cf-ads-row{align-items:flex-start}
@media (max-width:640px){
  .cf-ads-places{grid-template-columns:1fr 1fr}
}
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
  function setSwitch(el, on) {
    if (!el) return;
    el.classList.toggle('on', !!on);
    el.setAttribute('aria-checked', on ? 'true' : 'false');
  }
  function switchOn(id) {
    var el = document.getElementById(id);
    return !!(el && el.classList.contains('on'));
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
      enabled: switchOn('ads-enabled'),
      banner: switchOn('ads-banner'),
      interstitial: switchOn('ads-interstitial'),
      videoSlider: switchOn('ads-video-slider'),
      pop: switchOn('ads-pop'),
      popBypassUserOptOut: switchOn('ads-pop-bypass'),
      placements: placementsFromUi()
    };
  }
  function paint(d) {
    if (!d) return;
    setSwitch(document.getElementById('ads-enabled'), !!d.enabled);
    setSwitch(document.getElementById('ads-banner'), !!d.banner);
    setSwitch(document.getElementById('ads-interstitial'), !!d.interstitial);
    setSwitch(document.getElementById('ads-video-slider'), !!d.videoSlider);
    setSwitch(document.getElementById('ads-pop'), !!d.pop);
    setSwitch(document.getElementById('ads-pop-bypass'), !!d.popBypassUserOptOut);
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
  ['ads-enabled', 'ads-banner', 'ads-interstitial', 'ads-video-slider', 'ads-pop', 'ads-pop-bypass'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', function () {
      setSwitch(el, !el.classList.contains('on'));
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
