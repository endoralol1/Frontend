#!/usr/bin/env python3
"""Desktop: Browse as dropdown under nav; hide search scrollbars."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
LOCAL = Path(__file__).resolve().parent
ASSET_V = "20260801-ui50"


def main() -> None:
    patch = (LOCAL / "desktop-browse-dropdown.css").read_text()
    app_css = ROOT / "public/assets/css/app.css"
    text = app_css.read_text()
    marker = "/* desktop-browse-dropdown */"
    if marker in text:
        text = re.sub(
            r"/\* desktop-browse-dropdown \*/[\s\S]*?(?=\n/\* [a-z]|\Z)",
            marker + "\n" + patch + "\n",
            text,
            count=1,
        )
        print("css block replaced")
    else:
        text = text.rstrip() + "\n\n" + marker + "\n" + patch + "\n"
        print("css block appended")
    app_css.write_text(text)

    js_path = ROOT / "public/assets/js/app.js"
    js = js_path.read_text()

    helper = """
  function positionBrowseDropdown() {
    if (window.innerWidth < 992) {
      var p0 = document.querySelector('.browse-sheet-panel');
      if (p0) {
        p0.style.top = '';
        p0.style.right = '';
        p0.style.left = '';
        p0.style.transform = '';
      }
      return;
    }
    var btn = document.querySelector('.bottom-nav-browse');
    var panel = document.querySelector('.browse-sheet-panel');
    if (!btn || !panel) return;
    var r = btn.getBoundingClientRect();
    panel.style.top = Math.round(r.bottom + 10) + 'px';
    panel.style.right = Math.round(Math.max(12, window.innerWidth - r.right)) + 'px';
    panel.style.left = 'auto';
    panel.style.transform = 'none';
  }

"""
    if "function positionBrowseDropdown" not in js:
        if "function openBrowseSheet()" not in js:
            raise SystemExit("openBrowseSheet missing")
        js = js.replace("  function openBrowseSheet()", helper + "  function openBrowseSheet()", 1)
        print("helper inserted")
    else:
        print("helper exists")

    m = re.search(r"function openBrowseSheet\(\)\s*\{[\s\S]*?\n  \}", js)
    if not m:
        raise SystemExit("openBrowseSheet block not found")
    block = m.group(0)
    if "positionBrowseDropdown" not in block:
        new_block = block[:-1] + "    try { positionBrowseDropdown(); } catch (e) {}\n  }"
        js = js[: m.start()] + new_block + js[m.end() :]
        print("openBrowseSheet patched")
    else:
        print("openBrowseSheet already calls position")

    if "cf-browse-dropdown-reposition" not in js:
        js += """

/* cf-browse-dropdown-reposition */
(function () {
  function onBrowseReposition() {
    if (document.body.classList.contains('browse-open')) {
      try { positionBrowseDropdown(); } catch (e) {}
    }
  }
  window.addEventListener('resize', onBrowseReposition, { passive: true });
  window.addEventListener('scroll', onBrowseReposition, { passive: true });
})();
"""
        print("reposition listeners added")

    js_path.write_text(js)

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("deployed", ASSET_V)


if __name__ == "__main__":
    main()
