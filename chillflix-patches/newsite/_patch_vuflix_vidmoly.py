#!/usr/bin/env python3
"""Wire HiMovies/0123movie → Vidmoly HLS source into Vuflix newsite."""
from pathlib import Path
import shutil

ROOT = Path("/var/www/chillflix-newsite")
SRC = Path("/tmp/VidmolySources.php")

# 1) Install service
dst = ROOT / "app/Services/VidmolySources.php"
shutil.copyfile(SRC, dst)
print("installed", dst)

# 2) bootstrap
boot = ROOT / "app/bootstrap.php"
bt = boot.read_text(encoding="utf-8")
req = "require_once __DIR__ . '/Services/VidmolySources.php';\n"
if req not in bt:
    needle = "require_once __DIR__ . '/Services/StremifySources.php';\n"
    if needle in bt:
        bt = bt.replace(needle, needle + req, 1)
    else:
        needle2 = "require_once __DIR__ . '/Services/SourcesService.php';\n"
        if needle2 not in bt:
            raise SystemExit("bootstrap SourcesService require missing")
        bt = bt.replace(needle2, needle2 + req, 1)
    boot.write_text(bt, encoding="utf-8")
    print("patched bootstrap")
else:
    print("bootstrap already has Vidmoly")

# 3) catalog
ss = ROOT / "app/Services/SourcesService.php"
st = ss.read_text(encoding="utf-8")
if "'vidmoly'" not in st:
    for old, new in [
        (
            "        'stremify' => 'Stremify',\n    ];",
            "        'stremify' => 'Stremify',\n        'vidmoly' => 'Vidmoly',\n    ];",
        ),
        (
            "        'videasy' => 'Videasy',\n    ];",
            "        'videasy' => 'Videasy',\n        'vidmoly' => 'Vidmoly',\n    ];",
        ),
        (
            "        'vidrock' => 'VidRock',\n    ];",
            "        'vidrock' => 'VidRock',\n        'vidmoly' => 'Vidmoly',\n    ];",
        ),
    ]:
        if old in st:
            st = st.replace(old, new, 1)
            ss.write_text(st, encoding="utf-8")
            print("patched SourcesService catalog")
            break
    else:
        raise SystemExit("CATALOG end missing for vidmoly insert")
else:
    print("catalog already has vidmoly")

# 4) PlayerSources branch
ps = ROOT / "app/Services/PlayerSources.php"
pt = ps.read_text(encoding="utf-8")
branch = """            // HiMovies/0123movie → Vidmoly HLS (HTTP scrape)
            if ($provider === 'vidmoly') {
                if (!class_exists('VidmolySources')) {
                    $diagnostics[] = [
                        'code' => 'VIDMOLY_MISSING',
                        'message' => 'VidmolySources class missing',
                        'severity' => 'warning',
                        'provider' => 'vidmoly',
                    ];
                    continue;
                }
                $vm = VidmolySources::fetch($type, $tmdbId, $season, $episode);
                foreach ($vm['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($vm['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $key = 'vidmoly|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => 'vidmoly',
                        'providerName' => (string) ($src['providerName'] ?? 'Vidmoly'),
                        'label' => (string) ($src['label'] ?? 'Vidmoly'),
                        'language' => (string) ($src['language'] ?? ''),
                        'audioTracks' => [],
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($vm['ok']) && empty($vm['sources'])) {
                    $diagnostics[] = [
                        'code' => 'VIDMOLY_EMPTY',
                        'message' => (string) ($vm['error'] ?? 'No Vidmoly streams'),
                        'severity' => 'warning',
                        'provider' => 'vidmoly',
                    ];
                }
                continue;
            }
"""
if "provider === 'vidmoly'" not in pt:
    # Prefer insert after stremify branch if present, else after empty-provider guard.
    if "provider === 'stremify'" in pt:
        # Find end of stremify block: the continue; before next provider handling
        marker = "                continue;\n            }\n            $qs = ["
        if marker not in pt:
            # fallback older shape
            marker = "                continue;\n            }\n            $qs = [\n                'type' => $type,"
        if marker not in pt:
            raise SystemExit("PlayerSources stremify/qs marker missing")
        # Insert before $qs — but only the first continue after stremify.
        # Safer: insert right before `$qs = [`
        qs_anchor = "            $qs = [\n                'type' => $type,"
        if qs_anchor not in pt:
            qs_anchor = "            $qs = ["
        if qs_anchor not in pt:
            raise SystemExit("PlayerSources qs anchor missing")
        pt = pt.replace(qs_anchor, branch + qs_anchor, 1)
    else:
        anchor = "            if ($provider === '') {\n                continue;\n            }\n"
        if anchor not in pt:
            raise SystemExit("PlayerSources provider loop anchor missing")
        pt = pt.replace(anchor, anchor + branch, 1)
    ps.write_text(pt, encoding="utf-8")
    print("patched PlayerSources")
else:
    print("PlayerSources already has vidmoly")

# 5) media-proxy route → prefer VidmolySources (handles rewrite + Stremify fallback)
routes = ROOT / "app/routes.php"
rt = routes.read_text(encoding="utf-8")
old_proxy = """$router->get('/api/player/media-proxy', function () {
    $token = trim((string) ($_GET['t'] ?? ''));
    $sig = trim((string) ($_GET['s'] ?? ''));
    if (!class_exists('StremifySources')) {
        json_response(['ok' => false, 'error' => 'Stremify unavailable'], 404);
    }
    StremifySources::proxyMedia($token, $sig);
});"""
new_proxy = """$router->get('/api/player/media-proxy', function () {
    $token = trim((string) ($_GET['t'] ?? ''));
    $sig = trim((string) ($_GET['s'] ?? ''));
    if (class_exists('VidmolySources')) {
        VidmolySources::proxyMedia($token, $sig);
    }
    if (class_exists('StremifySources')) {
        StremifySources::proxyMedia($token, $sig);
    }
    json_response(['ok' => false, 'error' => 'Media proxy unavailable'], 404);
});"""
if "VidmolySources::proxyMedia" not in rt:
    if old_proxy in rt:
        routes.write_text(rt.replace(old_proxy, new_proxy, 1), encoding="utf-8")
        print("patched media-proxy route")
    else:
        # looser replace
        import re
        pat = r"\$router->get\('/api/player/media-proxy',\s*function\s*\(\)\s*\{.*?\n\}\);"
        m = re.search(pat, rt, re.S)
        if not m:
            raise SystemExit("media-proxy route block not found")
        routes.write_text(rt[: m.start()] + new_proxy + rt[m.end() :], encoding="utf-8")
        print("patched media-proxy route (regex)")
else:
    print("media-proxy already uses VidmolySources")

# 6) seed catalog row (disabled)
php = r'''<?php
require_once "/var/www/chillflix-newsite/app/bootstrap.php";
if (class_exists("SourcesService")) {
    SourcesService::ensureCatalogRows();
    // keep disabled by default
    $pdo = Database::pdo();
    $pdo->prepare("UPDATE sources SET enabled = 0, notes = ? WHERE id = 'vidmoly'")->execute([
        "HiMovies/0123movie → Vidmoly HLS. Movies via imdb+tmdb. TV only if wrapper still lands on Vidmoly. Uses media-proxy. CDN may 403 datacenter IPs."
    ]);
    echo "seeded vidmoly catalog row\n";
}
'''
Path("/tmp/_seed_vidmoly.php").write_text(php, encoding="utf-8")
import subprocess
subprocess.check_call(["php", "/tmp/_seed_vidmoly.php"])
print("done")
