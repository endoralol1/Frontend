#!/usr/bin/env python3
"""Fix Browse Games missing icon + remove duplicate Auto Next chip in player."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui63"

GAMES_ICON = (
    '<span class="browse-tile-icon tone-purple" aria-hidden="true">'
    '<svg class="browse-tile-svg" viewBox="0 0 24 24" width="1.15rem" height="1.15rem" fill="currentColor" focusable="false">'
    '<path d="M9 7h6a6 6 0 0 1 6 6v1.5a3.5 3.5 0 0 1-3.5 3.5h-.35a2 2 0 0 1-1.7-.95l-.55-.9a1 1 0 0 0-.85-.48H10a1 1 0 0 0-.85.48l-.55.9a2 2 0 0 1-1.7.95H6.5A3.5 3.5 0 0 1 3 14.5V13a6 6 0 0 1 6-6Zm-1.75 4.25a.75.75 0 0 0-.75.75v.75H5.75a.75.75 0 0 0 0 1.5H6.5v.75a.75.75 0 0 0 1.5 0v-.75h.75a.75.75 0 0 0 0-1.5H8v-.75a.75.75 0 0 0-.75-.75Zm8.5 1a1 1 0 1 0 0 2 1 1 0 0 0 0-2Zm2.25 1.75a1 1 0 1 0-2 0 1 1 0 0 0 2 0Z"/>'
    "</svg>"
    "</span>"
)

CSS_SVG = """
  .browse-tile-icon svg.browse-tile-svg,
  .browse-tile-icon .browse-tile-svg {
    width: 1.15rem;
    height: 1.15rem;
    display: block;
  }
"""


def bump_assets(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def fix_games_icon() -> None:
    path = ROOT / "app/Views/partials/bottom-nav.php"
    text = path.read_text()
    pat = re.compile(
        r'(<button type="button" class="browse-tile" data-browse-mock="games">\s*)'
        r'<span class="browse-tile-icon tone-purple">[\s\S]*?</span>',
        re.M,
    )
    text2, n = pat.subn(r"\1" + GAMES_ICON, text, count=1)
    if not n:
        if "browse-tile-svg" in text and 'data-browse-mock="games"' in text:
            print("games svg icon already present")
        else:
            raise SystemExit("games tile icon not found")
    else:
        text = text2
        print("games icon -> svg gamepad")
    path.write_text(text)


def fix_app_css() -> None:
    path = ROOT / "public/assets/css/app.css"
    text = path.read_text()
    if "browse-tile-svg" in text:
        print("app.css svg rule already present")
        return
    # Insert after .browse-tile-icon i rule
    needle = "  .browse-tile-icon i {\n    font-size: 1.15rem;\n  }"
    if needle not in text:
        raise SystemExit("browse-tile-icon i rule not found")
    text = text.replace(needle, needle + "\n" + CSS_SVG.rstrip("\n"), 1)
    path.write_text(text)
    print("app.css svg sizing added")


def strip_player_autonext_chip_js() -> None:
    path = ROOT / "public/assets/js/player.js"
    text = path.read_text()
    old = (
        '        ${isTv ? `<button type="button" class="np-chip" id="np-autonext" '
        'aria-pressed="true" title="Auto next episode">Auto Next</button>` : ""}\n'
    )
    if old not in text:
        if 'id="np-autonext"' not in text.split("np-autonext-switch")[0]:
            print("player.js chip already removed")
            return
        raise SystemExit("player.js autonext chip template not found")
    path.write_text(text.replace(old, "", 1))
    print("player.js Auto Next chip removed")


def strip_player_autonext_chip_php() -> None:
    path = ROOT / "app/Views/pages/player.php"
    text = path.read_text()
    old = (
        "                <?php if ($type === 'tv'): ?>\n"
        '                <button type="button" class="np-chip" id="np-autonext" '
        'aria-pressed="true" title="Auto next episode">Auto Next</button>\n'
        "                <?php endif; ?>\n"
    )
    if old not in text:
        if 'id="np-autonext"' not in text:
            print("player.php chip already removed")
            return
        raise SystemExit("player.php autonext chip not found")
    path.write_text(text.replace(old, "", 1))
    print("player.php Auto Next chip removed")


def bump_player_assets() -> None:
    """Bust player.js/css caches so the removed Auto Next chip ships."""
    for rel, pattern, repl in (
        (
            "app/routes.php",
            r"asset\('js/player\.js'\)\s*\.\s*'\?v=[^']+'",
            f"asset('js/player.js') . '?v={ASSET_V}'",
        ),
        (
            "app/routes.php",
            r"asset\('css/player\.css'\)\s*\.\s*'\?v=[^']+'",
            f"asset('css/player.css') . '?v={ASSET_V}'",
        ),
        (
            "app/Views/layouts/player.php",
            r"asset\('js/player\.js'\)\)\s*\?>\?v=[^\"]+",
            f"asset('js/player.js')) ?>?v={ASSET_V}",
        ),
        (
            "app/Views/layouts/player.php",
            r"asset\('css/player\.css'\)\)\s*\?>\?v=[^\"]+",
            f"asset('css/player.css')) ?>?v={ASSET_V}",
        ),
    ):
        path = ROOT / rel
        text = path.read_text()
        text2, n = re.subn(pattern, repl, text)
        if n:
            path.write_text(text2)
            print(f"bumped {rel} x{n}")
        else:
            print(f"skip bump {rel} ({pattern[:40]}...)")


def main() -> None:
    fix_games_icon()
    fix_app_css()
    strip_player_autonext_chip_js()
    strip_player_autonext_chip_php()
    bump_player_assets()
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump_assets(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
