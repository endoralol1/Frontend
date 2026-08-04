#!/usr/bin/env python3
"""Add experimental Stremify (Hayduk) test provider to Vuflix."""
from pathlib import Path
import shutil
import subprocess

ROOT = Path("/var/www/chillflix-newsite")
SRC = Path("/tmp/StremifySources.php")

# 1) Install service
dst = ROOT / "app/Services/StremifySources.php"
shutil.copyfile(SRC, dst)
print("installed", dst)

# 2) bootstrap
boot = ROOT / "app/bootstrap.php"
bt = boot.read_text(encoding="utf-8")
req = "require_once __DIR__ . '/Services/StremifySources.php';\n"
if req not in bt:
    needle = "require_once __DIR__ . '/Services/SourcesService.php';\n"
    if needle not in bt:
        raise SystemExit("bootstrap SourcesService require missing")
    bt = bt.replace(needle, needle + req, 1)
    boot.write_text(bt, encoding="utf-8")
    print("patched bootstrap")
else:
    print("bootstrap already has Stremify")

# 3) catalog
ss = ROOT / "app/Services/SourcesService.php"
st = ss.read_text(encoding="utf-8")
if "'stremify'" not in st:
    old = "        'vidrock' => 'VidRock',\n    ];"
    new = "        'vidrock' => 'VidRock',\n        'stremify' => 'Stremify',\n    ];"
    if old not in st:
        raise SystemExit("CATALOG end missing")
    ss.write_text(st.replace(old, new, 1), encoding="utf-8")
    print("patched SourcesService catalog")
else:
    print("catalog already has stremify")

# 4) PlayerSources branch
ps = ROOT / "app/Services/PlayerSources.php"
pt = ps.read_text(encoding="utf-8")
branch = """            // Experimental Stremify (Hayduk) — Vuflix test provider
            if ($provider === 'stremify') {
                if (!class_exists('StremifySources') || !StremifySources::hostAllowed()) {
                    $diagnostics[] = [
                        'code' => 'STREMIFY_HOST_BLOCKED',
                        'message' => 'Stremify test provider is Vuflix-only',
                        'severity' => 'info',
                        'provider' => 'stremify',
                    ];
                    continue;
                }
                $sf = StremifySources::fetch($type, $tmdbId, $season, $episode);
                foreach ($sf['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($sf['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $key = 'stremify|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'file'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => 'stremify',
                        'providerName' => (string) ($src['providerName'] ?? 'Stremify'),
                        'label' => (string) ($src['label'] ?? 'Stremify'),
                        'language' => (string) ($src['language'] ?? ''),
                        'audioTracks' => [],
                    ];
                }
                if (empty($sf['ok']) && empty($sf['sources'])) {
                    $diagnostics[] = [
                        'code' => 'STREMIFY_EMPTY',
                        'message' => (string) ($sf['error'] ?? 'No Stremify streams'),
                        'severity' => 'warning',
                        'provider' => 'stremify',
                    ];
                }
                continue;
            }
"""
if "provider === 'stremify'" not in pt:
    anchor = "            if ($provider === '') {\n                continue;\n            }\n"
    if anchor not in pt:
        raise SystemExit("PlayerSources provider loop anchor missing")
    pt = pt.replace(anchor, anchor + branch, 1)
    ps.write_text(pt, encoding="utf-8")
    print("patched PlayerSources")
else:
    print("PlayerSources already has stremify")

# 5) routes — media proxy + ensure catalog picks up new row
rp = ROOT / "app/routes.php"
rt = rp.read_text(encoding="utf-8")
proxy_route = """
$router->get('/api/player/media-proxy', function () {
    $token = trim((string) ($_GET['t'] ?? ''));
    $sig = trim((string) ($_GET['s'] ?? ''));
    if (!class_exists('StremifySources')) {
        json_response(['ok' => false, 'error' => 'Stremify unavailable'], 404);
    }
    StremifySources::proxyMedia($token, $sig);
});
"""
if "/api/player/media-proxy" not in rt:
    needle = "$router->get('/api/player/caption', function () {\n    $url = trim((string) ($_GET['url'] ?? ''));\n    PlayerSources::proxyCaption($url);\n});\n"
    if needle not in rt:
        raise SystemExit("caption route missing")
    rt = rt.replace(needle, needle + proxy_route, 1)
    rp.write_text(rt, encoding="utf-8")
    print("patched routes media-proxy")
else:
    print("media-proxy route exists")

# 6) Admin note under sources head
admin = ROOT / "app/Views/pages/admin/sources.php"
at = admin.read_text(encoding="utf-8")
note = """    <p class="muted" style="margin:.35rem 0 0;font-size:.82rem;color:rgba(255,255,255,.45)">
      Experimental: <b>Stremify</b> (Hayduk public addon) can be enabled below for testing on vuflix.co only.
      It scrapes free HTTP streams — flaky, often low quality, and many links need proxy headers. Keep disabled for normal traffic.
    </p>
"""
if "Experimental: <b>Stremify</b>" not in at:
    marker = "<p>Enable/disable, drag order (top = tested first), and probe with any movie/TV TMDB id. Users see Alpha/Beta labels.</p>\n"
    if marker not in at:
        raise SystemExit("admin head blurb missing")
    at = at.replace(marker, marker + note, 1)
    admin.write_text(at, encoding="utf-8")
    print("patched admin note")
else:
    print("admin note exists")

# 7) Seed catalog row (disabled)
php = r'''
<?php
$_SERVER["HTTP_HOST"] = "vuflix.co";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"] = "/__stremify_seed";
require "/var/www/chillflix-newsite/app/helpers.php";
require "/var/www/chillflix-newsite/app/Services/Database.php";
require "/var/www/chillflix-newsite/app/Services/Auth.php";
require "/var/www/chillflix-newsite/app/Services/SourcesService.php";
SourcesService::ensureCatalogRows();
$pdo = Database::pdo();
$row = $pdo->query("SELECT id,name,enabled FROM sources WHERE id='stremify'")->fetch(PDO::FETCH_ASSOC);
echo json_encode($row, JSON_PRETTY_PRINT), "\n";
$all = $pdo->query("SELECT id,enabled FROM sources ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($all), "\n";
'''
Path("/tmp/_stremify_seed.php").write_text(php, encoding="utf-8")
print(subprocess.check_output(["php", "/tmp/_stremify_seed.php"], text=True))

for f in [
    "app/bootstrap.php",
    "app/Services/StremifySources.php",
    "app/Services/PlayerSources.php",
    "app/Services/SourcesService.php",
    "app/routes.php",
    "app/Views/pages/admin/sources.php",
]:
    subprocess.check_call(["php", "-l", str(ROOT / f)])

print("OK")
