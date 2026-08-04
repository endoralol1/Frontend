#!/usr/bin/env python3
"""Wire Byse/UpCloud HLS resolver into Vuflix newsite."""
from pathlib import Path
import shutil
import subprocess

ROOT = Path("/var/www/chillflix-newsite")
TMP = Path("/tmp")

# 1) Install Node helper + PHP service
bin_dir = ROOT / "bin"
bin_dir.mkdir(parents=True, exist_ok=True)
shutil.copyfile(TMP / "byse-resolve.mjs", bin_dir / "byse-resolve.mjs")
(bin_dir / "byse-resolve.mjs").chmod(0o755)
print("installed", bin_dir / "byse-resolve.mjs")

dst = ROOT / "app/Services/ByseSources.php"
shutil.copyfile(TMP / "ByseSources.php", dst)
print("installed", dst)

# 2) bootstrap
boot = ROOT / "app/bootstrap.php"
bt = boot.read_text(encoding="utf-8")
req = "require_once __DIR__ . '/Services/ByseSources.php';\n"
if req not in bt:
    needle = "require_once __DIR__ . '/Services/VidmolySources.php';\n"
    if needle in bt:
        bt = bt.replace(needle, needle + req, 1)
    else:
        needle = "require_once __DIR__ . '/Services/SourcesService.php';\n"
        bt = bt.replace(needle, needle + req, 1)
    boot.write_text(bt, encoding="utf-8")
    print("patched bootstrap")
else:
    print("bootstrap already has Byse")

# 3) catalog as upcloud
ss = ROOT / "app/Services/SourcesService.php"
st = ss.read_text(encoding="utf-8")
if "'upcloud'" not in st:
    for old, new in [
        (
            "        'vidmoly' => 'Vidmoly',\n    ];",
            "        'vidmoly' => 'Vidmoly',\n        'upcloud' => 'UpCloud',\n    ];",
        ),
        (
            "        'stremify' => 'Stremify',\n    ];",
            "        'stremify' => 'Stremify',\n        'upcloud' => 'UpCloud',\n    ];",
        ),
    ]:
        if old in st:
            ss.write_text(st.replace(old, new, 1), encoding="utf-8")
            print("patched catalog +upcloud")
            break
    else:
        raise SystemExit("catalog insert point missing")
else:
    print("catalog already has upcloud")

# 4) PlayerSources branch
ps = ROOT / "app/Services/PlayerSources.php"
pt = ps.read_text(encoding="utf-8")
branch = """            // HiMovies UpCloud → Byse (gn1r5n) HLS via PoW helper
            if ($provider === 'upcloud' || $provider === 'byse') {
                if (!class_exists('ByseSources')) {
                    $diagnostics[] = [
                        'code' => 'UPCLOUD_MISSING',
                        'message' => 'ByseSources class missing',
                        'severity' => 'warning',
                        'provider' => 'upcloud',
                    ];
                    continue;
                }
                $uc = ByseSources::fetch($type, $tmdbId, $season, $episode);
                foreach ($uc['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($uc['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $key = 'upcloud|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => (string) ($src['quality'] ?? 'Auto'),
                        'provider' => 'upcloud',
                        'providerName' => (string) ($src['providerName'] ?? 'UpCloud'),
                        'label' => (string) ($src['label'] ?? 'UpCloud'),
                        'language' => (string) ($src['language'] ?? ''),
                        'audioTracks' => [],
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($uc['ok']) && empty($uc['sources'])) {
                    $diagnostics[] = [
                        'code' => 'UPCLOUD_EMPTY',
                        'message' => (string) ($uc['error'] ?? 'No UpCloud/Byse streams'),
                        'severity' => 'warning',
                        'provider' => 'upcloud',
                    ];
                }
                continue;
            }
"""
if "provider === 'upcloud'" not in pt:
    qs_anchor = "            $qs = [\n                'type' => $type,"
    if qs_anchor not in pt:
        qs_anchor = "            $qs = ["
    if qs_anchor not in pt:
        raise SystemExit("PlayerSources qs anchor missing")
    pt = pt.replace(qs_anchor, branch + qs_anchor, 1)
    ps.write_text(pt, encoding="utf-8")
    print("patched PlayerSources")
else:
    print("PlayerSources already has upcloud")

# 5) Admin note
admin = ROOT / "app/Views/pages/admin/sources.php"
if admin.is_file():
    at = admin.read_text(encoding="utf-8")
    if "UpCloud</b>" not in at and "Byse" not in at:
        old = "Experimental: <b>Vidmoly</b>"
        new = "Experimental: <b>UpCloud</b> (Byse/gn1r5n PoW→HLS) and <b>Vidmoly</b>"
        if old in at:
            admin.write_text(at.replace(old, new, 1), encoding="utf-8")
            print("patched admin note")
        else:
            print("admin note pattern missing")
    else:
        print("admin note already mentions UpCloud/Byse")

# 6) seed catalog disabled by default
php = r'''<?php
$_SERVER["HTTP_HOST"] = "vuflix.co";
$_SERVER["HTTPS"] = "on";
ob_start();
require "/var/www/chillflix-newsite/app/bootstrap.php";
ob_end_clean();
SourcesService::ensureCatalogRows();
Database::pdo()->prepare("UPDATE sources SET enabled = 0, notes = ? WHERE id = 'upcloud'")->execute([
    "HiMovies UpCloud → Byse (gn1r5n). PoW captcha + AES playback decrypt via bin/byse-resolve.mjs. Also covers many mislabeled Vidmoly buttons."
]);
echo "seeded upcloud\n";
'''
Path("/tmp/_seed_upcloud.php").write_text(php, encoding="utf-8")
subprocess.check_call(["php", "/tmp/_seed_upcloud.php"])

# 7) smoke Spider-Man
print("smoke resolve…")
subprocess.check_call(
    [
        "node",
        str(bin_dir / "byse-resolve.mjs"),
        "--imdb",
        "tt22084616",
        "--tmdb",
        "969681",
        "--kind",
        "mv",
        "--referer",
        "https://himovies.watch/movie/spider-man-brand-new-day-56025/",
    ]
)
print("done")
