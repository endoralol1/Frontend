#!/usr/bin/env python3
"""Seed Continue Watching as soon as a watch page opens (proven root cause: empty LS)."""
from __future__ import annotations

import re
from pathlib import Path

LOCAL = Path(__file__).resolve().parent
ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui19"

SEED_MARKER = "/* cw-seed-on-watch-page:"
SEED_SNIPPET = (LOCAL / "cw-seed-inline.php").read_text()


def sync_assets() -> None:
    (ROOT / "public/assets/js/player.js").write_text((LOCAL / "player.js").read_text())
    (ROOT / "public/assets/js/continue-party.js").write_text((LOCAL / "continue-party.js").read_text())
    if (LOCAL / "continue-party.css").exists():
        (ROOT / "public/assets/css/continue-party.css").write_text(
            (LOCAL / "continue-party.css").read_text()
        )
    print("synced assets")


def bump() -> None:
    for rel in ("app/Views/layouts/main.php", "app/routes.php"):
        path = ROOT / rel
        text = path.read_text()
        text = re.sub(r"20260801-ui\d+", ASSET_V, text)
        text = re.sub(
            r"asset\('js/player\.js'\) \. '\?v=[^']+'",
            f"asset('js/player.js') . '?v={ASSET_V}'",
            text,
        )
        text = re.sub(
            r"asset\('css/player\.css'\) \. '\?v=[^']+'",
            f"asset('css/player.css') . '?v={ASSET_V}'",
            text,
        )
        path.write_text(text)
        print("bumped", rel)

    app = ROOT / "public/assets/js/app.js"
    t = app.read_text()
    t2 = re.sub(
        r"(base \+ '/assets/js/player\.js\?v=)20260801-ui\d+",
        r"\g<1>" + ASSET_V,
        t,
    )
    if t2 != t:
        app.write_text(t2)
        print("bumped app.js player asset ref")


def patch_watch() -> None:
    watch = ROOT / "app/Views/pages/watch.php"
    text = watch.read_text()
    if SEED_MARKER in text:
        # refresh snippet
        text = re.sub(
            r"    <script>\n        /\* cw-seed-on-watch-page:.*?</script>\n",
            SEED_SNIPPET if SEED_SNIPPET.endswith("\n") else SEED_SNIPPET + "\n",
            text,
            count=1,
            flags=re.S,
        )
        watch.write_text(text)
        print("refreshed watch seed snippet")
        return

    needle = "        window.PLAYER = <?= json_encode($playerConfig ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;\n    </script>\n"
    if needle not in text:
        raise SystemExit("PLAYER assign block missing")
    text = text.replace(needle, needle + SEED_SNIPPET, 1)
    watch.write_text(text)
    print("injected watch-page continue seed")


def main() -> None:
    sync_assets()
    bump()
    patch_watch()
    # verify
    w = (ROOT / "app/Views/pages/watch.php").read_text()
    assert SEED_MARKER in w
    print("deploy_continue_seed_on_open done", ASSET_V)


if __name__ == "__main__":
    main()
