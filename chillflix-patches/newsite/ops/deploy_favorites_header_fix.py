#!/usr/bin/env python3
"""Fix Favorites/Search/Top IMDB title overlapping floating header logo."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui55"


def patch_page(name: str, main_class: str = "page-pad-top") -> None:
    path = ROOT / f"app/Views/pages/{name}.php"
    text = path.read_text()
    # Drop useless late headerClass assignment (header already rendered)
    text = re.sub(r"^<\?php \$headerClass = 'relative'; \?>\n", "", text)
    if 'class="page-pad-top' in text or "class='page-pad-top" in text:
        print(f"{name}: page-pad-top exists")
    else:
        text = text.replace("<main>", f'<main class="{main_class}">', 1)
        print(f"{name}: page-pad-top added")
    path.write_text(text)


def patch_favorites_head() -> None:
    path = ROOT / "app/Views/pages/favorites.php"
    text = path.read_text()
    # Prefer short title matching screenshot language
    text = text.replace(
        '<h1 class="title gardiently">My Favorites</h1>',
        '<h1 class="title gardiently">Favorites</h1>',
        1,
    )
    # Wrap head for mobile stacking so tabs/clear don't collide
    if "favorites-page" not in text:
        text = text.replace(
            '<main class="page-pad-top">',
            '<main class="page-pad-top favorites-page">',
            1,
        )
        print("favorites-page class added")
    path.write_text(text)


def patch_css() -> None:
    css = ROOT / "public/assets/css/app.css"
    text = css.read_text()
    marker = "/* favorites-header-fix */"
    body = """main.page-pad-top {
  padding-top: 4.75rem;
}
@media (max-width: 991.98px) {
  main.page-pad-top {
    padding-top: 4.25rem;
  }
  .favorites-page .section .head {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 0.75rem;
  }
  .favorites-page .section .head .start {
    flex-wrap: wrap !important;
    row-gap: 0.55rem;
  }
  .favorites-page .section .head .start .title {
    flex: 1 1 100%;
    width: 100%;
  }
  .favorites-page .section .head .start .tabs {
    margin-left: 0 !important;
  }
  .favorites-page #clearAllFavorites {
    align-self: flex-end;
  }
  /* 6-item nav: keep labels readable without feeling crushed */
  .bottom-nav-inner {
    grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
    max-width: 36rem !important;
    padding: 0.3rem 0.25rem !important;
  }
  .bottom-nav-item {
    min-width: 0 !important;
    padding: 0.28rem 0.1rem 0.22rem !important;
  }
  .bottom-nav-label {
    font-size: 0.56rem !important;
    letter-spacing: 0 !important;
  }
}
"""
    if marker in text:
        text = re.sub(
            r"/\* favorites-header-fix \*/[\s\S]*?(?=\n/\* [a-z]|\Z)",
            marker + "\n" + body + "\n",
            text,
            count=1,
        )
        print("css replaced")
    else:
        text = text.rstrip() + "\n\n" + marker + "\n" + body + "\n"
        print("css appended")
    css.write_text(text)


def bump() -> None:
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("asset", ASSET_V)


def main() -> None:
    for page in ("favorites", "search", "top-imdb"):
        patch_page(page)
    patch_favorites_head()
    patch_css()
    bump()
    print("deploy_favorites_header_fix done", ASSET_V)


if __name__ == "__main__":
    main()
