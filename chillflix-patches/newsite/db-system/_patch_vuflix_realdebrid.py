#!/usr/bin/env python3
"""Wire Vuflix-only RealDebrid (via Torrentio) into player + admin Sources."""
from pathlib import Path
import re
import shutil

root = Path("/var/www/chillflix-newsite")
src_svc = Path("/workspace/chillflix-patches/newsite/db-system/app/Services/RealDebridSources.php")
# Allow running when script is copied to /tmp with service file beside patch or from workspace mount
candidates = [
    src_svc,
    Path(__file__).resolve().parent / "app/Services/RealDebridSources.php",
    Path("/tmp/RealDebridSources.php"),
]
src_file = next((p for p in candidates if p.exists()), None)
if not src_file:
    raise SystemExit("RealDebridSources.php not found")

dest = root / "app/Services/RealDebridSources.php"
dest.parent.mkdir(parents=True, exist_ok=True)
shutil.copy2(src_file, dest)
print("copied RealDebridSources.php")

# --- bootstrap require ---
boot = root / "app/bootstrap.php"
bt = boot.read_text()
if "RealDebridSources.php" not in bt:
    bt = bt.replace(
        "require_once __DIR__ . '/Services/SourcesService.php';\n",
        "require_once __DIR__ . '/Services/SourcesService.php';\n"
        "require_once __DIR__ . '/Services/RealDebridSources.php';\n",
        1,
    )
    boot.write_text(bt)
    print("bootstrap require")
else:
    print("bootstrap ok")

# --- SourcesService catalog ---
ss = root / "app/Services/SourcesService.php"
st = ss.read_text()
if "'realdebrid'" not in st:
    st = st.replace(
        "        'vidrock' => 'VidRock',\n    ];",
        "        'vidrock' => 'VidRock',\n"
        "        'realdebrid' => 'RealDebrid',\n    ];",
        1,
    )
    # seed: realdebrid only useful on vuflix; keep disabled by default
    ss.write_text(st)
    print("catalog + realdebrid")
else:
    print("catalog has realdebrid")

# Ensure seed inserts realdebrid if missing from DB at runtime — add ensureRealDebrid helper
st = ss.read_text()
if "function ensureCatalogRow" not in st:
    st = st.replace(
        "    public static function seedDefaults(): void\n    {",
        """    /** Ensure catalog rows exist (safe to call often). */
    public static function ensureCatalogRows(): void
    {
        self::seedDefaults();
    }

    public static function seedDefaults(): void
    {""",
        1,
    )
    ss.write_text(st)
    print("ensureCatalogRows alias")

# --- PlayerSources: resolve realdebrid locally ---
ps = root / "app/Services/PlayerSources.php"
pt = ps.read_text()
marker = "            if ($provider === '') {\n                continue;\n            }\n"
inject = """            if ($provider === '') {
                continue;
            }
            // Vuflix-only RealDebrid (Torrentio → RD HTTP links for native player)
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
if "Vuflix-only RealDebrid" not in pt:
    if marker not in pt:
        raise SystemExit("PlayerSources inject marker not found")
    pt = pt.replace(marker, inject, 1)
    # soften empty error message
    pt = pt.replace(
        "'error' => 'No playable sources from VAPlayer/Huhu right now.',",
        "'error' => 'No playable sources right now.',",
        1,
    )
    ps.write_text(pt)
    print("PlayerSources realdebrid branch")
else:
    print("PlayerSources already patched")

# --- routes: admin RD settings + ensure catalog on admin sources ---
routes = root / "app/routes.php"
rt = routes.read_text()

if "/api/admin/realdebrid" not in rt:
    anchor = "$router->get('/api/admin/sources', function () {\n    Auth::requireStaff();\n    json_response(['ok' => true, 'sources' => SourcesService::all(), 'catalog' => SourcesService::CATALOG]);\n});"
    if anchor not in rt:
        # looser
        old = """$router->get('/api/admin/sources', function () {
    Auth::requireStaff();
    json_response(['ok' => true, 'sources' => SourcesService::all(), 'catalog' => SourcesService::CATALOG]);
});"""
    else:
        old = anchor
    if old not in rt:
        raise SystemExit("admin sources route not found")
    new = """$router->get('/api/admin/sources', function () {
    Auth::requireStaff();
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
    Auth::requireStaff();
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
    Auth::requireStaff();
    if (!class_exists('RealDebridSources') || !RealDebridSources::hostAllowed()) {
        json_response(['ok' => false, 'error' => 'RealDebrid is Vuflix-only'], 404);
    }
    $body = Auth::jsonBody();
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
    rt = rt.replace(old, new, 1)
    routes.write_text(rt)
    print("admin realdebrid routes")
else:
    print("routes already have realdebrid")

# --- admin sources UI panel ---
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
    # JS hooks
    at = at.replace(
        "  function load(){\n    fetch(API,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{\n      if(!d||!d.ok){ list.textContent='Failed'; return; }\n      render(d.sources||[]);\n    });\n  }",
        """  function load(){
    fetch(API,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(!d||!d.ok){ list.textContent='Failed'; return; }
      render(d.sources||[]);
      var rd = d.realdebrid;
      var panel = document.getElementById('rd-panel');
      var status = document.getElementById('rd-status');
      if (panel && rd && rd.hostAllowed) {
        panel.hidden = false;
        status.textContent = rd.configured
          ? ('Key saved: ' + (rd.maskedKey || '••••') + ' — enable “RealDebrid” below and Test with a TMDB id')
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
  }""",
        1,
    )
    admin.write_text(at)
    print("admin sources RD panel")
else:
    print("admin panel exists")

# Seed DB row now if possible
try:
    import subprocess
    subprocess.check_call([
        "php", "-r",
        "require '/var/www/chillflix-newsite/app/helpers.php';"
        "require '/var/www/chillflix-newsite/app/Services/Database.php';"
        "require '/var/www/chillflix-newsite/app/Services/Auth.php';"
        "require '/var/www/chillflix-newsite/app/Services/SourcesService.php';"
        "SourcesService::seedDefaults();"
        "echo 'seeded\\n';",
    ])
except Exception as e:
    print("seed skipped:", e)

print("OK realdebrid patch")
