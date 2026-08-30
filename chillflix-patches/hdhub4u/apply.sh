#!/usr/bin/env bash
# Apply HDHub4u provider on vuflix VPS newsite.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
WWW="${WWW:-/var/www/chillflix-newsite}"

cp -a "$WWW/app/bootstrap-services.php" "$WWW/app/bootstrap-services.php.bak-hdhub4u-$(date +%Y%m%d%H%M%S)"
cp -a "$WWW/app/Services/SourcesService.php" "$WWW/app/Services/SourcesService.php.bak-hdhub4u-$(date +%Y%m%d%H%M%S)"
cp -a "$WWW/app/Services/PlayerSources.php" "$WWW/app/Services/PlayerSources.php.bak-hdhub4u-$(date +%Y%m%d%H%M%S)"

cp "$ROOT/HdHub4uSources.php" "$WWW/app/Services/HdHub4uSources.php"

python3 - <<'PY'
from pathlib import Path
www = Path("/var/www/chillflix-newsite")

# bootstrap-services.php
bs = www / "app/bootstrap-services.php"
text = bs.read_text()
needle = "require_once __DIR__ . '/Services/FzMoviesSources.php';"
line = "require_once __DIR__ . '/Services/HdHub4uSources.php';"
if line not in text:
    if needle not in text:
        raise SystemExit("bootstrap needle missing")
    text = text.replace(needle, needle + "\n" + line)
    bs.write_text(text)
    print("bootstrap: added HdHub4uSources")
else:
    print("bootstrap: already present")

# SourcesService catalog
ss = www / "app/Services/SourcesService.php"
text = ss.read_text()
if "'hdhub4u'" not in text:
    needle = "        'fzmovies' => 'FzMovies', // fzmovies.host progressive MP4/MKV mirrors\n"
    insert = needle + "        'hdhub4u' => 'HDHub4u', // hdhub4u.bi → HubDrive/HubCloud FSL\n"
    if needle not in text:
        raise SystemExit("SourcesService needle missing")
    text = text.replace(needle, insert)
    ss.write_text(text)
    print("SourcesService: catalog entry added")
else:
    print("SourcesService: catalog already present")

# PlayerSources localProviderIds + branch
ps = www / "app/Services/PlayerSources.php"
text = ps.read_text()
old = "'yesmovies', 'fzmovies', 'videasy'"
new = "'yesmovies', 'fzmovies', 'hdhub4u', 'videasy'"
if "hdhub4u" not in text.split("localProviderIds")[1].split("\n")[0] if "localProviderIds" in text else True:
    if old in text:
        text = text.replace(old, new, 1)
        print("PlayerSources: localProviderIds updated")
    elif "'hdhub4u'" in text:
        print("PlayerSources: localProviderIds already has hdhub4u")
    else:
        # try broader replace
        import re
        m = re.search(r"\$localProviderIds = \[([^\]]+)\];", text)
        if not m:
            raise SystemExit("localProviderIds missing")
        ids = m.group(1)
        if "'hdhub4u'" not in ids:
            ids2 = ids.replace("'fzmovies'", "'fzmovies', 'hdhub4u'")
            text = text[:m.start(1)] + ids2 + text[m.end(1):]
            print("PlayerSources: localProviderIds patched via regex")
else:
    print("PlayerSources: localProviderIds check ok")

branch = r'''
            // HDHub4u → HubDrive/HubCloud FSL progressive files (Vuflix-only).
            if ($provider === 'hdhub4u') {
                if (!class_exists('HdHub4uSources') || !HdHub4uSources::hostAllowed()) {
                    $diagnostics[] = [
                        'code' => 'HDHUB4U_HOST_BLOCKED',
                        'message' => 'HDHub4u is Vuflix-only for now',
                        'severity' => 'info',
                        'provider' => 'hdhub4u',
                    ];
                    continue;
                }
                $hh = HdHub4uSources::fetch($type, $tmdbId, $season, $episode);
                foreach ($hh['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($hh['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $qualities = [];
                    foreach ($src['qualities'] ?? [] as $q) {
                        if (!is_array($q) || empty($q['url'])) {
                            continue;
                        }
                        $qualities[] = [
                            'quality' => (string) ($q['quality'] ?? 'Auto'),
                            'url' => (string) $q['url'],
                            'type' => (string) ($q['type'] ?? 'file'),
                        ];
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    $key = 'hdhub4u|' . ($qualities !== [] ? 'pack|' . $quality : $streamUrl);
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'file'),
                        'quality' => $quality !== '' ? $quality : 'Auto',
                        'provider' => 'hdhub4u',
                        'providerName' => (string) ($src['providerName'] ?? 'HDHub4u'),
                        'label' => (string) ($src['label'] ?? ('HDHub4u · ' . $quality)),
                        'language' => (string) ($src['language'] ?? 'hi'),
                        'hasEnglish' => array_key_exists('hasEnglish', $src) ? (bool) $src['hasEnglish'] : true,
                        'audioTracks' => [],
                        'qualities' => $qualities,
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($hh['ok']) && empty($hh['sources'])) {
                    $diagnostics[] = [
                        'code' => 'HDHUB4U_EMPTY',
                        'message' => (string) ($hh['error'] ?? 'No HDHub4u streams'),
                        'severity' => 'warning',
                        'provider' => 'hdhub4u',
                    ];
                }
                continue;
            }

'''

if "if ($provider === 'hdhub4u')" not in text and "if ($provider === 'hdhub4u')" not in text:
    pass
if "provider === 'hdhub4u'" not in text:
    marker = "            // FzMovies → progressive MP4/MKV mirrors (Vuflix-first).\n"
    if marker not in text:
        # insert before moonflix or after yesmovies
        marker2 = "            if ($provider === 'moonflix') {\n"
        if marker2 not in text:
            raise SystemExit("PlayerSources branch marker missing")
        text = text.replace(marker2, branch + marker2, 1)
    else:
        text = text.replace(marker, branch + marker, 1)
    print("PlayerSources: hdhub4u branch inserted")
else:
    print("PlayerSources: hdhub4u branch already present")

ps.write_text(text)
print("PlayerSources written")
PY

# Enable in DB + seed catalog row
cd "$WWW"
php -r '
require_once "app/bootstrap-services.php";
SourcesService::ensureCatalogRows();
$now = Auth::now();
Database::pdo()->prepare(
  "INSERT INTO sources (id, name, public_label, enabled, sort_order, scrape_timeout_sec, notes, updated_at)
   VALUES (\"hdhub4u\", \"HDHub4u\", \"HDHub4u\", 1, 455, 90, \"HubDrive→HubCloud FSL\", ?)
   ON DUPLICATE KEY UPDATE enabled=1, scrape_timeout_sec=90, name=VALUES(name), notes=VALUES(notes), updated_at=VALUES(updated_at)"
)->execute([$now]);
$row = Database::pdo()->query("SELECT id,name,enabled,scrape_timeout_sec FROM sources WHERE id=\"hdhub4u\"")->fetch(PDO::FETCH_ASSOC);
print_r($row);
'

# Syntax check
php -l "$WWW/app/Services/HdHub4uSources.php"
php -l "$WWW/app/Services/PlayerSources.php"
php -l "$WWW/app/bootstrap-services.php"
echo DONE
