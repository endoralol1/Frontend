#!/usr/bin/env python3
from pathlib import Path
import re

TAG = "    <script src=\"<?= e(asset('js/bot-shield.js')) ?>?v=20260825-bs1\"></script>"

for path in [
    Path("/var/www/chillflix-newsite/app/Views/layouts/main.php"),
    Path("/var/www/chillflix-newsite/app/Views/layouts/player.php"),
]:
    t = path.read_text()
    lines = t.splitlines()
    out = []
    replaced = False
    for line in lines:
        if "bot-shield" in line:
            out.append(TAG)
            replaced = True
        else:
            out.append(line)
    if not replaced:
        # insert before </head>
        out2 = []
        done = False
        for line in out:
            if not done and line.strip() == "</head>":
                out2.append(TAG)
                done = True
            out2.append(line)
        out = out2
        replaced = done
    path.write_text("\n".join(out) + ("\n" if t.endswith("\n") else ""))
    print(path.name, "replaced" if replaced else "FAILED")
    for line in path.read_text().splitlines():
        if "bot-shield" in line:
            print(" ", line)
