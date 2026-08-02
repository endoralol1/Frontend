#!/usr/bin/env python3
"""Remove Guest/username pill from Browse Your activity section."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui61"


def main() -> None:
    nav = ROOT / "app/Views/partials/bottom-nav.php"
    text = nav.read_text()
    pattern = re.compile(
        r'<div class="browse-stats-head">\s*'
        r'<h3 class="browse-section-title">Your activity</h3>\s*'
        r'<span class="browse-stats-who"[^>]*>.*?</span>\s*'
        r"</div>",
        re.S,
    )
    text2, n = pattern.subn('<h3 class="browse-section-title">Your activity</h3>', text, count=1)
    if not n:
        if 'id="browse-stats-who"' not in text:
            print("who badge already removed")
        else:
            raise SystemExit("stats who badge not found")
    else:
        text = text2
        print("who badge removed from html")
    nav.write_text(text)

    css = ROOT / "public/assets/css/app.css"
    c = css.read_text()
    if ".browse-stats-who {" in c and "display: none !important" not in c.split(".browse-stats-who {", 1)[1][:60]:
        c = c.replace(
            ".browse-stats-who {",
            ".browse-stats-who {\n  display: none !important;",
            1,
        )
        print("who css hidden")
    # unused head styles ok to leave
    css.write_text(c)

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
