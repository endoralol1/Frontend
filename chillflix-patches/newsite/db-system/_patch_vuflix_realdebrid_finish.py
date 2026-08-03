#!/usr/bin/env python3
"""Finish RealDebrid routes + admin UI after partial apply."""
from pathlib import Path

root = Path("/var/www/chillflix-newsite")
routes = root / "app/routes.php"
rt = routes.read_text()

old = """$router->get('/api/admin/sources', function () {
    Auth::requireRole('admin', 'moderator');
    json_response(['ok' => true, 'sources' => SourcesService::all(), 'catalog' => SourcesService::CATALOG]);
});"""

new = """$router->get('/api/admin/sources', function () {
    Auth::requireRole('admin', 'moderator');
    if (class_exists('SourcesService')) {
        SourcesService::ensureCatalogRows();
    }
    $extra = [];
    if (class_exists('RealDebridSources')) {
        $extra['realdebrid'] = [
            'hostAllowed' => RealDebridSources::hostAllowed(),
            'configured' => RealDebridSources::apiKeyConfigured(),
            'maskedKey' => RealDebridSources::maskedKey(),
        ];
    }
    json_response(['ok' => true, 'sources' => SourcesService::all(), 'catalog' => SourcesService::CATALOG] + $extra);
});

$router->get('/api/admin/realdebrid', function () {
    Auth::requireRole('admin', 'moderator');
    if (!class_exists('RealDebridSources') || !RealDebridSources::hostAllowed()) {
        json_response(['ok' => false, 'error' => 'RealDebrid is Vuflix-only'], 404);
    }
    json_response([
        'ok' => true,
        'hostAllowed' => true,
        'configured' => RealDebridSources::apiKeyConfigured(),
        'maskedKey' => RealDebridSources::maskedKey(),
    ]);
});

$router->map(['POST', 'PUT'], '/api/admin/realdebrid', function () {
    Auth::requireRole('admin', 'moderator');
    if (!class_exists('RealDebridSources') || !RealDebridSources::hostAllowed()) {
        json_response(['ok' => false, 'error' => 'RealDebrid is Vuflix-only'], 404);
    }
    $body = json_body();
    $key = trim((string) ($body['apiKey'] ?? ''));
    if ($key === '') {
        RealDebridSources::saveApiKey('');
        json_response(['ok' => true, 'configured' => false, 'maskedKey' => '', 'cleared' => true]);
    }
    $check = RealDebridSources::validateApiKey($key);
    if (empty($check['ok'])) {
        json_response(['ok' => false, 'error' => $check['error'] ?? 'Invalid key'], 400);
    }
    RealDebridSources::saveApiKey($key);
    json_response([
        'ok' => true,
        'configured' => true,
        'maskedKey' => RealDebridSources::maskedKey(),
        'username' => $check['username'] ?? '',
        'premium' => $check['premium'] ?? 0,
    ]);
});"""

if "/api/admin/realdebrid" in rt:
    print("routes already ok")
elif old not in rt:
    raise SystemExit("admin sources GET route not found")
else:
    routes.write_text(rt.replace(old, new, 1))
    print("routes patched")

admin = root / "app/Views/pages/admin/sources.php"
at = admin.read_text()
if "rd-api-key" not in at:
    at = at.replace(
        """    <div class="cf-admin-head">
      <h1>Sources</h1>
      <p>Enable/disable, drag order (top = tested first), and probe with any movie/TV TMDB id. Users see Alpha/Beta labels.</p>
    </div>
    <div class="cf-admin-panel">""",
        """    <div class="cf-admin-head">
      <h1>Sources</h1>
      <p>Enable/disable, drag order (top = tested first), and probe with any movie/TV TMDB id. Users see Alpha/Beta labels.</p>
    </div>
    <div class="cf-admin-panel" id="rd-panel" hidden>
      <h2 style="margin:0 0 .35rem;font-size:1.05rem">RealDebrid (Vuflix)</h2>
      <p style="margin:0 0 .75rem;color:rgba(255,255,255,.55);font-size:.86rem;line-height:1.4">
        Learning provider: Torrentio finds torrents, RealDebrid unlocks HTTP links, your player plays them.
        Cached titles start fast; uncached ones wait on RD’s side (not this server).
        Get a key at <a href="https://real-debrid.com/apitoken" target="_blank" rel="noopener" style="color:#ffb3bb">real-debrid.com/apitoken</a>.
      </p>
      <div class="cf-admin-toolbar" style="align-items:center">
        <input id="rd-api-key" type="password" placeholder="RealDebrid API key" style="flex:1 1 16rem;min-height:2.4rem;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.45rem .65rem" autocomplete="off">
        <button type="button" class="cf-admin-btn" id="rd-save">Save key</button>
        <button type="button" class="cf-admin-btn ghost" id="rd-clear">Clear</button>
      </div>
      <p id="rd-status" style="margin:.55rem 0 0;font-size:.82rem;color:rgba(255,255,255,.5)"></p>
    </div>
    <div class="cf-admin-panel">""",
        1,
    )
    old_load = """  function load(){
    fetch(API,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(!d||!d.ok){ list.textContent='Failed'; return; }
      render(d.sources||[]);
    });
  }"""
    new_load = """  function load(){
    fetch(API,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(!d||!d.ok){ list.textContent='Failed'; return; }
      render(d.sources||[]);
      var rd = d.realdebrid;
      var panel = document.getElementById('rd-panel');
      var status = document.getElementById('rd-status');
      if (panel && rd && rd.hostAllowed) {
        panel.hidden = false;
        status.textContent = rd.configured
          ? ('Key saved: ' + (rd.maskedKey || '••••') + ' — enable RealDebrid below and Test with a TMDB id')
          : 'No API key yet — paste one and Save, then enable RealDebrid in the list';
      } else if (panel) {
        panel.hidden = true;
      }
    });
  }
  var rdSave = document.getElementById('rd-save');
  var rdClear = document.getElementById('rd-clear');
  var rdKey = document.getElementById('rd-api-key');
  var rdApi = <?= json_encode(url('/api/admin/realdebrid')) ?>;
  if (rdSave && rdKey) {
    rdSave.onclick = function () {
      var key = (rdKey.value || '').trim();
      if (!key) { show('Paste an API key first'); return; }
      show('Validating RealDebrid key…');
      fetch(rdApi, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({apiKey: key})
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok) { show((d && d.error) || 'Save failed'); return; }
        rdKey.value = '';
        show('RealDebrid key saved' + (d.username ? (' · ' + d.username) : ''), true);
        load();
      }).catch(function () { show('Save failed'); });
    };
  }
  if (rdClear) {
    rdClear.onclick = function () {
      fetch(rdApi, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({apiKey: ''})
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.ok) { show('RealDebrid key cleared', true); load(); }
        else show('Clear failed');
      });
    };
  }"""
    if old_load not in at:
        raise SystemExit("load() block not found")
    admin.write_text(at.replace(old_load, new_load, 1))
    print("admin ui patched")
else:
    print("admin ui ok")

# seed
import subprocess
subprocess.check_call([
    "php", "-r",
    "require '/var/www/chillflix-newsite/app/helpers.php';"
    "require '/var/www/chillflix-newsite/app/Services/Database.php';"
    "require '/var/www/chillflix-newsite/app/Services/Auth.php';"
    "require '/var/www/chillflix-newsite/app/Services/SourcesService.php';"
    "SourcesService::seedDefaults();"
    "$ids=array_column(SourcesService::all(),'id');"
    "echo in_array('realdebrid',$ids,true)?'db has realdebrid\\n':'db missing\\n';",
])

assert "Vuflix-only RealDebrid" in (root / "app/Services/PlayerSources.php").read_text()
assert "/api/admin/realdebrid" in routes.read_text()
assert "rd-api-key" in admin.read_text()
print("OK finish")
