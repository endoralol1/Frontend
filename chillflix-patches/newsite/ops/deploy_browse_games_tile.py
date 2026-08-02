#!/usr/bin/env python3
"""Replace Browse 4K tile with Games (Soon)."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui62"


def main() -> None:
    path = ROOT / "app/Views/partials/bottom-nav.php"
    text = path.read_text()
    new = """                    <button type="button" class="browse-tile" data-browse-mock="games">
                        <span class="browse-tile-icon tone-purple"><i class="uil uil-joystick"></i></span>
                        <span class="browse-tile-label">Games</span>
                        <span class="browse-tile-badge">Soon</span>
                    </button>"""
    pat = re.compile(
        r'<button type="button" class="browse-tile" data-browse-mock="4k">[\s\S]*?</button>',
        re.M,
    )
    text2, n = pat.subn(new.strip(), text, count=1)
    if not n:
        if 'data-browse-mock="games"' in text and ">Games</span>" in text:
            print("games tile already present")
        else:
            raise SystemExit("4k tile not found")
    else:
        text = text2
        print("4k -> games replaced")
    path.write_text(text)

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
