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
      <a href="<?= e(url('/admin/storage')) ?>">Storage</a>
      <a href="<?= e(url('/home')) ?>">Site</a>
    </nav>

    <div class="cf-admin-head">
      <h1>Ads</h1>
      <p class="muted" style="margin:.35rem 0 0;color:rgba(255,255,255,.55);font-size:.88rem">
        Guests + users see ads. Staff never do. User settings can turn off Pop only — unless you bypass that below.
      </p>
    </div>

    <section class="cf-admin-panel cf-ads-panel cf-ads-earn" aria-label="AdCash earnings" style="margin-top:1rem">
      <div class="cf-ads-earn-head">
        <div>
          <h2 class="cf-ads-earn-title">AdCash earnings</h2>
          <p class="muted" style="margin:.25rem 0 0;font-size:.78rem;color:rgba(255,255,255,.45)">
            Publisher Reporting API v2 · balance, daily totals, and zone breakdown
          </p>
        </div>
        <div class="cf-ads-earn-range">
          <label class="cf-ads-earn-field">
            <span>From</span>
            <input type="date" id="ads-earn-start" autocomplete="off">
          </label>
          <label class="cf-ads-earn-field">
            <span>To</span>
            <input type="date" id="ads-earn-end" autocomplete="off">
          </label>
          <button type="button" class="cf-admin-btn" id="ads-earn-refresh">Refresh</button>
        </div>
      </div>

      <div id="ads-earn-msg" class="muted" style="min-height:1.1rem;margin:.65rem 0 .35rem;font-size:.8rem;color:rgba(255,255,255,.5)"></div>

      <div class="cf-ads-earn-metrics" id="ads-earn-metrics" hidden>
        <div class="cf-ads-earn-metric">
          <span>Balance</span>
          <strong id="ads-earn-balance">—</strong>
        </div>
        <div class="cf-ads-earn-metric">
          <span>Period earnings</span>
          <strong id="ads-earn-total">—</strong>
        </div>
        <div class="cf-ads-earn-metric">
          <span>Clicks</span>
          <strong id="ads-earn-clicks">—</strong>
        </div>
        <div class="cf-ads-earn-metric">
          <span>Unique users</span>
          <strong id="ads-earn-users">—</strong>
        </div>
      </div>

      <div class="cf-ads-earn-grid">
        <div class="cf-ads-earn-block">
          <h3 class="cf-ads-subhead">By day</h3>
          <div class="cf-ads-earn-table-wrap">
            <table class="cf-ads-earn-table" id="ads-earn-days">
              <thead><tr><th>Date</th><th>Earnings</th><th>Clicks</th><th>Users</th><th>eCPM</th></tr></thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
        <div class="cf-ads-earn-block">
          <h3 class="cf-ads-subhead">By unit</h3>
          <p class="muted" style="margin:0 0 .45rem;font-size:.72rem;color:rgba(255,255,255,.42)">AdCash sub-zones are combined into Banner / Pop / Interstitial / Video slider</p>
          <div class="cf-ads-earn-table-wrap">
            <table class="cf-ads-earn-table" id="ads-earn-zones">
              <thead><tr><th>Unit</th><th>Earnings</th><th>Clicks</th><th>Users</th><th>eCPM</th></tr></thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
      </div>

      <details class="cf-ads-earn-token" id="ads-earn-token-box">
        <summary>Reporting API token</summary>
        <p class="muted" style="margin:.55rem 0 .65rem;font-size:.76rem;color:rgba(255,255,255,.45)">
          Stored server-side only. Leave blank to keep the current token. Current: <code id="ads-earn-token-mask">not set</code>
        </p>
        <div class="cf-ads-earn-token-row">
          <input type="password" id="ads-earn-token" placeholder="Paste AdCash Reporting API token" autocomplete="off" spellcheck="false">
          <button type="button" class="cf-admin-btn" id="ads-earn-token-save">Save token</button>
        </div>
      </details>
    </section>

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

      <div class="cf-ads-anti" style="margin-top:1rem;padding-top:.85rem;border-top:1px solid rgba(255,255,255,.08)">
        <div style="display:flex;flex-wrap:wrap;gap:.55rem;align-items:center;justify-content:space-between">
          <div style="min-width:0">
            <strong style="font-size:.9rem">Anti-adblock library</strong>
            <div class="muted" style="margin-top:.25rem;font-size:.78rem;color:rgba(255,255,255,.5)">
              File: <code id="ads-anti-src"><?= e((string) ($ads['antiAdblockSrc'] ?? '')) ?></code>
            </div>
            <div style="margin-top:.35rem;font-size:.82rem;color:rgba(255,255,255,.72)">
              Last rotated:
              <strong id="ads-anti-rotated" style="font-weight:600;color:#fff" data-ts="<?= (int) ($ads['antiAdblockRotatedAt'] ?? 0) ?>"><?php
                $rotAt = (int) ($ads['antiAdblockRotatedAt'] ?? 0);
                echo $rotAt > 0 ? '…' : 'never';
              ?></strong>
            </div>
            <div id="ads-anti-next" style="margin-top:.2rem;font-size:.78rem;color:rgba(255,255,255,.55)"><?php
                $autoOn = !empty($ads['antiAdblockAutoRotate']);
                $mins = (int) ($ads['antiAdblockAutoRotateMinutes'] ?? 0);
                if ($mins <= 0) {
                    $mins = max(1, (int) ($ads['antiAdblockAutoRotateHours'] ?? 24)) * 60;
                }
                $mins = max(10, min(10080, $mins));
                if (!$autoOn) {
                    echo 'Auto-rotate: OFF';
                } elseif ($rotAt <= 0) {
                    echo 'Auto-rotate: ON · every ' . (int) $mins . 'm · next run soon';
                } else {
                    $nextIn = ($rotAt + $mins * 60) - time();
                    if ($nextIn <= 0) {
                        echo 'Auto-rotate: ON · every ' . (int) $mins . 'm · next run due now';
                    } elseif ($nextIn < 60) {
                        echo 'Auto-rotate: ON · every ' . (int) $mins . 'm · next in ' . $nextIn . 's';
                    } elseif ($nextIn < 3600) {
                        echo 'Auto-rotate: ON · every ' . (int) $mins . 'm · next in ' . (int) floor($nextIn / 60) . 'm';
                    } else {
                        echo 'Auto-rotate: ON · every ' . (int) $mins . 'm · next in ' . (int) floor($nextIn / 3600) . 'h';
                    }
                }
            ?></div>
          </div>
          <button type="button" class="cf-admin-btn" id="ads-rotate-anti" style="min-height:2rem;padding:.35rem .8rem">Rotate now</button>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin-top:.85rem">
          <label style="display:inline-flex;align-items:center;gap:.45rem;font-size:.84rem;cursor:pointer">
            <input type="checkbox" id="ads-anti-auto" <?= !empty($ads['antiAdblockAutoRotate']) ? 'checked' : '' ?>>
            Auto-rotate
          </label>
          <label style="display:inline-flex;align-items:center;gap:.4rem;font-size:.84rem">
            Every
            <select id="ads-anti-hours" style="background:rgba(0,0,0,.35);color:#fff;border:1px solid rgba(255,255,255,.15);border-radius:.5rem;padding:.35rem .5rem">
              <?php
                $curM = (int) ($ads['antiAdblockAutoRotateMinutes'] ?? 0);
                if ($curM <= 0) {
                    $curM = max(1, (int) ($ads['antiAdblockAutoRotateHours'] ?? 24)) * 60;
                }
                foreach ([
                    10 => '10 minutes',
                    30 => '30 minutes',
                    60 => '1 hour',
                    360 => '6 hours',
                    720 => '12 hours',
                    1440 => '1 day',
                    2880 => '2 days',
                    4320 => '3 days',
                    10080 => '7 days',
                ] as $m => $label) {
                    $sel = $curM === (int) $m ? ' selected' : '';
                    echo '<option value="' . (int) $m . '"' . $sel . '>' . e($label) . '</option>';
                }
              ?>
            </select>
          </label>
          <button type="button" class="cf-admin-btn ghost" id="ads-anti-save-sched" style="min-height:2rem;padding:.3rem .7rem">Save timer</button>
        </div>
        <p id="ads-anti-msg" class="muted" style="min-height:1.1rem;margin:.55rem 0 0;font-size:.78rem;color:rgba(255,255,255,.45)">Content refresh every 5 min (same filename). Auto-rotate renames the file on your timer — 10 min works, but 1 day is usually enough.</p>
      </div>
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

.cf-ads-earn-title{margin:0;font-size:1.05rem;font-weight:800}
.cf-ads-earn-head{
  display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:.85rem;
}
.cf-ads-earn-range{display:flex;flex-wrap:wrap;align-items:flex-end;gap:.45rem}
.cf-ads-earn-field{display:flex;flex-direction:column;gap:.22rem;font-size:.68rem;letter-spacing:.04em;text-transform:uppercase;color:rgba(255,255,255,.5)}
.cf-ads-earn-field input{
  appearance:none;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;
  border-radius:.55rem;padding:.42rem .55rem;font:inherit;font-size:.82rem;min-width:9.5rem;
}
.cf-ads-earn-metrics{
  display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.55rem;margin:.85rem 0 1rem;
}
@media (min-width:720px){
  .cf-ads-earn-metrics{grid-template-columns:repeat(4,minmax(0,1fr))}
}
.cf-ads-earn-metric{
  padding:.75rem .8rem;border-radius:.75rem;border:1px solid rgba(255,255,255,.1);
  background:rgba(0,0,0,.22);display:flex;flex-direction:column;gap:.28rem;
}
.cf-ads-earn-metric span{font-size:.68rem;letter-spacing:.04em;text-transform:uppercase;color:rgba(255,255,255,.45)}
.cf-ads-earn-metric strong{font-size:1.15rem;font-weight:800;letter-spacing:-.02em}
.cf-ads-earn-grid{display:grid;gap:1rem}
@media (min-width:860px){
  .cf-ads-earn-grid{grid-template-columns:1fr 1fr}
}
.cf-ads-earn-block{
  border:1px solid rgba(255,255,255,.08);border-radius:.85rem;padding:.65rem .7rem .75rem;
  background:rgba(0,0,0,.16);min-width:0;
}
.cf-ads-earn-table-wrap{overflow:auto;max-height:18rem}
.cf-ads-earn-table{width:100%;border-collapse:collapse;font-size:.78rem}
.cf-ads-earn-table th,.cf-ads-earn-table td{
  padding:.42rem .35rem;text-align:left;border-bottom:1px solid rgba(255,255,255,.06);white-space:nowrap;
}
.cf-ads-earn-table th{color:rgba(255,255,255,.45);font-weight:700;font-size:.66rem;letter-spacing:.04em;text-transform:uppercase}
.cf-ads-earn-table td:nth-child(n+2){font-variant-numeric:tabular-nums}
.cf-ads-earn-token{margin-top:1rem;border-top:1px solid rgba(255,255,255,.08);padding-top:.75rem}
.cf-ads-earn-token summary{cursor:pointer;color:rgba(255,255,255,.7);font-size:.82rem;font-weight:700}
.cf-ads-earn-token-row{display:flex;flex-wrap:wrap;gap:.45rem}
.cf-ads-earn-token-row input{
  flex:1 1 14rem;min-width:0;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;
  border-radius:.55rem;padding:.55rem .65rem;font:inherit;font-size:.82rem;
}
</style>
<script>
(function () {
  var API = <?= json_encode(url('/api/admin/ads'), JSON_UNESCAPED_SLASHES) ?>;
  var EARN_API = <?= json_encode(url('/api/admin/ads/earnings'), JSON_UNESCAPED_SLASHES) ?>;
  var TOKEN_API = <?= json_encode(url('/api/admin/ads/reporting-token'), JSON_UNESCAPED_SLASHES) ?>;
  var msg = document.getElementById('ads-msg');
  var earnMsg = document.getElementById('ads-earn-msg');
  function show(t, ok) {
    if (!msg) return;
    msg.textContent = t || '';
    msg.style.color = ok ? 'rgba(120,220,160,.95)' : 'rgba(255,255,255,.55)';
  }
  function showEarn(t, ok) {
    if (!earnMsg) return;
    earnMsg.textContent = t || '';
    earnMsg.style.color = ok === true ? 'rgba(120,220,160,.95)' : (ok === false ? 'rgba(255,140,140,.95)' : 'rgba(255,255,255,.5)');
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

  function isoDaysAgo(n) {
    var d = new Date();
    d.setUTCDate(d.getUTCDate() - n);
    return d.toISOString().slice(0, 10);
  }
  function money(n, cur) {
    var v = Number(n || 0);
    return v.toFixed(2) + ' ' + (cur || 'EUR');
  }
  function fillTable(tableId, rows, mapRow) {
    var tb = document.querySelector('#' + tableId + ' tbody');
    if (!tb) return;
    tb.innerHTML = '';
    if (!rows || !rows.length) {
      tb.innerHTML = '<tr><td colspan="5" style="color:rgba(255,255,255,.45)">No rows</td></tr>';
      return;
    }
    rows.forEach(function (row) {
      var tr = document.createElement('tr');
      tr.innerHTML = mapRow(row);
      tb.appendChild(tr);
    });
  }
  function paintEarnings(d) {
    var metrics = document.getElementById('ads-earn-metrics');
    var mask = document.getElementById('ads-earn-token-mask');
    if (mask) mask.textContent = (d && d.token_masked) ? d.token_masked : 'not set';
    if (!d || !d.ok) {
      if (metrics) metrics.hidden = true;
      showEarn((d && d.error) || 'Could not load earnings', false);
      return;
    }
    if (metrics) metrics.hidden = false;
    var cur = d.currency || (d.balance && d.balance.currency) || 'EUR';
    document.getElementById('ads-earn-balance').textContent = money(d.balance && d.balance.amount, d.balance && d.balance.currency || cur);
    document.getElementById('ads-earn-total').textContent = money(d.totals && d.totals.earnings, cur);
    document.getElementById('ads-earn-clicks').textContent = String((d.totals && d.totals.clicks) || 0);
    document.getElementById('ads-earn-users').textContent = String((d.totals && d.totals.unique_users) || 0);
    fillTable('ads-earn-days', d.by_date || [], function (r) {
      return '<td>' + (r.date || '') + '</td><td>' + money(r.earnings, cur) + '</td><td>' + (r.clicks || 0) + '</td><td>' + (r.unique_users || 0) + '</td><td>' + Number(r.unique_users_ecpm || 0).toFixed(2) + '</td>';
    });
    fillTable('ads-earn-zones', d.by_zone || [], function (r) {
      var sub = r.sub_zones ? (' <span style="color:rgba(255,255,255,.35)">(+'+r.sub_zones+' sub)</span>') : '';
      var label = (r.label || ('Zone ' + r.zone)) + sub;
      return '<td>' + label + '</td><td>' + money(r.earnings, cur) + '</td><td>' + (r.clicks || 0) + '</td><td>' + (r.unique_users || 0) + '</td><td>' + Number(r.unique_users_ecpm || 0).toFixed(2) + '</td>';
    });
    var tz = d.timezone ? (' · ' + d.timezone) : '';
    showEarn('Updated' + (d.fetched_at ? (' ' + d.fetched_at.replace('T', ' ').replace(/Z$/, ' UTC')) : '') + tz, true);
  }
  function loadEarnings() {
    var start = document.getElementById('ads-earn-start');
    var end = document.getElementById('ads-earn-end');
    var qs = [];
    if (start && start.value) qs.push('start_date=' + encodeURIComponent(start.value));
    if (end && end.value) qs.push('end_date=' + encodeURIComponent(end.value));
    showEarn('Loading…');
    fetch(EARN_API + (qs.length ? ('?' + qs.join('&')) : ''), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(paintEarnings).catch(function () {
      showEarn('Load failed', false);
    });
  }
  var startEl = document.getElementById('ads-earn-start');
  var endEl = document.getElementById('ads-earn-end');
  if (startEl && !startEl.value) startEl.value = isoDaysAgo(13);
  if (endEl && !endEl.value) endEl.value = isoDaysAgo(0);
  var refresh = document.getElementById('ads-earn-refresh');
  if (refresh) refresh.addEventListener('click', loadEarnings);
  var tokenSave = document.getElementById('ads-earn-token-save');
  if (tokenSave) {
    tokenSave.addEventListener('click', function () {
      var input = document.getElementById('ads-earn-token');
      var token = input ? String(input.value || '').trim() : '';
      if (!token) { showEarn('Paste a token first', false); return; }
      showEarn('Saving token…');
      fetch(TOKEN_API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ apiToken: token })
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok) { showEarn((d && d.error) || 'Token save failed', false); return; }
        if (input) input.value = '';
        var mask = document.getElementById('ads-earn-token-mask');
        if (mask) mask.textContent = d.token_masked || 'saved';
        showEarn('Token saved', true);
        loadEarnings();
      }).catch(function () { showEarn('Token save failed', false); });
    });
  }
  
  var ROTATE_API = <?= json_encode(url('/api/admin/ads/rotate-antiadblock'), JSON_UNESCAPED_SLASHES) ?>;
  var SCHED_API = <?= json_encode(url('/api/admin/ads/antiadblock-schedule'), JSON_UNESCAPED_SLASHES) ?>;
  var antiMsg = document.getElementById('ads-anti-msg');
  function antiShow(txt, ok) {
    if (!antiMsg) return;
    antiMsg.textContent = txt || '';
    antiMsg.style.color = ok === false ? '#f87171' : (ok === true ? '#4ade80' : 'rgba(255,255,255,.45)');
  }
  function fmtRotated(ts) {
    ts = Number(ts) || 0;
    if (!ts) return 'never';
    try {
      var d = new Date(ts * 1000);
      var ago = Math.max(0, Math.floor(Date.now() / 1000) - ts);
      var rel = ago < 60 ? (ago + 's ago')
        : ago < 3600 ? (Math.floor(ago / 60) + 'm ago')
        : ago < 86400 ? (Math.floor(ago / 3600) + 'h ago')
        : (Math.floor(ago / 86400) + 'd ago');
      // Device local time (CET/CEST in DE/HR, EET/EEST in RO, etc.)
      var local = d.toLocaleString(undefined, {
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        hour12: false
      });
      var tz = '';
      try {
        var parts = new Intl.DateTimeFormat(undefined, { timeZoneName: 'short' }).formatToParts(d);
        for (var i = 0; i < parts.length; i++) {
          if (parts[i].type === 'timeZoneName') { tz = parts[i].value; break; }
        }
      } catch (e2) {}
      return local + (tz ? ' ' + tz : '') + ' · ' + rel;
    } catch (e) { return String(ts); }
  }
  function fmtNext(d) {
    if (!d) return '';
    var on = !!d.antiAdblockAutoRotate;
    var mins = Number(d.antiAdblockAutoRotateMinutes || 0);
    if (!mins) mins = (Number(d.antiAdblockAutoRotateHours || 24) * 60);
    mins = Math.max(10, Math.min(10080, mins));
    if (!on) return 'Auto-rotate: OFF';
    var ts = Number(d.antiAdblockRotatedAt || 0);
    if (!ts) return 'Auto-rotate: ON · every ' + mins + 'm · next run soon';
    var nextIn = (ts + mins * 60) - Math.floor(Date.now() / 1000);
    if (nextIn <= 0) return 'Auto-rotate: ON · every ' + mins + 'm · next run due now';
    if (nextIn < 60) return 'Auto-rotate: ON · every ' + mins + 'm · next in ' + nextIn + 's';
    if (nextIn < 3600) return 'Auto-rotate: ON · every ' + mins + 'm · next in ' + Math.floor(nextIn / 60) + 'm';
    return 'Auto-rotate: ON · every ' + mins + 'm · next in ' + Math.floor(nextIn / 3600) + 'h';
  }
  function paintAnti(d) {
    if (!d) return;
    var src = document.getElementById('ads-anti-src');
    var rot = document.getElementById('ads-anti-rotated');
    var next = document.getElementById('ads-anti-next');
    var auto = document.getElementById('ads-anti-auto');
    var hours = document.getElementById('ads-anti-hours');
    if (src && d.antiAdblockSrc) src.textContent = d.antiAdblockSrc;
    if (rot && d.antiAdblockRotatedAt !== undefined && d.antiAdblockRotatedAt !== null) {
      rot.setAttribute('data-ts', String(Number(d.antiAdblockRotatedAt) || 0));
      rot.textContent = fmtRotated(d.antiAdblockRotatedAt);
    }
    if (next) next.textContent = fmtNext(d);
    if (auto) auto.checked = !!d.antiAdblockAutoRotate;
    if (hours && (d.antiAdblockAutoRotateMinutes || d.antiAdblockAutoRotateHours)) {
      hours.value = String(d.antiAdblockAutoRotateMinutes || (d.antiAdblockAutoRotateHours * 60));
    }
  }
  (function paintAntiOnLoad() {
    var rotEl = document.getElementById('ads-anti-rotated');
    var ts = rotEl ? Number(rotEl.getAttribute('data-ts') || 0) : 0;
    var autoEl = document.getElementById('ads-anti-auto');
    var hoursEl = document.getElementById('ads-anti-hours');
    paintAnti({
      antiAdblockSrc: (document.getElementById('ads-anti-src') || {}).textContent || '',
      antiAdblockRotatedAt: ts,
      antiAdblockAutoRotate: !!(autoEl && autoEl.checked),
      antiAdblockAutoRotateMinutes: hoursEl ? Number(hoursEl.value || 1440) : 1440
    });
  })();
  var rotateBtn = document.getElementById('ads-rotate-anti');
  if (rotateBtn) {
    rotateBtn.addEventListener('click', function () {
      if (!confirm('Rotate anti-adblock JS to a new random filename?\n\nDo this when Brave/extensions start blocking ads.')) return;
      rotateBtn.disabled = true;
      antiShow('Rotating…');
      fetch(ROTATE_API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: '{}' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          rotateBtn.disabled = false;
          if (!d || !d.ok) { antiShow('Rotate failed: ' + ((d && (d.message || d.error)) || 'unknown'), false); alert('Rotate failed'); return; }
          paintAnti(d);
          antiShow((d.message || 'Rotated') + ' · ' + fmtRotated(d.antiAdblockRotatedAt), true);
          alert((d.message || 'Rotated') + '\n' + fmtRotated(d.antiAdblockRotatedAt));
        })
        .catch(function () { rotateBtn.disabled = false; antiShow('Rotate failed: network', false); alert('Rotate failed: network'); });
    });
  }
  var saveSched = document.getElementById('ads-anti-save-sched');
  if (saveSched) {
    saveSched.addEventListener('click', function () {
      var auto = document.getElementById('ads-anti-auto');
      var hours = document.getElementById('ads-anti-hours');
      saveSched.disabled = true;
      antiShow('Saving timer…');
      fetch(SCHED_API, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          antiAdblockAutoRotate: !!(auto && auto.checked),
          antiAdblockAutoRotateMinutes: hours ? Number(hours.value || 1440) : 1440
        })
      }).then(function (r) { return r.json(); }).then(function (d) {
        saveSched.disabled = false;
        if (!d || !d.ok) { antiShow((d && d.error) || 'Save failed', false); return; }
        paintAnti(d);
        (function () {
          var mins = Number(d.antiAdblockAutoRotateMinutes || 0);
          var label = mins >= 60 && mins % 60 === 0 ? ((mins / 60) + 'h') : (mins + 'm');
          antiShow('Timer saved · every ' + label + ' · auto ' + (d.antiAdblockAutoRotate ? 'ON' : 'OFF'), true);
        })();
      }).catch(function () { saveSched.disabled = false; antiShow('Save failed: network', false); });
    });
  }


  loadEarnings();
})();
</script>
