#!/bin/bash
# Apply Videasy provider on the VPS (run after SSH is restored).
set -euo pipefail
ROOT=/var/www/chillflix-newsite
PATCH="$(cd "$(dirname "$0")" && pwd)"

cp -a "$PATCH/VideasySources.php" "$ROOT/app/Services/VideasySources.php"
cp -a "$PATCH/videasy-resolve.mjs" "$ROOT/bin/videasy-resolve.mjs"
chmod 755 "$ROOT/bin/videasy-resolve.mjs"

# bootstrap require
if ! grep -q 'VideasySources.php' "$ROOT/app/bootstrap-services.php"; then
  sed -i "/YesMoviesSources.php/a require_once __DIR__ . '/Services/VideasySources.php';" \
    "$ROOT/app/bootstrap-services.php"
fi

# PlayerSources local list
python3 - <<'PY'
from pathlib import Path
p = Path('/var/www/chillflix-newsite/app/Services/PlayerSources.php')
t = p.read_text()
if "'videasy'" not in t.split('localProviderIds')[1][:500]:
    t = t.replace(
        "'yesmovies', 'ridomovies'",
        "'yesmovies', 'videasy', 'ridomovies'",
        1,
    )
# Insert fetch branch before yesmovies if missing
if "provider === 'videasy'" not in t:
    needle = "            // YesMovies → ployan.me direct HLS"
    block = r'''            // Videasy (Cinemove VE) — speedracelight multi-server.
            if ($provider === 'videasy') {
                if (!class_exists('VideasySources')) {
                    $diagnostics[] = [
                        'code' => 'VIDEASY_MISSING',
                        'message' => 'VideasySources class not loaded',
                        'severity' => 'warning',
                        'provider' => 'videasy',
                    ];
                    continue;
                }
                $ve = VideasySources::fetch($type, $tmdbId, $season, $episode);
                foreach ($ve['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($ve['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    $key = 'videasy|' . ($src['qualities'] ?? null ? 'pack' : $streamUrl);
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'hls'),
                        'quality' => $quality !== '' ? $quality : 'Auto',
                        'qualities' => is_array($src['qualities'] ?? null) ? $src['qualities'] : [],
                        'provider' => 'videasy',
                        'providerName' => (string) ($src['providerName'] ?? 'Videasy'),
                        'label' => (string) ($src['label'] ?? ('Videasy · ' . $quality)),
                        'language' => (string) ($src['language'] ?? 'en'),
                        'hasEnglish' => !empty($src['hasEnglish']),
                        'audioTracks' => [],
                        'subtitles' => is_array($src['subtitles'] ?? null) ? $src['subtitles'] : [],
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($ve['ok']) && empty($ve['sources'])) {
                    $diagnostics[] = [
                        'code' => 'VIDEASY_EMPTY',
                        'message' => (string) ($ve['error'] ?? 'No Videasy streams'),
                        'severity' => 'warning',
                        'provider' => 'videasy',
                    ];
                }
                continue;
            }

'''
    if needle not in t:
        raise SystemExit('yesmovies needle missing')
    t = t.replace(needle, block + needle, 1)
p.write_text(t)
print('PlayerSources patched')
PY

# fetch-local-provider
python3 - <<'PY'
from pathlib import Path
p = Path('/var/www/chillflix-newsite/bin/fetch-local-provider.php')
t = p.read_text()
if "'videasy'" not in t:
    t = t.replace(
        "'yesmovies' => class_exists('YesMoviesSources')",
        "'yesmovies' => class_exists('YesMoviesSources'),\n    'videasy' => class_exists('VideasySources')",
        1,
    )
    # add case body - look for yesmovies match arm
    if "videasy" not in t.split('match')[1] if 'match' in t else t:
        pass
p.write_text(t)
print('fetch-local patched')
PY

mysql newsite -e "UPDATE sources SET enabled=1, public_label='VE', sort_order=35, notes='Videasy (Cinemove VE) — speedracelight multi-server' WHERE id='videasy'; SELECT id,public_label,enabled,sort_order FROM sources WHERE id='videasy';"

# smoke
cd "$ROOT" && timeout 50 node bin/videasy-resolve.mjs --type movie --tmdb 157336 | head -c 400; echo
php -r "require 'app/bootstrap-services.php'; \$r=VideasySources::fetch('movie',157336); echo json_encode(['ok'=>\$r['ok']??null,'n'=>count(\$r['sources']??[]),'err'=>\$r['error']??null,'diag0'=>\$r['diagnostics'][0]??null], JSON_PRETTY_PRINT), PHP_EOL;"

echo DONE
