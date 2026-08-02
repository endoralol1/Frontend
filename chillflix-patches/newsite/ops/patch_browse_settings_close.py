#!/usr/bin/env python3
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui65"

OLD = """  function closeBrowseSettings() {
    $('#browse-settings-view').removeClass('is-open').attr('hidden', true);
    $('#browse-home-view').removeAttr('hidden');
    $('#browse-sheet').removeClass('settings-open');
  }"""

NEW = """  function closeBrowseSettings() {
    var $view = $('#browse-settings-view');
    var wasOpen = $view.length && !$view.attr('hidden');
    $view.removeClass('is-open').attr('hidden', true);
    $('#browse-sheet').removeClass('settings-open');
    if (wasOpen) $('#browse-home-view').removeAttr('hidden');
  }"""


def main() -> None:
    path = ROOT / "public/assets/js/app.js"
    text = path.read_text()
    if NEW in text:
        print("already patched")
    elif OLD in text:
        path.write_text(text.replace(OLD, NEW, 1))
        print("closeBrowseSettings patched")
    else:
        raise SystemExit("closeBrowseSettings block not found")

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
