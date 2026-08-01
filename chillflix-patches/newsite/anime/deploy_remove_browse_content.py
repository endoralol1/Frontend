#!/usr/bin/env python3
"""Remove Movies / TV Shows / Anime from Browse (they live in main nav)."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui54"


def main() -> None:
    path = ROOT / "app/Views/partials/bottom-nav.php"
    text = path.read_text()

    # Remove Content section (Movies / TV / Anime)
    pattern = re.compile(
        r'\s*<section class="browse-section(?: browse-section-content)?">\s*'
        r'<h3 class="browse-section-title">Content</h3>[\s\S]*?</section>\s*',
        re.M,
    )
    new_text, n = pattern.subn("\n", text, count=1)
    if n == 0:
        if 'browse-section-title">Content</h3>' not in text:
            print("Content section already removed")
        else:
            raise SystemExit("Content section found but regex failed")
    else:
        text = new_text
        print("Content section removed from browse")

    path.write_text(text)

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("asset version", ASSET_V)
    print("done", ASSET_V)


if __name__ == "__main__":
    main()
