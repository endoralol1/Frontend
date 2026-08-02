#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path("/var/www/chillflix-newsite")

js = ROOT / "public/assets/js/app.js"
text = js.read_text()
text2 = text.replace(
    "var base = (window.CF_BASE || '');",
    "var base = (window.CF_BASE || (window.APP && APP.baseUrl) || '');",
)
js.write_text(text2)
print("authApi base replacements", text.count("var base = (window.CF_BASE || '');"), "->", text2.count("var base = (window.CF_BASE || (window.APP && APP.baseUrl) || '');"))

layout = ROOT / "app/Views/layouts/main.php"
lt = layout.read_text()
if "window.CF_BASE" not in lt:
    lt = lt.replace(
        "window.APP = {",
        "window.CF_BASE = <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>;\n        window.APP = {",
        1,
    )
layout.write_text(re.sub(r"20260801-ui\d+", "20260801-ui89", lt))
print("layout asset", set(re.findall(r"20260801-ui\d+", layout.read_text())))
