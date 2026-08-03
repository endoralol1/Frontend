#!/usr/bin/env python3
"""Fully remove RealDebrid from Chillflix/Vuflix newsite."""
from pathlib import Path
import re

ROOT = Path("/var/www/chillflix-newsite")

# 1) Delete service + settings
for p in [
    ROOT / "app/Services/RealDebridSources.php",
    ROOT / "storage/realdebrid.json",
    ROOT / "storage/realdebrid.json.tmp",
    ROOT / "storage/rd-write-test.txt",
]:
    if p.exists():
        p.unlink()
        print("deleted", p)

# 2) bootstrap
boot = ROOT / "app/bootstrap.php"
bt = boot.read_text(encoding="utf-8")
bt2 = bt.replace("require_once __DIR__ . '/Services/RealDebridSources.php';\n", "")
if bt2 == bt:
    raise SystemExit("bootstrap RD require missing")
boot.write_text(bt2, encoding="utf-8")
print("patched bootstrap")

# 3) SourcesService catalog
ss = ROOT / "app/Services/SourcesService.php"
st = ss.read_text(encoding="utf-8")
st2 = st.replace("        'vidrock' => 'VidRock',\n        'realdebrid' => 'RealDebrid',\n", "        'vidrock' => 'VidRock',\n")
if st2 == st:
    # try trailing comma variant
    st2 = st.replace("        'realdebrid' => 'RealDebrid',\n", "")
if "realdebrid" in st2:
    raise SystemExit("could not remove realdebrid from CATALOG")
ss.write_text(st2, encoding="utf-8")
print("patched SourcesService")

# 4) PlayerSources — remove RD branch + RD-specific empty errors
ps = ROOT / "app/Services/PlayerSources.php"
pt = ps.read_text(encoding="utf-8")
rd_block = """            // Vuflix-only RealDebrid (Torrentio → RD HTTP links for native player)
            if ($provider === 'realdebrid' || $provider === 'torrentio-rd') {
                if (!class_exists('RealDebridSources') || !RealDebridSources::hostAllowed()) {
                    $diagnostics[] = [
                        'code' => 'RD_HOST_BLOCKED',
                        'message' => 'RealDebrid is only available on Vuflix',
                        'severity' => 'info',
                        'provider' => 'realdebrid',
                    ];
                    continue;
                }
                $rd = RealDebridSources::fetch($type, $tmdbId, $season, $episode);
                foreach ($rd['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($rd['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $key = 'realdebrid|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'file'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => 'realdebrid',
                        'providerName' => (string) ($src['providerName'] ?? 'RealDebrid'),
                        'label' => (string) ($src['label'] ?? 'RealDebrid'),
                        'language' => (string) ($src['language'] ?? ''),
                        'audioTracks' => [],
                    ];
                }
                if (empty($rd['ok']) && empty($rd['sources'])) {
                    $diagnostics[] = [
                        'code' => 'RD_EMPTY',
                        'message' => (string) ($rd['error'] ?? 'No RealDebrid streams'),
                        'severity' => 'warning',
                        'provider' => 'realdebrid',
                    ];
                }
                continue;
            }
"""
if rd_block not in pt:
    raise SystemExit("PlayerSources RD block missing")
pt = pt.replace(rd_block, "")

old_err = """        $sources = array_values($merged);
        if (!$sources) {
            $err = 'No playable sources right now.';
            $codes = [];
            foreach ($diagnostics as $d) {
                if (is_array($d) && !empty($d['code'])) {
                    $codes[(string) $d['code']] = trim((string) ($d['message'] ?? ''));
                }
            }
            if (isset($codes['RD_KEY_MISSING'])) {
                $err = 'RealDebrid API key missing. Open Admin → Sources, paste your key from real-debrid.com/apitoken, click Save key, then try again.';
            } elseif (isset($codes['RD_KEY_INVALID'])) {
                $err = 'RealDebrid API key is invalid or expired. Update it in Admin → Sources.';
            } elseif (isset($codes['RD_HOST_BLOCKED'])) {
                $err = 'RealDebrid is only available on vuflix.co.';
            } elseif (!empty($codes['RD_EMPTY'])) {
                $err = $codes['RD_EMPTY'];
            }
            return [
                'ok' => false,
                'error' => $err,
                'diagnostics' => array_values($diagnostics),
                'sources' => [],
                'subtitles' => [],
            ];
        }
"""
new_err = """        $sources = array_values($merged);
        if (!$sources) {
            return [
                'ok' => false,
                'error' => 'No playable sources right now.',
                'diagnostics' => array_values($diagnostics),
                'sources' => [],
                'subtitles' => [],
            ];
        }
"""
if old_err not in pt:
    raise SystemExit("PlayerSources empty-error block missing")
pt = pt.replace(old_err, new_err, 1)
if "RealDebrid" in pt or "realdebrid" in pt or "RD_KEY" in pt:
    raise SystemExit("PlayerSources still mentions RealDebrid")
ps.write_text(pt, encoding="utf-8")
print("patched PlayerSources")

# 5) routes — strip RD from admin sources + delete RD endpoints
rp = ROOT / "app/routes.php"
rt = rp.read_text(encoding="utf-8")
old_extra = """    $extra = [];
    if (class_exists('RealDebridSources')) {
        $extra['realdebrid'] = [
            'hostAllowed' => RealDebridSources::hostAllowed(),
            'configured' => RealDebridSources::apiKeyConfigured(),
            'maskedKey' => RealDebridSources::maskedKey(),
        ];
    }
    json_response(['ok' => true, 'sources' => SourcesService::all(), 'catalog' => SourcesService::CATALOG] + $extra);
"""
new_extra = """    json_response(['ok' => true, 'sources' => SourcesService::all(), 'catalog' => SourcesService::CATALOG]);
"""
if old_extra not in rt:
    raise SystemExit("routes admin sources extra missing")
rt = rt.replace(old_extra, new_extra, 1)

rd_routes = """
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
});
"""
if rd_routes not in rt:
    raise SystemExit("RD routes block missing")
rt = rt.replace(rd_routes, "\n", 1)
if "RealDebrid" in rt or "realdebrid" in rt:
    raise SystemExit("routes still mentions RealDebrid")
rp.write_text(rt, encoding="utf-8")
print("patched routes")

# 6) Admin sources UI — rewrite without RD panel/JS
admin = ROOT / "app/Views/pages/admin/sources.php"
admin.write_text(
    """<?php
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
    var inp=e.target.closest('[data-label]');
    if(!inp) return;
    var id=inp.getAttribute('data-label');
    fetch(API+'/'+encodeURIComponent(id), {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({publicLabel:inp.value})
    }).then(r=>r.json()).then(function(d){ if(d&&d.ok) show('Label saved', true); else show('Label save failed'); });
  });
  document.getElementById('src-save-order').onclick=function(){
    fetch(API+'/reorder', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({order:orderIds()})
    }).then(r=>r.json()).then(function(d){ if(d&&d.ok){ render(d.sources||[]); show('Order saved', true);} else show('Order save failed'); });
  };
  load();
})();
</script>
""",
    encoding="utf-8",
)
print("rewrote admin sources.php")

# 7) DB: delete realdebrid row; re-enable vaplayer + huhu defaults
php = r'''
<?php
$_SERVER["HTTP_HOST"] = "vuflix.co";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"] = "/__rd_remove";
require "/var/www/chillflix-newsite/app/helpers.php";
require "/var/www/chillflix-newsite/app/Services/Database.php";
require "/var/www/chillflix-newsite/app/Services/Auth.php";
$pdo = Database::pdo();
$pdo->exec("DELETE FROM sources WHERE id = 'realdebrid'");
$pdo->prepare("UPDATE sources SET enabled = 1, updated_at = ? WHERE id IN ('vaplayer','huhu')")->execute([time()]);
$rows = $pdo->query("SELECT id, enabled FROM sources ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT), "\n";
'''
Path("/tmp/_rd_db_cleanup.php").write_text(php, encoding="utf-8")
import subprocess
out = subprocess.check_output(["php", "/tmp/_rd_db_cleanup.php"], text=True)
print("DB cleanup:\n", out)

# sanity: no leftover references in live app (ignore backups)
import subprocess as sp
res = sp.run(
    ["grep", "-rn", "RealDebrid\\|realdebrid\\|torrentio-rd", str(ROOT / "app")],
    capture_output=True,
    text=True,
)
lines = [ln for ln in (res.stdout or "").splitlines() if ".bak" not in ln]
if lines:
    print("LEFTOVER:\n" + "\n".join(lines[:40]))
    raise SystemExit("leftover RealDebrid references")
print("OK — RealDebrid fully removed")
