#!/usr/bin/env python3
from pathlib import Path
import re

discover = Path("/var/www/chillflix-newsite/app/Views/pages/discover.php")
js = Path("/var/www/chillflix-newsite/public/assets/js/app.js")

t = discover.read_text()
t2, n = re.subn(
    r"url\(\$path\) \. \(\$viewMode !== 'grid' \? \('\?view=' \. rawurlencode\(\$viewMode\)\) : ''\)",
    "url($path)",
    t,
)
discover.write_text(t2)
print(f"discover view query cleanups: {n}")

js_t = js.read_text()
pat = re.compile(
    r"if \(href\) \{\n"
    r"\s*var view = \$\('#view-mode-input'\)\.val\(\);\n"
    r"\s*location\.href = href \+ \(view && view !== 'grid' \? \('\?view=' \+ encodeURIComponent\(view\)\) : ''\);\n"
    r"\s*\}",
    re.M,
)
js2, njs = pat.subn("if (href) {\n        location.href = href;\n      }", js_t, count=1)
if njs != 1:
    # show nearby for debug
    idx = js_t.find("view-mode-input")
    raise SystemExit(f"js cleanup failed n={njs} idx={idx} snippet={js_t[idx-80:idx+160]!r}")
js.write_text(js2)
print("js view-mode-input usage removed")

# Confirm toggle gone
assert "view-switcher" not in discover.read_text()
assert "btn-view-switcher" not in discover.read_text()
assert "$viewMode = 'list';" in discover.read_text()
print("DONE")
