#!/usr/bin/env python3
"""Replace Browse 4K tile with Games (Soon)."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui63"


def main() -> None:
    path = ROOT / "app/Views/partials/bottom-nav.php"
    text = path.read_text()
    new = """                    <button type="button" class="browse-tile" data-browse-mock="games">
                        <span class="browse-tile-icon tone-purple" aria-hidden="true"><svg class="browse-tile-svg" viewBox="0 0 24 24" width="1.15rem" height="1.15rem" fill="currentColor" focusable="false"><path d="M9 7h6a6 6 0 0 1 6 6v1.5a3.5 3.5 0 0 1-3.5 3.5h-.35a2 2 0 0 1-1.7-.95l-.55-.9a1 1 0 0 0-.85-.48H10a1 1 0 0 0-.85.48l-.55.9a2 2 0 0 1-1.7.95H6.5A3.5 3.5 0 0 1 3 14.5V13a6 6 0 0 1 6-6Zm-1.75 4.25a.75.75 0 0 0-.75.75v.75H5.75a.75.75 0 0 0 0 1.5H6.5v.75a.75.75 0 0 0 1.5 0v-.75h.75a.75.75 0 0 0 0-1.5H8v-.75a.75.75 0 0 0-.75-.75Zm8.5 1a1 1 0 1 0 0 2 1 1 0 0 0 0-2Zm2.25 1.75a1 1 0 1 0-2 0 1 1 0 0 0 2 0Z"/></svg></span>
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
