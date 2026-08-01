#!/usr/bin/env python3
"""Add Anime to main menu (bottom-nav pill + header)."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui52"


def patch_bottom_nav() -> None:
    path = ROOT / "app/Views/partials/bottom-nav.php"
    text = path.read_text()

    if "navAnime" not in text:
        text = text.replace(
            "$navTv = is_active('/tv-series') || (bool) preg_match('#^/tv(/|$)#', $path);\n?>",
            "$navTv = is_active('/tv-series') || (bool) preg_match('#^/tv(/|$)#', $path);\n"
            "$navAnime = is_active('/anime');\n?>",
            1,
        )
        print("navAnime var added")
    else:
        print("navAnime var exists")

    item = """        <a href="<?= e(url('/anime')) ?>" class="bottom-nav-item<?= $navAnime ? ' active' : '' ?>"<?= $navAnime ? ' aria-current="page"' : '' ?>>
            <span class="bottom-nav-icon"><i class="uil uil-star" aria-hidden="true"></i></span>
            <span class="bottom-nav-label">Anime</span>
        </a>
"""
    if "url('/anime')) ?>" in text and "bottom-nav-label\">Anime</span>" in text and "bottom-nav-item<?= $navAnime" in text:
        print("bottom-nav anime item exists")
    else:
        needle = """        <a href="<?= e(url('/tv-series')) ?>" class="bottom-nav-item<?= $navTv ? ' active' : '' ?>"<?= $navTv ? ' aria-current="page"' : '' ?>>
            <span class="bottom-nav-icon"><i class="uil uil-tv-retro" aria-hidden="true"></i></span>
            <span class="bottom-nav-label">TV</span>
        </a>
"""
        if needle not in text:
            raise SystemExit("TV bottom-nav item not found")
        text = text.replace(needle, needle + item, 1)
        print("bottom-nav anime item added")

    path.write_text(text)


def patch_header() -> None:
    path = ROOT / "app/Views/partials/header.php"
    text = path.read_text()
    if "url('/anime')" in text and ">Anime</a>" in text:
        print("header anime exists")
        return
    if "TV Shows</a></li>" not in text:
        raise SystemExit("TV Shows header link missing")
    lines = text.splitlines(keepends=True)
    out = []
    added = False
    for line in lines:
        out.append(line)
        if (not added) and "TV Shows</a></li>" in line and "url('/tv-series')" in line:
            indent = re.match(r"^(\s*)", line).group(1)
            out.append(
                f"{indent}<li><a href=\"<?= e(url('/anime')) ?>\" class=\"<?= is_active('/anime') ? 'active' : '' ?>\">Anime</a></li>\n"
            )
            added = True
    if not added:
        raise SystemExit("failed to insert header Anime link")
    path.write_text("".join(out))
    print("header anime added")


def patch_css() -> None:
    css = ROOT / "public/assets/css/app.css"
    text = css.read_text()
    marker = "/* anime-main-nav */"
    body = """@media (max-width: 991.98px) {
  .bottom-nav-inner {
    grid-template-columns: repeat(6, 1fr) !important;
    max-width: 34rem !important;
    gap: 0.05rem !important;
  }
  .bottom-nav-item {
    min-height: 3rem !important;
    padding: 0.3rem 0.15rem 0.25rem !important;
  }
  .bottom-nav-label {
    font-size: 0.58rem !important;
  }
  .bottom-nav-icon {
    width: 1.7rem !important;
    height: 1.7rem !important;
  }
  .bottom-nav-icon i {
    font-size: 1.12rem !important;
  }
}
@media (min-width: 992px) {
  .bottom-nav-item {
    min-width: 4.05rem !important;
    padding: 0.28rem 0.42rem 0.22rem !important;
  }
}
"""
    if marker in text:
        text = re.sub(
            r"/\* anime-main-nav \*/[\s\S]*?(?=\n/\* [a-z]|\Z)",
            marker + "\n" + body + "\n",
            text,
            count=1,
        )
        print("anime-main-nav css replaced")
    else:
        text = text.rstrip() + "\n\n" + marker + "\n" + body + "\n"
        print("anime-main-nav css appended")
    css.write_text(text)


def bump_assets() -> None:
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("asset version", ASSET_V)


def main() -> None:
    patch_bottom_nav()
    patch_header()
    patch_css()
    bump_assets()
    print("deploy_anime_main_nav done", ASSET_V)


if __name__ == "__main__":
    main()
