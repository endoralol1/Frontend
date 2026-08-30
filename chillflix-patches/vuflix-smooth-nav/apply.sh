#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
WWW=/var/www/chillflix-newsite

cp "$ROOT/app.docksearch-1240.js" "$WWW/public/assets/js/app.docksearch-1240.js"
cp "$ROOT/soft-nav-transitions.css" "$WWW/public/assets/css/soft-nav-transitions.css"

python3 - <<'PY'
from pathlib import Path
import re
main = Path("/var/www/chillflix-newsite/app/Views/layouts/main.php")
t = main.read_text()

if "soft-nav-transitions.css" not in t:
    m = re.search(
        r'<link rel="stylesheet" href="<\?= e\(asset\(\'css/app-docksearch-1248\.css\'\)\) \?>\?v=[^"]+">',
        t,
    )
    if not m:
        raise SystemExit("docksearch css link not found")
    inject = (
        m.group(0)
        + '\n    <link rel="stylesheet" href="<?= e(asset(\'css/soft-nav-transitions.css\')) ?>?v=20260830-smooth1">'
    )
    t = t.replace(m.group(0), inject, 1)
    print("css linked")
else:
    print("css already linked")

t2, n = re.subn(
    r"asset\('js/app\.docksearch-\d+\.js'\)\) \?>\?v=[^\"]+",
    "asset('js/app.docksearch-1240.js')) ?>?v=20260830-smooth1",
    t,
    count=1,
)
if n != 1 and "app.docksearch-1240.js" not in t:
    raise SystemExit("app.docksearch script tag not found")
if n == 1:
    t = t2
    print("js bumped to 1240")
else:
    print("js already 1240")

main.write_text(t)
print("main.php updated")
PY

echo DONE
